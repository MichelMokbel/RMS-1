<?php

namespace App\Services\PettyCash;

use App\Models\ApInvoice;
use App\Models\ExpenseCategory;
use App\Models\PettyCashWallet;
use App\Models\Supplier;
use App\Services\AP\SupplierAccountingPolicyService;
use DateTimeImmutable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PettyCashImportValidator
{
    /** @var array<int, Supplier|null> */
    private array $suppliers = [];

    /** @var array<int, ExpenseCategory|null> */
    private array $categories = [];

    /** @var array<int, PettyCashWallet|null> */
    private array $wallets = [];

    public function __construct(
        protected SupplierAccountingPolicyService $supplierPolicy,
        protected PettyCashImportRowPreparer $rowPreparer,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $sourceRows
     * @return array{rows:array<int,array<string,mixed>>,invoices:array<int,array<string,mixed>>,stats:array<string,int|float>}
     */
    public function validate(
        array $sourceRows,
        int $companyId,
        ?int $defaultCategoryId,
        ?int $defaultWalletId,
        string $businessDate,
    ): array {
        $maxRows = (int) config('petty_cash.imports.max_rows', 5000);
        if (count($sourceRows) > $maxRows) {
            throw ValidationException::withMessages([
                'workbook' => __('The workbook may contain at most :count data rows.', ['count' => $maxRows]),
            ]);
        }

        $this->suppliers = $this->categories = $this->wallets = [];
        $this->assertDefaultCategory($defaultCategoryId);
        $this->assertDefaultWallet($defaultWalletId);

        $rows = [];
        foreach ($this->rowPreparer->prepare($sourceRows) as $offset => $source) {
            $rows[] = $this->normalizeRow(
                $source,
                $offset + 2,
                $companyId,
                $defaultCategoryId,
                $defaultWalletId,
                $businessDate,
            );
        }

        $entryIds = collect($rows)
            ->pluck('payload.entry_id')
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
            ->uniqueStrict();
        $maxGroups = (int) config('petty_cash.imports.max_groups', 500);
        if ($entryIds->count() > $maxGroups) {
            throw ValidationException::withMessages([
                'workbook' => __('The workbook may contain at most :count distinct entry IDs.', ['count' => $maxGroups]),
            ]);
        }

        $invoices = [];
        $groupIndexes = [];
        foreach ($entryIds as $entryId) {
            $indexes = collect($rows)
                ->keys()
                ->filter(fn (int $index): bool => $rows[$index]['payload']['entry_id'] === $entryId)
                ->values()
                ->all();
            $groupIndexes[$entryId] = $indexes;
            $first = $rows[$indexes[0]]['payload'];
            $headerFields = [
                'supplier_id', 'reference_number', 'due_date', 'category_id',
                'wallet_id', 'paid', 'notes',
            ];
            $groupErrors = [];
            foreach ($headerFields as $field) {
                $values = collect($indexes)
                    ->map(fn (int $index): mixed => $rows[$index]['payload'][$field] ?? null)
                    ->uniqueStrict();
                if ($values->count() > 1) {
                    $message = __('All rows with entry ID :entry must use the same :field.', [
                        'entry' => $entryId,
                        'field' => Str::headline($field),
                    ]);
                    $groupErrors[$field] = $message;
                    foreach ($indexes as $index) {
                        $rows[$index]['errors'][$field] = $message;
                    }
                }
            }

            $rowErrors = collect($indexes)->sum(fn (int $index): int => count($rows[$index]['errors']));
            if ($rowErrors > 0) {
                $groupErrors['rows'] = __('One or more lines in this entry need correction.');
            }
            $invoiceTotal = round(
                collect($indexes)->sum(fn (int $index): float => round(
                    (float) ($rows[$index]['payload']['quantity'] ?? 0)
                        * (float) ($rows[$index]['payload']['unit_price'] ?? 0),
                    2
                )),
                2
            );
            if ($invoiceTotal <= 0) {
                $message = __('The grouped invoice total must be greater than zero.');
                $groupErrors['total_amount'] = $message;
                foreach ($indexes as $index) {
                    $rows[$index]['errors']['total_amount'] = $message;
                }
            }
            if (($first['supplier_id'] ?? null)
                && filled($first['reference_number'] ?? null)
                && Schema::hasColumn('ap_invoices', 'reference_number')
                && ApInvoice::query()
                    ->where('supplier_id', $first['supplier_id'])
                    ->whereDate('invoice_date', $businessDate)
                    ->where('reference_number', $first['reference_number'])
                    ->where('status', '!=', 'void')
                    ->exists()) {
                $message = __('This supplier reference already exists on the business date.');
                $groupErrors['reference_number'] = $message;
                foreach ($indexes as $index) {
                    $rows[$index]['errors']['reference_number'] = $message;
                }
            }
            $header = collect($first)->only($headerFields)->all();
            $header['tax_amount'] = 0.0;
            $header['subtotal'] = $invoiceTotal;
            $header['total_amount'] = $invoiceTotal;
            $invoices[] = [
                'entry_id' => $entryId,
                'group_key' => hash('sha256', $entryId),
                'header' => $header,
                'errors' => $groupErrors,
                'status' => $groupErrors === [] ? 'valid' : 'invalid',
                'client_uuid' => (string) Str::uuid(),
            ];
        }

        collect($invoices)
            ->filter(fn (array $invoice): bool => (int) ($invoice['header']['supplier_id'] ?? 0) > 0
                && filled($invoice['header']['reference_number'] ?? null))
            ->groupBy(fn (array $invoice): string => (int) $invoice['header']['supplier_id'].'|'.mb_strtolower(trim((string) $invoice['header']['reference_number'])))
            ->filter(fn ($matches): bool => $matches->count() > 1)
            ->each(function ($matches) use (&$invoices, &$rows, $groupIndexes): void {
                foreach ($matches as $match) {
                    $message = __('Supplier reference is duplicated across multiple entry IDs in this workbook.');
                    foreach ($invoices as &$invoice) {
                        if ($invoice['entry_id'] === $match['entry_id']) {
                            $invoice['errors']['reference_number'] = $message;
                            break;
                        }
                    }
                    unset($invoice);
                    foreach ($groupIndexes[$match['entry_id']] ?? [] as $index) {
                        $rows[$index]['errors']['reference_number'] = $message;
                    }
                }
            });

        $remainingByWallet = [];
        foreach ($invoices as &$invoice) {
            if (($invoice['errors'] ?? []) !== [] || ! (bool) ($invoice['header']['paid'] ?? false)) {
                continue;
            }
            $walletId = (int) ($invoice['header']['wallet_id'] ?? 0);
            $wallet = $walletId > 0
                ? ($this->wallets[$walletId] ??= PettyCashWallet::query()->find($walletId))
                : null;
            if (! $wallet || ! $wallet->isActive()) {
                continue;
            }
            $remainingByWallet[$walletId] ??= round((float) $wallet->balance, 2);
            $total = round((float) ($invoice['header']['total_amount'] ?? 0), 2);
            if (round($remainingByWallet[$walletId] - $total, 2) >= 0) {
                $remainingByWallet[$walletId] = round($remainingByWallet[$walletId] - $total, 2);

                continue;
            }

            $message = __('The wallet balance is insufficient, so this invoice will be imported as unpaid.');
            $invoice['header']['paid_requested'] = true;
            $invoice['header']['paid'] = false;
            $invoice['header']['settlement_warning'] = $message;
            foreach ($groupIndexes[$invoice['entry_id']] ?? [] as $index) {
                $rows[$index]['payload']['paid_requested'] = true;
                $rows[$index]['payload']['paid'] = false;
                $rows[$index]['payload']['settlement_warning'] = $message;
            }
        }
        unset($invoice);

        foreach ($rows as &$row) {
            $row['status'] = $row['errors'] === [] ? 'valid' : 'invalid';
        }
        unset($row);
        foreach ($invoices as &$invoice) {
            if (collect($groupIndexes[$invoice['entry_id']] ?? [])->contains(
                fn (int $index): bool => $rows[$index]['status'] === 'invalid'
            )) {
                $invoice['errors']['rows'] ??= __('One or more lines in this entry need correction.');
            }
            $invoice['status'] = $invoice['errors'] === [] ? 'valid' : 'invalid';
        }
        unset($invoice);

        $invalidRows = count(array_filter($rows, fn (array $row): bool => $row['status'] === 'invalid'));
        $invalidInvoices = count(array_filter($invoices, fn (array $invoice): bool => $invoice['status'] === 'invalid'));

        return [
            'rows' => $rows,
            'invoices' => $invoices,
            'stats' => [
                'rows' => count($rows),
                'valid_rows' => count($rows) - $invalidRows,
                'invalid_rows' => $invalidRows,
                'invoices' => count($invoices),
                'valid_invoices' => count($invoices) - $invalidInvoices,
                'invalid_invoices' => $invalidInvoices,
                'paid_invoices' => count(array_filter($invoices, fn (array $invoice): bool => (bool) ($invoice['header']['paid'] ?? false))),
                'unpaid_for_insufficient_balance' => count(array_filter(
                    $invoices,
                    fn (array $invoice): bool => isset($invoice['header']['settlement_warning'])
                )),
                'subtotal' => round(array_sum(array_map(fn (array $invoice): float => (float) ($invoice['header']['subtotal'] ?? 0), $invoices)), 2),
                'total_amount' => round(array_sum(array_map(fn (array $invoice): float => (float) ($invoice['header']['total_amount'] ?? 0), $invoices)), 2),
            ],
        ];
    }

    /** @return array{payload:array<string,mixed>,errors:array<string,string>,row_number:int,status:string} */
    private function normalizeRow(
        array $source,
        int $rowNumber,
        int $companyId,
        ?int $defaultCategoryId,
        ?int $defaultWalletId,
        string $businessDate,
    ): array {
        $errors = [];
        $entryId = Str::upper(trim((string) ($source['entry_id'] ?? '')));
        if ($entryId === '' || mb_strlen($entryId) > 191) {
            $errors['entry_id'] = __('Entry ID is required and may not exceed 191 characters.');
        }

        $supplierId = $this->resolveSupplier($source['supplier'] ?? null, $companyId, $errors);
        $categoryId = $this->resolveCategory($source['category'] ?? null, $defaultCategoryId, $errors);
        $walletId = $this->resolveWallet($source['wallet'] ?? null, $defaultWalletId, $errors);

        $reference = $this->optionalText($source['reference_number'] ?? null, 100, 'reference_number', $errors);
        $notes = $this->optionalText($source['notes'] ?? null, 5000, 'notes', $errors);
        $description = trim((string) ($source['description'] ?? ''));
        if ($description === '' || mb_strlen($description) > 255) {
            $errors['description'] = __('Description is required and may not exceed 255 characters.');
        }

        $dueDate = blank($source['due_date'] ?? null) ? $businessDate : $this->date($source['due_date']);
        if ($dueDate === null) {
            $errors['due_date'] = __('Due date must use YYYY-MM-DD.');
        } elseif ($dueDate < $businessDate) {
            $errors['due_date'] = __('Due date may not be before the business date.');
        }

        $paid = $this->boolean($source['paid'] ?? null);
        if ($paid === null) {
            $errors['paid'] = __('Paid must be TRUE or FALSE.');
        }

        $quantity = blank($source['quantity'] ?? null)
            ? 1.0
            : $this->decimal($source['quantity'], 3, false);
        if ($quantity === null || $quantity < 0.001) {
            $errors['quantity'] = __('Quantity must be at least 0.001 with at most three decimal places.');
        }
        $unitPrice = $this->decimal($source['unit_price'] ?? null, 4, true);
        if ($unitPrice === null) {
            $errors['unit_price'] = __('Unit price must be non-negative with at most four decimal places.');
        }
        $payload = [
            'entry_id' => $entryId,
            'supplier' => is_scalar($source['supplier'] ?? null) ? trim((string) $source['supplier']) : null,
            'supplier_id' => $supplierId,
            'reference_number' => $reference,
            'due_date' => $dueDate,
            'category' => is_scalar($source['category'] ?? null) ? trim((string) $source['category']) : null,
            'category_id' => $categoryId,
            'wallet' => is_scalar($source['wallet'] ?? null) ? trim((string) $source['wallet']) : null,
            'wallet_id' => $walletId,
            'paid' => $paid,
            'description' => $description,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'notes' => $notes,
            '_sheet_row' => $rowNumber,
        ];

        return ['payload' => $payload, 'errors' => $errors, 'row_number' => $rowNumber, 'status' => 'valid'];
    }

    private function resolveSupplier(mixed $token, int $companyId, array &$errors): ?int
    {
        $id = $this->tokenId($token);
        $supplier = $id ? ($this->suppliers[$id] ??= Supplier::query()->find($id)) : null;
        if (! $supplier || ($supplier->company_id && (int) $supplier->company_id !== $companyId)) {
            $errors['supplier'] = __('Supplier must use a valid company supplier token.');

            return null;
        }
        if (! $supplier->isActive()) {
            $errors['supplier'] = __('Supplier is inactive.');
        } elseif ($this->supplierPolicy->blocksPosting($supplier)) {
            $errors['supplier'] = $this->supplierPolicy->postingBlockedMessage($supplier);
        }

        return (int) $supplier->id;
    }

    private function resolveCategory(mixed $token, ?int $defaultId, array &$errors): ?int
    {
        $id = filled($token) ? $this->tokenId($token) : $defaultId;
        $category = $id ? ($this->categories[$id] ??= ExpenseCategory::query()->find($id)) : null;
        if (! $category || ! $category->active) {
            $errors['category'] = __('Category must use an active category token or a valid default.');

            return null;
        }

        return (int) $category->id;
    }

    private function resolveWallet(mixed $token, ?int $defaultId, array &$errors): ?int
    {
        $id = filled($token) ? $this->tokenId($token) : $defaultId;
        $wallet = $id ? ($this->wallets[$id] ??= PettyCashWallet::query()->find($id)) : null;
        if (! $wallet || ! $wallet->isActive()) {
            $errors['wallet'] = __('Wallet must use an active wallet token or a valid default.');

            return null;
        }

        return (int) $wallet->id;
    }

    private function assertDefaultCategory(?int $id): void
    {
        if ($id !== null && ! ExpenseCategory::query()->whereKey($id)->where('active', true)->exists()) {
            throw ValidationException::withMessages(['default_category_id' => __('The default category is inactive or missing.')]);
        }
    }

    private function assertDefaultWallet(?int $id): void
    {
        if ($id !== null && ! PettyCashWallet::query()->whereKey($id)->where('active', true)->exists()) {
            throw ValidationException::withMessages(['default_wallet_id' => __('The default wallet is inactive or missing.')]);
        }
    }

    private function tokenId(mixed $value): ?int
    {
        if (! is_scalar($value) || ! preg_match('/^\s*([1-9][0-9]*)\s*(?:\||$)/', (string) $value, $match)) {
            return null;
        }

        return (int) $match[1];
    }

    private function boolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            'true', 'yes', '1' => true,
            'false', 'no', '0' => false,
            default => null,
        };
    }

    private function date(mixed $value): ?string
    {
        $raw = is_scalar($value) ? trim((string) $value) : '';
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);

        return $date && $date->format('Y-m-d') === $raw ? $raw : null;
    }

    private function decimal(mixed $value, int $scale, bool $allowZero): ?float
    {
        $raw = is_int($value) || is_float($value) ? (string) $value : trim((string) $value);
        if ($raw === '' || ! preg_match('/^\d+(?:\.\d{1,'.$scale.'})?$/', $raw)) {
            return null;
        }
        $number = round((float) $raw, $scale);
        if ($number < 0 || (! $allowZero && $number <= 0)) {
            return null;
        }

        return $number;
    }

    private function optionalText(mixed $value, int $max, string $field, array &$errors): ?string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > $max) {
            $errors[$field] = __(':Field may not exceed :max characters.', [
                'field' => Str::headline($field),
                'max' => $max,
            ]);
        }

        return $text;
    }
}
