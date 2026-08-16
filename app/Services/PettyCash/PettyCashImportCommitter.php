<?php

namespace App\Services\PettyCash;

use App\Enums\PettyCash\PettyCashImportStatus;
use App\Models\AccountingCompany;
use App\Models\ApInvoice;
use App\Models\PettyCashImportBatch;
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

                $businessDate = $locked->business_date->format('Y-m-d');
                $periodId = $this->accountingContext->resolvePeriodId($businessDate, (int) $locked->company_id);
                $this->periodGate->assertDateOpen($businessDate, (int) $locked->company_id, $periodId, 'ap', 'business_date');
                $locked->forceFill([
                    'status' => 'committing',
                    'failure_reason' => null,
                    'failed_at' => null,
                ])->save();

                $stagedInvoices = PettyCashImportInvoice::query()
                    ->where('import_batch_id', $locked->id)
                    ->with(['rows' => fn ($query) => $query->orderBy('row_number')])
                    ->orderBy('id')
                    ->get();
                if ($stagedInvoices->isEmpty() || $stagedInvoices->contains(fn ($invoice) => $invoice->status->value !== 'valid')) {
                    throw ValidationException::withMessages(['rows' => __('All staged entries must be valid before commit.')]);
                }

                $this->lockSuppliers($stagedInvoices);
                $this->lockAndValidateWalletCapacity($stagedInvoices);
                foreach ($stagedInvoices as $stagedInvoice) {
                    $this->commitInvoice($locked, $stagedInvoice, $actor, $businessDate, (string) $company->base_currency);
                }

                $locked->forceFill([
                    'status' => 'completed',
                    'committed_by' => $actor->id,
                    'committed_at' => now(),
                    'failure_reason' => null,
                    'failed_at' => null,
                ])->save();
                $this->audit->log('petty_cash_import.committed', (int) $actor->id, $locked, [
                    'business_date' => $businessDate,
                    'invoices' => $stagedInvoices->count(),
                    'rows' => $stagedInvoices->sum(fn ($invoice) => $invoice->rows->count()),
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
            'tax_amount' => $header['tax_amount'],
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

    private function lockAndValidateWalletCapacity($stagedInvoices): void
    {
        $paidByWallet = [];
        foreach ($stagedInvoices as $invoice) {
            $header = $invoice->header;
            if (! (bool) ($header['paid'] ?? false)) {
                continue;
            }
            $total = $invoice->rows->sum(fn (PettyCashImportRow $row): float => round(
                (float) $row->payload['quantity'] * (float) $row->payload['unit_price'],
                2
            ));
            $walletId = (int) $header['wallet_id'];
            $paidByWallet[$walletId] = round(($paidByWallet[$walletId] ?? 0) + $total + (float) $header['tax_amount'], 2);
        }

        ksort($paidByWallet);
        foreach ($paidByWallet as $walletId => $required) {
            $wallet = PettyCashWallet::query()->whereKey($walletId)->lockForUpdate()->first();
            if (! $wallet || ! $wallet->isActive()) {
                throw ValidationException::withMessages(['wallet_id' => __('A settlement wallet is inactive or missing.')]);
            }
            if (! config('petty_cash.allow_negative_wallet_balance', false)
                && round((float) $wallet->balance - $required, 2) < 0) {
                throw ValidationException::withMessages([
                    'balance' => __('Wallet :wallet does not have enough balance for this import.', [
                        'wallet' => $wallet->driver_name,
                    ]),
                ]);
            }
        }
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
