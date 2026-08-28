<?php

namespace App\Services\PettyCash;

use App\Models\AccountingCompany;
use App\Models\BankAccount;
use App\Models\PettyCashImportBatch;
use App\Models\PettyCashImportInvoice;
use App\Models\PettyCashImportRow;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\Accounting\AccountingContextService;
use App\Services\Accounting\AccountingPeriodGateService;
use App\Services\Accounting\LedgerAccountMappingService;
use App\Support\Imports\SafeSpreadsheetReader;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class PettyCashImportService
{
    public const DATA_SHEET = 'petty_cash_expenses';

    public const HEADERS = [
        'entry_id', 'supplier', 'reference_number', 'due_date', 'category', 'wallet',
        'paid', 'description', 'quantity', 'unit_price', 'notes',
    ];

    public const BULK_HEADERS = [
        'business_date', ...self::HEADERS,
    ];

    public const CATEGORY_DEFINITION_SHEET = 'category_definitions';

    public const CATEGORY_DEFINITION_HEADERS = ['code', 'name'];

    private const PARSER_VERSION = '3';

    private const ALLOWED_SHEETS = [
        self::DATA_SHEET, 'instructions', 'suppliers', 'categories', 'wallets',
        self::CATEGORY_DEFINITION_SHEET,
    ];

    public function __construct(
        protected SafeSpreadsheetReader $reader,
        protected PettyCashImportValidator $validator,
        protected AccountingContextService $accountingContext,
        protected AccountingPeriodGateService $periodGate,
        protected LedgerAccountMappingService $mappings,
        protected PettyCashImportCommitter $committer,
        protected AccountingAuditLogService $audit,
        protected PettyCashImportCategoryProposalService $categoryProposals,
    ) {}

    public function stage(
        UploadedFile $workbook,
        string $businessDate,
        ?int $defaultCategoryId,
        ?int $defaultWalletId,
        int $companyId,
        User $actor,
        string $fundingSource = 'petty_cash',
        ?int $defaultBankAccountId = null,
    ): PettyCashImportBatch {
        $businessDate = $this->businessDate($businessDate);

        return $this->stageWorkbook(
            $workbook,
            'daily',
            $businessDate,
            null,
            $defaultCategoryId,
            $defaultWalletId,
            null,
            $companyId,
            $actor,
            $fundingSource,
            $defaultBankAccountId,
        );
    }

    public function stageBulk(
        UploadedFile $workbook,
        ?int $defaultSupplierId,
        ?int $defaultCategoryId,
        ?int $defaultWalletId,
        bool $defaultPaid,
        int $companyId,
        User $actor,
        string $fundingSource = 'petty_cash',
        ?int $defaultBankAccountId = null,
    ): PettyCashImportBatch {
        return $this->stageWorkbook(
            $workbook,
            'bulk',
            null,
            $defaultSupplierId,
            $defaultCategoryId,
            $defaultWalletId,
            $defaultPaid,
            $companyId,
            $actor,
            $fundingSource,
            $defaultBankAccountId,
        );
    }

    private function stageWorkbook(
        UploadedFile $workbook,
        string $importMode,
        ?string $businessDate,
        ?int $defaultSupplierId,
        ?int $defaultCategoryId,
        ?int $defaultWalletId,
        ?bool $defaultPaid,
        int $companyId,
        User $actor,
        string $fundingSource,
        ?int $defaultBankAccountId,
    ): PettyCashImportBatch {
        $this->assertAccess($actor);
        $company = AccountingCompany::query()->findOrFail($companyId);
        if (! $company->is_active) {
            throw ValidationException::withMessages([
                'company_id' => __('The selected accounting company is inactive.'),
            ]);
        }
        $fundingSource = $this->fundingSource($fundingSource);
        $defaultBankAccountId = $fundingSource === 'bank_account'
            ? $this->resolveBankAccountId($defaultBankAccountId, $companyId, (string) $company->base_currency)
            : null;
        if ($fundingSource === 'bank_account') {
            $defaultWalletId = null;
        }
        $this->assertUpload($workbook);

        $sha256 = hash_file('sha256', $workbook->getRealPath());
        if (! is_string($sha256) || $sha256 === '') {
            throw ValidationException::withMessages(['workbook' => __('The workbook checksum could not be calculated.')]);
        }
        $idempotencyKey = hash('sha256', json_encode([
            'company_id' => $companyId,
            'import_mode' => $importMode,
            'business_date' => $businessDate,
            'sha256' => $sha256,
            'default_category_id' => $defaultCategoryId,
            'default_supplier_id' => $defaultSupplierId,
            'default_wallet_id' => $defaultWalletId,
            'default_paid' => $defaultPaid,
            'funding_source' => $fundingSource,
            'default_bank_account_id' => $defaultBankAccountId,
            'parser_version' => self::PARSER_VERSION,
        ], JSON_THROW_ON_ERROR));
        $existing = $this->existingBatch($companyId, $idempotencyKey);
        if ($existing) {
            return $existing;
        }

        try {
            $parsed = $this->reader->workbook($workbook->getRealPath());
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'workbook' => __('The workbook could not be read safely: :reason', [
                    'reason' => $exception->getMessage(),
                ]),
            ]);
        }
        $this->assertWorkbookShape($parsed['sheets'], $parsed['headers'], $importMode);
        $categoryDefinitions = $this->categoryProposals->definitions($parsed['sheets'], $parsed['headers']);
        $sourceRows = $parsed['sheets'][self::DATA_SHEET];
        if ($sourceRows === []) {
            throw ValidationException::withMessages(['workbook' => __('The workbook must contain at least one expense line.')]);
        }
        $validated = $this->validator->validate(
            $sourceRows,
            $companyId,
            $defaultCategoryId,
            $defaultWalletId,
            $businessDate ?? '',
            $defaultSupplierId,
            $defaultPaid,
            $importMode,
            $fundingSource,
        );
        if ((int) $validated['stats']['rows'] === 0) {
            throw ValidationException::withMessages(['workbook' => __('The workbook must contain at least one expense line.')]);
        }
        if ($importMode === 'bulk') {
            $this->applyBulkAccountingErrors($validated, $companyId, $fundingSource);
        } else {
            $this->assertAccountingPreflight($validated, $companyId, $fundingSource);
        }
        $dateFrom = $validated['stats']['date_from'] ?: null;
        $dateTo = $validated['stats']['date_to'] ?: null;
        if ($importMode === 'daily') {
            foreach (collect($validated['invoices'])
                ->filter(fn (array $invoice): bool => ($invoice['errors'] ?? []) === [])
                ->pluck('business_date')->filter()->unique() as $date) {
                $periodId = $this->accountingContext->resolvePeriodId($date, $companyId);
                $this->periodGate->assertDateOpen($date, $companyId, $periodId, 'ap', 'business_date');
            }
        }
        $conflictingDeclaredCategories = $this->categoryProposals->conflictCount($categoryDefinitions);

        $disk = (string) config('petty_cash.imports.disk', config('filesystems.default', 'local'));
        $root = "petty-cash/{$companyId}/imports";
        $objectKey = $root.'/'.Str::uuid().'.xlsx';
        $stored = Storage::disk($disk)->putFileAs(
            $root,
            $workbook,
            basename($objectKey),
            ['visibility' => 'private']
        );
        if ($stored !== $objectKey) {
            throw ValidationException::withMessages(['workbook' => __('The workbook could not be stored securely.')]);
        }

        try {
            return DB::transaction(function () use (
                $workbook,
                $businessDate,
                $importMode,
                $dateFrom,
                $dateTo,
                $defaultSupplierId,
                $defaultCategoryId,
                $defaultWalletId,
                $defaultPaid,
                $fundingSource,
                $defaultBankAccountId,
                $companyId,
                $actor,
                $sha256,
                $idempotencyKey,
                $disk,
                $objectKey,
                $validated,
                $categoryDefinitions,
                $conflictingDeclaredCategories,
            ): PettyCashImportBatch {
                $invalid = (int) $validated['stats']['invalid_rows'] + (int) $validated['stats']['invalid_invoices'];
                $invalid += $conflictingDeclaredCategories;
                $validated['stats']['invalid_categories'] = $conflictingDeclaredCategories;
                $batch = PettyCashImportBatch::query()->create([
                    'company_id' => $companyId,
                    'import_mode' => $importMode,
                    'business_date' => $businessDate ?? $dateFrom ?? now()->toDateString(),
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'default_category_id' => $defaultCategoryId,
                    'default_supplier_id' => $defaultSupplierId,
                    'default_wallet_id' => $defaultWalletId,
                    'default_paid' => $defaultPaid,
                    'funding_source' => $fundingSource,
                    'default_bank_account_id' => $defaultBankAccountId,
                    'status' => $invalid === 0 ? 'ready' : ($importMode === 'bulk' ? 'needs_review' : 'failed'),
                    'revision' => 0,
                    'parser_version' => self::PARSER_VERSION,
                    'source_name' => Str::limit(basename($workbook->getClientOriginalName()), 255, ''),
                    'storage_disk' => $disk,
                    'object_key' => $objectKey,
                    'sha256' => $sha256,
                    'idempotency_key' => $idempotencyKey,
                    'stats' => $validated['stats'],
                    'initiated_by' => $actor->id,
                    'initiated_at' => now(),
                    'failed_at' => null,
                    'failure_reason' => null,
                ]);

                $invoiceIds = [];
                foreach ($validated['invoices'] as $entry) {
                    $stagedInvoice = PettyCashImportInvoice::query()->create([
                        'import_batch_id' => $batch->id,
                        'entry_id' => $entry['entry_id'],
                        'business_date' => $this->nullableBusinessDate($entry['business_date']),
                        'group_key' => $entry['group_key'],
                        'status' => $entry['status'],
                        'header' => $entry['header'],
                        'original_header' => $entry['header'],
                        'errors' => $entry['errors'],
                        'client_uuid' => $entry['client_uuid'],
                    ]);
                    $invoiceIds[$entry['business_date'].'|'.$entry['entry_id']] = (int) $stagedInvoice->id;
                }

                foreach ($validated['rows'] as $entry) {
                    $payload = $entry['payload'];
                    PettyCashImportRow::query()->create([
                        'import_batch_id' => $batch->id,
                        'import_invoice_id' => $invoiceIds[$payload['business_date'].'|'.$payload['entry_id']] ?? null,
                        'row_number' => $entry['row_number'],
                        'source_identifier' => Str::limit((string) ($payload['entry_id'] ?? ''), 191, ''),
                        'status' => $entry['status'],
                        'payload' => $payload,
                        'original_payload' => $payload,
                        'errors' => $entry['errors'],
                        'row_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
                    ]);
                }

                $this->categoryProposals->persist($batch, $validated, $categoryDefinitions);
                if ($importMode === 'bulk') {
                    $this->categoryProposals->sync($batch, $validated);
                    $validated['stats']['invalid_categories'] = $batch->categoryProposals()->whereIn('status', ['inactive', 'ambiguous', 'mapped_inactive'])->count();
                    $invalid = (int) $validated['stats']['invalid_rows'] + (int) $validated['stats']['invalid_invoices']
                        + $validated['stats']['invalid_categories'];
                    $batch->forceFill([
                        'stats' => $validated['stats'],
                        'status' => $invalid === 0 ? 'ready' : 'needs_review',
                    ])->save();
                }

                $this->audit->log(
                    $invalid === 0
                        ? 'petty_cash_import.staged'
                        : ($importMode === 'bulk' ? 'petty_cash_import.needs_review' : 'petty_cash_import.validation_failed'),
                    (int) $actor->id,
                    $batch,
                    [
                        'import_mode' => $importMode,
                        'funding_source' => $fundingSource,
                        'default_bank_account_id' => $defaultBankAccountId,
                        'date_from' => $dateFrom,
                        'date_to' => $dateTo,
                        'sha256' => $sha256,
                        'rows' => $validated['stats']['rows'],
                        'invoices' => $validated['stats']['invoices'],
                        'unpaid_for_insufficient_balance' => $validated['stats']['unpaid_for_insufficient_balance'],
                        'invalid_rows' => $validated['stats']['invalid_rows'],
                        'invalid_invoices' => $validated['stats']['invalid_invoices'],
                    ],
                    $companyId
                );

                return $batch->fresh(['invoices.rows', 'rows', 'categoryProposals']);
            });
        } catch (QueryException $exception) {
            Storage::disk($disk)->delete($objectKey);
            $existing = $this->existingBatch($companyId, $idempotencyKey);
            if ($existing) {
                return $existing;
            }

            throw $exception;
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($objectKey);
            throw $exception;
        }
    }

    public function commit(PettyCashImportBatch $batch, User $actor): PettyCashImportBatch
    {
        $this->assertAccess($actor);

        return $this->committer->commit($batch, $actor);
    }

    /** @param array{invoices:array<int,array<string,mixed>>} $validated */
    private function assertAccountingPreflight(array $validated, int $companyId, string $fundingSource): void
    {
        $invoices = collect($validated['invoices'])
            ->filter(fn (array $invoice): bool => ($invoice['errors'] ?? []) === []);
        if ($invoices->isEmpty()) {
            return;
        }

        $required = ['ap_control'];
        if ($fundingSource === 'petty_cash'
            && $invoices->contains(fn (array $invoice): bool => (bool) ($invoice['header']['paid'] ?? false))) {
            $required[] = 'petty_cash_asset';
        }
        $supplierIds = $invoices->pluck('header.supplier_id')->filter()->map(fn ($id): int => (int) $id)->unique();
        if (Supplier::query()->whereIn('id', $supplierIds)->whereNull('default_expense_account_id')->exists()) {
            $required[] = 'expense_default';
        }

        $this->mappings->assertRequiredMappings($companyId, array_values(array_unique($required)));
    }

    private function applyBulkAccountingErrors(array &$validated, int $companyId, string $fundingSource): void
    {
        $mappingMessage = null;
        try {
            $this->assertAccountingPreflight($validated, $companyId, $fundingSource);
        } catch (ValidationException $exception) {
            $mappingMessage = collect($exception->errors())->flatten()->first()
                ?? __('Required accounting mappings are missing.');
        }

        foreach ($validated['invoices'] as &$invoice) {
            if ($mappingMessage !== null) {
                $invoice['errors']['accounting_mapping'] = $mappingMessage;
            }
            if (filled($invoice['business_date'] ?? null)) {
                try {
                    $periodId = $this->accountingContext->resolvePeriodId($invoice['business_date'], $companyId);
                    $this->periodGate->assertDateOpen(
                        $invoice['business_date'],
                        $companyId,
                        $periodId,
                        'ap',
                        'business_date'
                    );
                } catch (ValidationException $exception) {
                    $invoice['errors']['business_date'] = collect($exception->errors())->flatten()->first()
                        ?? __('The accounting period is not open.');
                }
            }
            $invoice['status'] = $invoice['errors'] === [] ? 'valid' : 'invalid';
        }
        unset($invoice);
        $invalid = count(array_filter(
            $validated['invoices'],
            fn (array $invoice): bool => $invoice['status'] === 'invalid'
        ));
        $validated['stats']['invalid_invoices'] = $invalid;
        $validated['stats']['valid_invoices'] = count($validated['invoices']) - $invalid;
    }

    private function assertWorkbookShape(array $sheets, array $headers, string $importMode): void
    {
        $errors = [];
        if (! array_key_exists(self::DATA_SHEET, $sheets)) {
            $errors['sheets'] = __('The workbook is missing the Petty Cash Expenses sheet.');
        }
        $unexpected = array_values(array_diff(array_keys($sheets), self::ALLOWED_SHEETS));
        if ($unexpected !== []) {
            $errors['unexpected_sheets'] = __('Unexpected workbook sheets: :sheets.', ['sheets' => implode(', ', $unexpected)]);
        }
        $expectedHeaders = $importMode === 'bulk' ? self::BULK_HEADERS : self::HEADERS;
        if (array_key_exists(self::DATA_SHEET, $sheets)
            && ($headers[self::DATA_SHEET] ?? []) !== $expectedHeaders) {
            $errors['headers'] = __('The Petty Cash Expenses headers are missing, unexpected, or out of order.');
        }
        if (array_key_exists(self::CATEGORY_DEFINITION_SHEET, $sheets)
            && ($headers[self::CATEGORY_DEFINITION_SHEET] ?? []) !== self::CATEGORY_DEFINITION_HEADERS) {
            $errors['category_definitions'] = __('The Category Definitions headers must be code and name.');
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function assertUpload(UploadedFile $workbook): void
    {
        if (! $workbook->isValid() || strtolower($workbook->getClientOriginalExtension()) !== 'xlsx') {
            throw ValidationException::withMessages(['workbook' => __('Upload a valid XLSX workbook.')]);
        }
        $maxKb = (int) config('petty_cash.imports.max_upload_kb', 10_240);
        if ((int) ceil(((int) $workbook->getSize()) / 1024) > $maxKb) {
            throw ValidationException::withMessages([
                'workbook' => __('The workbook may not exceed :size MB.', ['size' => round($maxKb / 1024)]),
            ]);
        }
    }

    private function businessDate(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (! $date || $date->format('Y-m-d') !== $value) {
            throw ValidationException::withMessages(['business_date' => __('Business date must use YYYY-MM-DD.')]);
        }

        return $value;
    }

    private function fundingSource(string $value): string
    {
        if (! in_array($value, ['petty_cash', 'bank_account'], true)) {
            throw ValidationException::withMessages([
                'funding_source' => __('Choose a valid payment source.'),
            ]);
        }

        return $value;
    }

    private function resolveBankAccountId(?int $bankAccountId, int $companyId, string $currencyCode): int
    {
        $bank = $this->mappings->resolveBankAccount($bankAccountId, $companyId);
        if (! $bank instanceof BankAccount) {
            throw ValidationException::withMessages([
                'default_bank_account_id' => __('Choose an active bank account for this company.'),
            ]);
        }
        if ((int) $bank->company_id !== $companyId || ! $bank->ledger_account_id) {
            throw ValidationException::withMessages([
                'default_bank_account_id' => __('The selected bank account must belong to the company and be linked to a ledger account.'),
            ]);
        }
        if (filled($bank->currency_code) && strtoupper((string) $bank->currency_code) !== strtoupper($currencyCode)) {
            throw ValidationException::withMessages([
                'default_bank_account_id' => __('The bank account currency must match the accounting company currency.'),
            ]);
        }

        return (int) $bank->id;
    }

    private function nullableBusinessDate(mixed $value): ?string
    {
        $raw = is_scalar($value) ? trim((string) $value) : '';
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);

        return $date && $date->format('Y-m-d') === $raw ? $raw : null;
    }

    private function assertAccess(User $actor): void
    {
        if (! $actor->hasRole('admin') || ! $actor->can('petty_cash.import')) {
            throw new AuthorizationException(__('Only administrators may import petty cash expenses.'));
        }
    }

    private function existingBatch(int $companyId, string $idempotencyKey): ?PettyCashImportBatch
    {
        return PettyCashImportBatch::query()
            ->where('company_id', $companyId)
            ->where('idempotency_key', $idempotencyKey)
            ->first()
            ?->load(['invoices.rows', 'rows', 'categoryProposals']);
    }
}
