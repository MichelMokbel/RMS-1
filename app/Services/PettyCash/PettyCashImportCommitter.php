<?php

namespace App\Services\PettyCash;

use App\Enums\PettyCash\PettyCashImportStatus;
use App\Models\AccountingCompany;
use App\Models\ApInvoice;
use App\Models\ExpenseCategory;
use App\Models\PettyCashImportBatch;
use App\Models\PettyCashImportCategoryProposal;
use App\Models\PettyCashImportInvoice;
use App\Models\PettyCashImportRow;
use App\Models\PettyCashWallet;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\Accounting\AccountingContextService;
use App\Services\Accounting\AccountingPeriodGateService;
use App\Services\AP\ApExpenseCreationService;
use App\Services\AP\SupplierAccountingPolicyService;
use App\Services\Sequences\DocumentSequenceService;
use App\Services\Spend\ExpenseWorkflowService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

class PettyCashImportCommitter
{
    public function __construct(
        protected AccountingContextService $accountingContext,
        protected AccountingPeriodGateService $periodGate,
        protected ApExpenseCreationService $expenses,
        protected SupplierAccountingPolicyService $supplierPolicy,
        protected ExpenseWorkflowService $workflow,
        protected DocumentSequenceService $sequences,
        protected AccountingAuditLogService $audit,
    ) {}

    public function commit(PettyCashImportBatch $batch, User $actor): PettyCashImportBatch
    {
        try {
            $result = DB::transaction(function () use ($batch, $actor): PettyCashImportBatch {
                $locked = PettyCashImportBatch::query()->lockForUpdate()->findOrFail($batch->id);
                if ($locked->status === PettyCashImportStatus::Completed) {
                    return $locked;
                }
                if ($locked->status !== PettyCashImportStatus::Ready) {
                    throw ValidationException::withMessages(['status' => __('Only a validated import can be committed.')]);
                }

                $company = AccountingCompany::query()
                    ->whereKey($locked->company_id)
                    ->lockForUpdate()
                    ->first();
                if (! $company || ! $company->is_active) {
                    throw ValidationException::withMessages([
                        'company_id' => __('The selected accounting company is inactive.'),
                    ]);
                }

                $locked->forceFill([
                    'status' => 'committing',
                    'failure_reason' => null,
                    'failed_at' => null,
                ])->save();

                $stagedInvoices = PettyCashImportInvoice::query()
                    ->where('import_batch_id', $locked->id)
                    ->where('excluded', false)
                    ->with(['rows' => fn ($query) => $query->where('excluded', false)->orderBy('row_number')])
                    ->orderBy('business_date')
                    ->orderBy('id')
                    ->get();
                if ($stagedInvoices->isEmpty() || $stagedInvoices->contains(fn ($invoice) => $invoice->status->value !== 'valid')) {
                    throw ValidationException::withMessages(['rows' => __('All staged entries must be valid before commit.')]);
                }

                foreach ($stagedInvoices->pluck('business_date')->filter()->unique() as $date) {
                    $businessDate = $date->format('Y-m-d');
                    $periodId = $this->accountingContext->resolvePeriodId($businessDate, (int) $locked->company_id);
                    $this->periodGate->assertDateOpen(
                        $businessDate,
                        (int) $locked->company_id,
                        $periodId,
                        'ap',
                        'business_date'
                    );
                }

                $this->resolveCategories($locked, $stagedInvoices, $actor);
                $this->lockSuppliers($stagedInvoices);
                $downgradedAtCommit = $this->lockAndApplyWalletCapacity($stagedInvoices);
                foreach ($stagedInvoices as $stagedInvoice) {
                    $this->commitInvoice(
                        $locked,
                        $stagedInvoice,
                        $actor,
                        $stagedInvoice->business_date->format('Y-m-d'),
                        (string) $company->base_currency
                    );
                }

                $stats = $locked->stats ?? [];
                $stats['unpaid_for_insufficient_balance'] = (int) ($stats['unpaid_for_insufficient_balance'] ?? 0)
                    + $downgradedAtCommit;
                $locked->forceFill([
                    'status' => 'completed',
                    'stats' => $stats,
                    'committed_by' => $actor->id,
                    'committed_at' => now(),
                    'failure_reason' => null,
                    'failed_at' => null,
                ])->save();
                $this->audit->log('petty_cash_import.committed', (int) $actor->id, $locked, [
                    'date_from' => $locked->date_from?->format('Y-m-d') ?? $locked->business_date->format('Y-m-d'),
                    'date_to' => $locked->date_to?->format('Y-m-d') ?? $locked->business_date->format('Y-m-d'),
                    'invoices' => $stagedInvoices->count(),
                    'rows' => $stagedInvoices->sum(fn ($invoice) => $invoice->rows->count()),
                    'unpaid_for_insufficient_balance' => $stats['unpaid_for_insufficient_balance'],
                    'target_invoice_ids' => $stagedInvoices->pluck('target_invoice_id')->filter()->values()->all(),
                ], (int) $locked->company_id);

                return $locked;
            });

            return $result->fresh(['invoices.rows', 'rows']);
        } catch (Throwable $exception) {
            $this->recordFailure($batch, $actor, $exception);

            throw $exception;
        }
    }

