<?php

namespace App\Services\PettyCash;

use App\Models\ApInvoice;
use App\Models\ExpenseCategory;
use App\Models\PettyCashWallet;
use App\Models\Supplier;
use App\Services\AP\SupplierAccountingPolicyService;
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

    /** @var array<string, array<int, ExpenseCategory>> */
    private array $categoriesByName = [];

    public function __construct(
        protected SupplierAccountingPolicyService $supplierPolicy,
        protected PettyCashImportRowPreparer $rowPreparer,
        protected PettyCashImportValueParser $values,
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
        ?int $defaultSupplierId = null,
        ?bool $defaultPaid = null,
        string $importMode = 'daily',
        string $fundingSource = 'petty_cash',
    ): array {
        $maxRows = (int) config('petty_cash.imports.max_rows', 5000);
        if (count($sourceRows) > $maxRows) {
            throw ValidationException::withMessages([
                'workbook' => __('The workbook may contain at most :count data rows.', ['count' => $maxRows]),
            ]);
        }

        $this->suppliers = $this->categories = $this->wallets = [];
        $this->categoriesByName = ExpenseCategory::query()->get()->groupBy(
            fn (ExpenseCategory $category): string => $this->normalizeCategoryName($category->name)
        )->all();
        $this->assertDefaultCategory($defaultCategoryId);
        if ($fundingSource === 'petty_cash') {
            $this->assertDefaultWallet($defaultWalletId);
        }
        $this->assertDefaultSupplier($defaultSupplierId, $companyId);

        $rows = [];
        foreach ($this->rowPreparer->prepare($sourceRows, $importMode === 'bulk') as $offset => $source) {
            $rows[] = $this->normalizeRow(
                $source,
                $offset + 2,
                $companyId,
                $defaultCategoryId,
                $defaultWalletId,
                $businessDate,
                $defaultSupplierId,
                $defaultPaid,
                $importMode,
                $fundingSource,
            );
        }

        $groupIds = collect($rows)
            ->map(fn (array $row): string => $this->groupIdentifier($row['payload']))
            ->filter()
            ->uniqueStrict();
        $maxGroups = (int) config('petty_cash.imports.max_groups', 500);
        if ($groupIds->count() > $maxGroups) {
            throw ValidationException::withMessages([
                'workbook' => __('The workbook may contain at most :count distinct entry IDs.', ['count' => $maxGroups]),
            ]);
        }

        $invoices = [];
        $groupIndexes = [];
        foreach ($groupIds as $groupId) {
            $indexes = collect($rows)
                ->keys()
                ->filter(fn (int $index): bool => $this->groupIdentifier($rows[$index]['payload']) === $groupId)
                ->values()
                ->all();
            $groupIndexes[$groupId] = $indexes;
            $first = $rows[$indexes[0]]['payload'];
            $entryId = $first['entry_id'];
            $invoiceDate = $first['business_date'];
            $consistentHeaderFields = [
                'supplier_id', 'reference_number', 'due_date', 'category_id',
                'category_name', 'category_normalized', 'wallet_id', 'paid',
            ];
            $groupErrors = [];
            foreach ($consistentHeaderFields as $field) {
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
                    ->whereDate('invoice_date', $invoiceDate)
                    ->where('reference_number', $first['reference_number'])
                    ->where('status', '!=', 'void')
                    ->exists()) {
                $message = __('This supplier reference already exists on the business date.');
                $groupErrors['reference_number'] = $message;
                foreach ($indexes as $index) {
                    $rows[$index]['errors']['reference_number'] = $message;
                }
            }
            $header = collect($first)->only([...$consistentHeaderFields, 'notes'])->all();
            $header['business_date'] = $invoiceDate;
            $header['tax_amount'] = 0.0;
            $header['subtotal'] = $invoiceTotal;
            $header['total_amount'] = $invoiceTotal;
            $invoices[] = [
                'entry_id' => $entryId,
                'business_date' => $invoiceDate,
                'group_key' => hash('sha256', $groupId),
                'header' => $header,
                'errors' => $groupErrors,
                'status' => $groupErrors === [] ? 'valid' : 'invalid',
                'client_uuid' => (string) Str::uuid(),
            ];
        }

        collect($invoices)
            ->filter(fn (array $invoice): bool => (int) ($invoice['header']['supplier_id'] ?? 0) > 0
                && filled($invoice['header']['reference_number'] ?? null))
            ->groupBy(fn (array $invoice): string => (int) $invoice['header']['supplier_id'].'|'.$invoice['business_date'].'|'.mb_strtolower(trim((string) $invoice['header']['reference_number'])))
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
                    foreach ($groupIndexes[$match['business_date'].'|'.$match['entry_id']] ?? [] as $index) {
                        $rows[$index]['errors']['reference_number'] = $message;
                    }
                }
            });

        if ($fundingSource === 'petty_cash') {
            $remainingByWallet = [];
            usort($invoices, fn (array $left, array $right): int => [
                $left['business_date'], min($groupIndexes[$left['business_date'].'|'.$left['entry_id']] ?? [PHP_INT_MAX]),
            ] <=> [
                $right['business_date'], min($groupIndexes[$right['business_date'].'|'.$right['entry_id']] ?? [PHP_INT_MAX]),
            ]);
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
                foreach ($groupIndexes[$invoice['business_date'].'|'.$invoice['entry_id']] ?? [] as $index) {
                    $rows[$index]['payload']['paid_requested'] = true;
                    $rows[$index]['payload']['paid'] = false;
                    $rows[$index]['payload']['settlement_warning'] = $message;
                }
            }
            unset($invoice);
        }

        foreach ($rows as &$row) {
            $row['status'] = $row['errors'] === [] ? 'valid' : 'invalid';
        }
        unset($row);
        foreach ($invoices as &$invoice) {
            if (collect($groupIndexes[$invoice['business_date'].'|'.$invoice['entry_id']] ?? [])->contains(
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
                'date_from' => collect($invoices)->pluck('business_date')
                    ->filter(fn (mixed $date): bool => $this->values->date($date) !== null)->min(),
                'date_to' => collect($invoices)->pluck('business_date')
                    ->filter(fn (mixed $date): bool => $this->values->date($date) !== null)->max(),
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
        ?int $defaultSupplierId,
        ?bool $defaultPaid,
        string $importMode,
        string $fundingSource,
    ): array {
        $errors = [];
        $entryId = Str::upper(trim((string) ($source['entry_id'] ?? '')));
        if ($entryId === '' || mb_strlen($entryId) > 191) {
            $errors['entry_id'] = __('Entry ID is required and may not exceed 191 characters.');
        }

        $rowBusinessDate = $importMode === 'bulk'
            ? $this->values->date($source['business_date'] ?? null)
            : $businessDate;
        if ($rowBusinessDate === null) {
            $errors['business_date'] = __('Business date must use YYYY-MM-DD.');
            $rowBusinessDate = is_scalar($source['business_date'] ?? null)
                ? trim((string) $source['business_date'])
                : '';
        }

        $supplierId = $this->resolveSupplier($source['supplier'] ?? null, $defaultSupplierId, $companyId, $errors);
        $categoryName = null;
        $categoryNormalized = null;
        $categoryId = $this->resolveCategory(
            $source['category'] ?? null,
            $defaultCategoryId,
            $errors,
            $categoryName,
            $categoryNormalized,
        );
        $walletId = $fundingSource === 'petty_cash'
            ? $this->resolveWallet($source['wallet'] ?? null, $defaultWalletId, $errors)
            : null;

        $reference = $this->values->optionalText($source['reference_number'] ?? null, 100, 'reference_number', $errors);
        $notes = $this->values->optionalText($source['notes'] ?? null, 5000, 'notes', $errors);
        $description = trim((string) ($source['description'] ?? ''));
        if ($description === '' || mb_strlen($description) > 255) {
            $errors['description'] = __('Description is required and may not exceed 255 characters.');
        }

        $dueDate = blank($source['due_date'] ?? null) ? $rowBusinessDate : $this->values->date($source['due_date']);
        if ($dueDate === null) {
            $errors['due_date'] = __('Due date must use YYYY-MM-DD.');
        } elseif ($rowBusinessDate !== '' && $dueDate < $rowBusinessDate) {
            $errors['due_date'] = __('Due date may not be before the business date.');
        }

        $paid = blank($source['paid'] ?? null) && $defaultPaid !== null
            ? $defaultPaid
            : $this->values->boolean($source['paid'] ?? null);
        if ($paid === null) {
            $errors['paid'] = __('Paid must be TRUE or FALSE.');
        }

        $quantity = blank($source['quantity'] ?? null)
            ? 1.0
            : $this->values->decimal($source['quantity'], 3, false);
        if ($quantity === null || $quantity < 0.001) {
            $errors['quantity'] = __('Quantity must be at least 0.001 with at most three decimal places.');
        }
        $unitPrice = $this->values->decimal($source['unit_price'] ?? null, 4, true);
        if ($unitPrice === null) {
            $errors['unit_price'] = __('Unit price must be non-negative with at most four decimal places.');
        }
        $payload = [
            'entry_id' => $entryId,
            'business_date' => $rowBusinessDate,
            'supplier' => is_scalar($source['supplier'] ?? null) ? trim((string) $source['supplier']) : null,
            'supplier_id' => $supplierId,
            'reference_number' => $reference,
            'due_date' => $dueDate,
            'category' => is_scalar($source['category'] ?? null) ? trim((string) $source['category']) : null,
            'category_id' => $categoryId,
            'category_name' => $categoryName,
            'category_normalized' => $categoryNormalized,
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

    private function resolveSupplier(mixed $token, ?int $defaultId, int $companyId, array &$errors): ?int
    {
        $id = filled($token) ? $this->values->tokenId($token) : $defaultId;
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

    private function resolveCategory(
        mixed $token,
        ?int $defaultId,
        array &$errors,
        ?string &$categoryName,
        ?string &$normalizedName,
    ): ?int {
        $raw = is_scalar($token) ? trim((string) $token) : '';
        $id = $raw !== '' ? $this->values->tokenId($raw) : $defaultId;
        if ($id) {
            $category = $this->categories[$id] ??= ExpenseCategory::query()->find($id);
            if (! $category || ! $category->active) {
                $errors['category'] = __('Category must use an active category token or a valid default.');

                return null;
            }
            $categoryName = $category->name;
            $normalizedName = $this->normalizeCategoryName($category->name);

            return (int) $category->id;
        }

        if ($raw === '' || mb_strlen($raw) > 100) {
            $errors['category'] = __('Category is required and may not exceed 100 characters.');

            return null;
        }

        $categoryName = preg_replace('/\s+/u', ' ', $raw) ?: $raw;
        $normalizedName = $this->normalizeCategoryName($categoryName);
        $matches = $this->categoriesByName[$normalizedName] ?? [];
        if (count($matches) > 1) {
            $errors['category'] = __('Category name is ambiguous and must be selected by ID.');

            return null;
        }
        if ($matches === []) {
            return null;
        }
        $category = $matches[0];
        if (! $category->active) {
            $errors['category'] = __('An inactive category already uses this name. Review it before importing.');

            return null;
        }

        return (int) $category->id;
    }

    private function resolveWallet(mixed $token, ?int $defaultId, array &$errors): ?int
    {
        $id = filled($token) ? $this->values->tokenId($token) : $defaultId;
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

    private function assertDefaultSupplier(?int $id, int $companyId): void
    {
        if ($id === null) {
            return;
        }
        $errors = [];
        $this->resolveSupplier(null, $id, $companyId, $errors);
        if ($errors !== []) {
            throw ValidationException::withMessages(['default_supplier_id' => reset($errors)]);
        }
    }

    /** @param array<string, mixed> $payload */
    private function groupIdentifier(array $payload): string
    {
        $entryId = (string) ($payload['entry_id'] ?? '');
        $date = (string) ($payload['business_date'] ?? '');

        return $entryId === '' ? '' : $date.'|'.$entryId;
    }

    private function normalizeCategoryName(string $name): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($name)) ?: trim($name);

        return mb_strtolower($collapsed);
    }
}
