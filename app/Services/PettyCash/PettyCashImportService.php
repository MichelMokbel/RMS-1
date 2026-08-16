<?php

namespace App\Services\PettyCash;

use App\Models\AccountingCompany;
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

    private const ALLOWED_SHEETS = [
        self::DATA_SHEET, 'instructions', 'suppliers', 'categories', 'wallets',
    ];

    public function __construct(
        protected SafeSpreadsheetReader $reader,
        protected PettyCashImportValidator $validator,
        protected AccountingContextService $accountingContext,
        protected AccountingPeriodGateService $periodGate,
        protected LedgerAccountMappingService $mappings,
        protected PettyCashImportCommitter $committer,
        protected AccountingAuditLogService $audit,
    ) {}

    public function stage(
        UploadedFile $workbook,
        string $businessDate,
        ?int $defaultCategoryId,
        ?int $defaultWalletId,
        int $companyId,
        User $actor
    ): PettyCashImportBatch {
        $this->assertAccess($actor);
        $company = AccountingCompany::query()->findOrFail($companyId);
        if (! $company->is_active) {
            throw ValidationException::withMessages([
                'company_id' => __('The selected accounting company is inactive.'),
            ]);
        }
        $businessDate = $this->businessDate($businessDate);
        $this->assertUpload($workbook);

        $periodId = $this->accountingContext->resolvePeriodId($businessDate, $companyId);
        $this->periodGate->assertDateOpen($businessDate, $companyId, $periodId, 'ap', 'business_date');

        $sha256 = hash_file('sha256', $workbook->getRealPath());
        if (! is_string($sha256) || $sha256 === '') {
            throw ValidationException::withMessages(['workbook' => __('The workbook checksum could not be calculated.')]);
        }
        $idempotencyKey = hash('sha256', json_encode([
            'company_id' => $companyId,
            'business_date' => $businessDate,
            'sha256' => $sha256,
            'default_category_id' => $defaultCategoryId,
            'default_wallet_id' => $defaultWalletId,
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
        $this->assertWorkbookShape($parsed['sheets'], $parsed['headers']);
        $sourceRows = $parsed['sheets'][self::DATA_SHEET];
        if ($sourceRows === []) {
            throw ValidationException::withMessages(['workbook' => __('The workbook must contain at least one expense line.')]);
        }
        $validated = $this->validator->validate(
            $sourceRows,
            $companyId,
            $defaultCategoryId,
            $defaultWalletId,
            $businessDate,
        );
        if ((int) $validated['stats']['rows'] === 0) {
            throw ValidationException::withMessages(['workbook' => __('The workbook must contain at least one expense line.')]);
        }
        $this->assertAccountingPreflight($validated, $companyId);

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
                $defaultCategoryId,
                $defaultWalletId,
                $companyId,
                $actor,
                $sha256,
                $idempotencyKey,
                $disk,
                $objectKey,
                $validated
            ): PettyCashImportBatch {
                $invalid = (int) $validated['stats']['invalid_rows'] + (int) $validated['stats']['invalid_invoices'];
                $batch = PettyCashImportBatch::query()->create([
                    'company_id' => $companyId,
                    'business_date' => $businessDate,
                    'default_category_id' => $defaultCategoryId,
                    'default_wallet_id' => $defaultWalletId,
                    'status' => $invalid === 0 ? 'ready' : 'failed',
                    'source_name' => Str::limit(basename($workbook->getClientOriginalName()), 255, ''),
                    'storage_disk' => $disk,
                    'object_key' => $objectKey,
                    'sha256' => $sha256,
                    'idempotency_key' => $idempotencyKey,
                    'stats' => $validated['stats'],
                    'initiated_by' => $actor->id,
                    'initiated_at' => now(),
                    'failed_at' => $invalid === 0 ? null : now(),
                    'failure_reason' => $invalid === 0 ? null : 'Petty cash workbook validation failed.',
                ]);

                $invoiceIds = [];
                foreach ($validated['invoices'] as $entry) {
                    $stagedInvoice = PettyCashImportInvoice::query()->create([
                        'import_batch_id' => $batch->id,
                        'entry_id' => $entry['entry_id'],
                        'group_key' => $entry['group_key'],
                        'status' => $entry['status'],
                        'header' => $entry['header'],
                        'errors' => $entry['errors'],
                        'client_uuid' => $entry['client_uuid'],
                    ]);
                    $invoiceIds[$entry['entry_id']] = (int) $stagedInvoice->id;
                }

                foreach ($validated['rows'] as $entry) {
                    $payload = $entry['payload'];
                    PettyCashImportRow::query()->create([
                        'import_batch_id' => $batch->id,
                        'import_invoice_id' => $invoiceIds[$payload['entry_id']] ?? null,
                        'row_number' => $entry['row_number'],
                        'source_identifier' => Str::limit((string) ($payload['entry_id'] ?? ''), 191, ''),
                        'status' => $entry['status'],
                        'payload' => $payload,
                        'errors' => $entry['errors'],
                        'row_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
                    ]);
                }

                $this->audit->log(
                    $invalid === 0 ? 'petty_cash_import.staged' : 'petty_cash_import.validation_failed',
                    (int) $actor->id,
                    $batch,
                    [
                        'business_date' => $businessDate,
                        'sha256' => $sha256,
                        'rows' => $validated['stats']['rows'],
                        'invoices' => $validated['stats']['invoices'],
                        'unpaid_for_insufficient_balance' => $validated['stats']['unpaid_for_insufficient_balance'],
                        'invalid_rows' => $validated['stats']['invalid_rows'],
                        'invalid_invoices' => $validated['stats']['invalid_invoices'],
                    ],
                    $companyId
                );

                return $batch->fresh(['invoices.rows', 'rows']);
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
    private function assertAccountingPreflight(array $validated, int $companyId): void
    {
        $invoices = collect($validated['invoices'])
            ->filter(fn (array $invoice): bool => ($invoice['errors'] ?? []) === []);
        if ($invoices->isEmpty()) {
            return;
        }

        $required = ['ap_control'];
        if ($invoices->contains(fn (array $invoice): bool => (bool) ($invoice['header']['paid'] ?? false))) {
            $required[] = 'petty_cash_asset';
        }
        $supplierIds = $invoices->pluck('header.supplier_id')->filter()->map(fn ($id): int => (int) $id)->unique();
        if (Supplier::query()->whereIn('id', $supplierIds)->whereNull('default_expense_account_id')->exists()) {
            $required[] = 'expense_default';
        }

        $this->mappings->assertRequiredMappings($companyId, array_values(array_unique($required)));
    }

    private function assertWorkbookShape(array $sheets, array $headers): void
    {
        $errors = [];
        if (! array_key_exists(self::DATA_SHEET, $sheets)) {
            $errors['sheets'] = __('The workbook is missing the Petty Cash Expenses sheet.');
        }
        $unexpected = array_values(array_diff(array_keys($sheets), self::ALLOWED_SHEETS));
        if ($unexpected !== []) {
            $errors['unexpected_sheets'] = __('Unexpected workbook sheets: :sheets.', ['sheets' => implode(', ', $unexpected)]);
        }
        if (array_key_exists(self::DATA_SHEET, $sheets)
            && ($headers[self::DATA_SHEET] ?? []) !== self::HEADERS) {
            $errors['headers'] = __('The Petty Cash Expenses headers are missing, unexpected, or out of order.');
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
            ?->load(['invoices.rows', 'rows']);
    }
}