    private function commitInvoice(
        PettyCashImportBatch $batch,
        PettyCashImportInvoice $stagedInvoice,
        User $actor,
        string $businessDate,
        string $currencyCode
    ): void {
        $header = $stagedInvoice->header;
        $this->assertCommitEntry($header, $businessDate, (int) $batch->company_id);
        $sequence = $this->sequences->next(
            'pc_expense_'.str_replace('-', '', $businessDate),
            (int) $batch->company_id,
            substr($businessDate, 0, 4)
        );
        if ($sequence > 9999) {
            throw ValidationException::withMessages([
                'invoice_number' => __('The daily petty cash expense sequence has exceeded 9,999 documents.'),
            ]);
        }

        $invoice = $this->expenses->createDraft([
            'company_id' => (int) $batch->company_id,
            'supplier_id' => (int) $header['supplier_id'],
            'category_id' => (int) $header['category_id'],
            'wallet_id' => (int) $header['wallet_id'],
            'invoice_number' => sprintf('EXP-%s-%04d', str_replace('-', '', $businessDate), $sequence),
            'reference_number' => $header['reference_number'] ?? null,
            'invoice_date' => $businessDate,
            'due_date' => $header['due_date'],
            'tax_amount' => (float) ($header['tax_amount'] ?? 0),
            'currency_code' => $currencyCode,
            'notes' => $header['notes'] ?? null,
            'source_document_type' => 'petty_cash_expense_import',
            'source_document_id' => (int) $stagedInvoice->id,
            'items' => $stagedInvoice->rows->map(fn (PettyCashImportRow $row): array => [
                'description' => $row->payload['description'],
                'quantity' => $row->payload['quantity'],
                'unit_price' => $row->payload['unit_price'],
            ])->all(),
        ], (int) $actor->id);

        $invoice = $this->workflow->autoProcessPettyCashOnCreate(
            $invoice,
            (int) $actor->id,
            ! (bool) $header['paid'],
            [
                'payment_method' => 'petty_cash',
                'payment_date' => $businessDate,
                'recognition_date' => $businessDate,
                'reference' => $header['reference_number'] ?? null,
                'client_uuid' => (string) $stagedInvoice->client_uuid,
                'notes' => __('Settlement created by petty cash import :batch.', ['batch' => $batch->id]),
            ]
        );

        $stagedInvoice->forceFill([
            'status' => 'committed',
            'target_invoice_id' => $invoice->id,
        ])->save();
        PettyCashImportRow::query()
            ->where('import_invoice_id', $stagedInvoice->id)
            ->where('excluded', false)
            ->update([
                'status' => 'committed',
                'target_type' => ApInvoice::class,
                'target_id' => $invoice->id,
                'updated_at' => now(),
            ]);
    }

    private function lockSuppliers($stagedInvoices): void
    {
        $supplierIds = $stagedInvoices
            ->map(fn (PettyCashImportInvoice $invoice): int => (int) ($invoice->header['supplier_id'] ?? 0))
            ->filter()
            ->unique()
            ->sort()
            ->values();

        Supplier::query()->whereIn('id', $supplierIds)->orderBy('id')->lockForUpdate()->get();
    }

    private function resolveCategories(PettyCashImportBatch $batch, $stagedInvoices, User $actor): void
    {
        $categories = ExpenseCategory::query()->orderBy('id')->lockForUpdate()->get();
        $byName = $categories->groupBy(fn (ExpenseCategory $category): string => $this->normalizeCategoryName($category->name));
        $proposals = PettyCashImportCategoryProposal::query()
            ->where('import_batch_id', $batch->id)
            ->orderBy('normalized_name')
            ->lockForUpdate()
            ->get();

        foreach ($proposals as $proposal) {
            $matches = $byName->get($proposal->normalized_name, collect());
            if ($matches->count() > 1) {
                throw ValidationException::withMessages([
                    'category' => __('A staged category name matches multiple existing categories.'),
                ]);
            }
            $category = $matches->first();
            $created = false;
            if ($category && ! $category->active) {
                throw ValidationException::withMessages([
                    'category' => __('An inactive category named :name must be reviewed before commit.', [
                        'name' => $proposal->source_name,
                    ]),
                ]);
            }
            if (! $category) {
                $created = true;
                $category = ExpenseCategory::query()->create([
                    'name' => $proposal->source_name,
                    'description' => __('Created by petty cash import batch :batch.', ['batch' => $batch->id]),
                    'active' => true,
                ]);
                $byName->put($proposal->normalized_name, collect([$category]));
                $this->audit->log('expense_category.created_by_import', (int) $actor->id, $category, [
                    'import_batch_id' => $batch->id,
                    'source_code' => $proposal->source_code,
                    'source_name' => $proposal->source_name,
                ], (int) $batch->company_id);
            }
            $proposal->forceFill([
                'status' => $created ? 'created' : 'matched',
                'expense_category_id' => $category->id,
            ])->save();
        }

        foreach ($stagedInvoices as $invoice) {
            $header = $invoice->header;
            if ((int) ($header['category_id'] ?? 0) > 0) {
                continue;
            }
            $normalized = (string) ($header['category_normalized'] ?? '');
            $category = $byName->get($normalized, collect())->first();
            if (! $category) {
                throw ValidationException::withMessages(['category' => __('A staged category could not be resolved.')]);
            }
            $header['category_id'] = (int) $category->id;
            $invoice->forceFill(['header' => $header])->save();
            foreach ($invoice->rows as $row) {
                $payload = $row->payload;
                $payload['category_id'] = (int) $category->id;
                $row->forceFill(['payload' => $payload])->save();
            }
        }
    }

    private function normalizeCategoryName(string $name): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($name)) ?: trim($name);

        return mb_strtolower($collapsed);
    }

    private function lockAndApplyWalletCapacity($stagedInvoices): int
    {
        $walletIds = $stagedInvoices
            ->filter(fn (PettyCashImportInvoice $invoice): bool => (bool) ($invoice->header['paid'] ?? false))
            ->map(fn (PettyCashImportInvoice $invoice): int => (int) ($invoice->header['wallet_id'] ?? 0))
            ->filter()
            ->unique()
            ->sort()
            ->values();
        $wallets = PettyCashWallet::query()
            ->whereIn('id', $walletIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $remainingByWallet = $wallets->map(fn (PettyCashWallet $wallet): float => round((float) $wallet->balance, 2))->all();
        $downgraded = 0;

        foreach ($stagedInvoices as $invoice) {
            $header = $invoice->header;
            if (! (bool) ($header['paid'] ?? false)) {
                continue;
            }
            $walletId = (int) ($header['wallet_id'] ?? 0);
            $wallet = $wallets->get($walletId);
            if (! $wallet || ! $wallet->isActive()) {
                throw ValidationException::withMessages(['wallet_id' => __('A settlement wallet is inactive or missing.')]);
            }
            $total = round($invoice->rows->sum(fn (PettyCashImportRow $row): float => round(
                (float) $row->payload['quantity'] * (float) $row->payload['unit_price'],
                2
            )) + (float) ($header['tax_amount'] ?? 0), 2);
            if (round($remainingByWallet[$walletId] - $total, 2) >= 0) {
                $remainingByWallet[$walletId] = round($remainingByWallet[$walletId] - $total, 2);

                continue;
            }

            $message = __('The wallet balance is insufficient, so this invoice was imported as unpaid.');
            $header['paid_requested'] = true;
            $header['paid'] = false;
            $header['settlement_warning'] = $message;
            $invoice->forceFill(['header' => $header])->save();
            foreach ($invoice->rows as $row) {
                $payload = $row->payload;
                $payload['paid_requested'] = true;
                $payload['paid'] = false;
                $payload['settlement_warning'] = $message;
                $row->forceFill(['payload' => $payload])->save();
            }
            $downgraded++;
        }

        return $downgraded;
    }

    /** @param array<string, mixed> $header */
    private function assertCommitEntry(array $header, string $businessDate, int $companyId): void
    {
        $supplier = Supplier::query()->find((int) ($header['supplier_id'] ?? 0));
        if (! $supplier
            || ! $supplier->isActive()
            || ($supplier->company_id && (int) $supplier->company_id !== $companyId)) {
            throw ValidationException::withMessages(['supplier' => __('The staged supplier is no longer available.')]);
        }
        $this->supplierPolicy->assertCanPost($supplier, 'supplier');

        $reference = trim((string) ($header['reference_number'] ?? ''));
        if ($reference !== ''
            && Schema::hasColumn('ap_invoices', 'reference_number')
            && ApInvoice::query()
                ->where('supplier_id', $supplier->id)
                ->whereDate('invoice_date', $businessDate)
                ->where('reference_number', $reference)
                ->where('status', '!=', 'void')
                ->exists()) {
            throw ValidationException::withMessages([
                'reference_number' => __('This supplier reference already exists on the business date.'),
            ]);
        }
    }

    private function recordFailure(PettyCashImportBatch $batch, User $actor, Throwable $exception): void
    {
        try {
            $current = PettyCashImportBatch::query()->find($batch->id);
            if ($current && in_array($current->status, [
                PettyCashImportStatus::Ready,
                PettyCashImportStatus::Committing,
            ], true)) {
                $current->forceFill([
                    'status' => 'ready',
                    'failed_at' => now(),
                    'failure_reason' => __('The import could not be committed. No documents were changed.'),
                ])->save();
                $this->audit->log('petty_cash_import.commit_failed', (int) $actor->id, $current, [
                    'exception' => $exception::class,
                ], (int) $current->company_id);
            }
        } catch (Throwable $cleanupException) {
            Log::error('Unable to persist petty cash import commit failure metadata.', [
                'batch_id' => $batch->id,
                'original_exception' => $exception::class,
                'cleanup_exception' => $cleanupException::class,
            ]);
        }
    }
}
