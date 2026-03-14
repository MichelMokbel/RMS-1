<?php

namespace App\Services\AP;

use App\Models\ApInvoice;
use App\Models\ApPayment;
use App\Models\ApPaymentAllocation;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\Accounting\AccountingContextService;
use App\Services\Accounting\LedgerAccountMappingService;
use App\Services\Accounting\AccountingPeriodGateService;
use App\Services\Banking\BankTransactionService;
use App\Services\Ledger\SubledgerService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApAllocationService
{
    public function __construct(
        protected ApInvoiceStatusService $statusService,
        protected SubledgerService $subledgerService,
        protected AccountingContextService $accountingContext,
        protected LedgerAccountMappingService $mappingService,
        protected AccountingPeriodGateService $periodGate,
        protected AccountingAuditLogService $auditLog,
        protected BankTransactionService $bankTransactionService
    )
    {
    }

    public function createPaymentWithAllocations(array $payload, int $userId): ApPayment
    {
        return DB::transaction(function () use ($payload, $userId) {
            $this->validateAllocations($payload['allocations'] ?? [], $payload['supplier_id']);
            $companyId = $this->accountingContext->resolveCompanyId($payload['branch_id'] ?? null, $payload['company_id'] ?? null);
            $periodId = $this->accountingContext->resolvePeriodId($payload['payment_date'] ?? null, $payload['company_id'] ?? null);
            $paymentMethod = $this->mappingService->normalizePaymentMethod((string) ($payload['payment_method'] ?? 'bank_transfer'));
            $bankAccountId = $this->resolveBankAccountId($paymentMethod, $companyId, $payload['bank_account_id'] ?? null);
            $this->periodGate->assertDateOpen((string) $payload['payment_date'], $companyId, $periodId, 'ap', 'payment_date');

            $payment = ApPayment::create([
                'supplier_id' => $payload['supplier_id'],
                'company_id' => $companyId,
                'bank_account_id' => $bankAccountId,
                'branch_id' => $payload['branch_id'] ?? null,
                'department_id' => $payload['department_id'] ?? null,
                'job_id' => $payload['job_id'] ?? null,
                'period_id' => $periodId,
                'payment_date' => $payload['payment_date'],
                'amount' => $payload['amount'],
                'payment_method' => $paymentMethod,
                'currency_code' => $payload['currency_code'] ?? config('pos.currency', 'QAR'),
                'reference' => $payload['reference'] ?? null,
                'notes' => $payload['notes'] ?? null,
                'created_by' => $userId,
                'posted_at' => now(),
                'posted_by' => $userId,
            ]);

            $allocations = $payload['allocations'] ?? [];
            if (empty($allocations) && ! Config::get('ap.allow_unapplied_payments', true)) {
                throw ValidationException::withMessages(['allocations' => __('Allocations required.')]);
            }

            $this->applyAllocations($payment, $allocations);
            $this->subledgerService->recordApPayment($payment, $userId);
            $this->bankTransactionService->recordApPayment($payment->fresh(), $userId);
            $this->auditLog->log('ap_payment.created', $userId, $payment, [
                'supplier_id' => (int) $payment->supplier_id,
                'amount' => (float) $payment->amount,
            ], (int) ($payment->company_id ?? 0) ?: null);

            return $payment->fresh(['allocations.invoice']);
        });
    }

    public function allocateExistingPayment(ApPayment $payment, array $allocations, int $userId): ApPayment
    {
        return DB::transaction(function () use ($payment, $allocations, $userId) {
            $this->validateAllocations($allocations, $payment->supplier_id, $payment);
            $newAllocations = $this->applyAllocations($payment, $allocations);
            foreach ($newAllocations as $allocation) {
                $this->subledgerService->recordApPaymentAllocation($allocation, $userId);
            }
            $this->auditLog->log('ap_payment.allocated', $userId, $payment, [
                'allocation_count' => count($newAllocations),
            ], (int) ($payment->company_id ?? 0) ?: null);
            return $payment->fresh(['allocations.invoice']);
        });
    }

    private function validateAllocations(array $allocations, int $supplierId, ?ApPayment $payment = null): void
    {
        $totalAlloc = 0;
        foreach ($allocations as $row) {
            $invoice = ApInvoice::where('id', $row['invoice_id'] ?? 0)->lockForUpdate()->first();
            if (! $invoice) {
                throw ValidationException::withMessages(['allocations' => __('Invoice not found.')]);
            }
            if ($invoice->supplier_id !== $supplierId) {
                throw ValidationException::withMessages(['allocations' => __('Allocations must match supplier.')]);
            }
            if (! in_array($invoice->status, ['posted', 'partially_paid'], true)) {
                throw ValidationException::withMessages(['allocations' => __('Invoice must be posted or partially paid.')]);
            }
            $amount = (float) ($row['allocated_amount'] ?? 0);
            if ($amount <= 0) {
                throw ValidationException::withMessages(['allocations' => __('Allocation amount must be positive.')]);
            }
            $outstanding = (float) $invoice->total_amount - (float) $invoice->allocations()->sum('allocated_amount');
            if ($amount > $outstanding) {
                throw ValidationException::withMessages(['allocations' => __('Allocation exceeds outstanding.')]);
            }
            $totalAlloc += $amount;
        }

        if ($payment) {
            if ($totalAlloc > $payment->unallocatedAmount()) {
                throw ValidationException::withMessages(['allocations' => __('Allocations exceed remaining payment amount.')]);
            }
        }
    }

    private function applyAllocations(ApPayment $payment, array $allocations): array
    {
        $created = [];
        $sum = 0;
        foreach ($allocations as $row) {
            $invoice = ApInvoice::where('id', $row['invoice_id'])->lockForUpdate()->firstOrFail();
            $allocAmount = (float) $row['allocated_amount'];
            $sum += $allocAmount;

            $created[] = ApPaymentAllocation::create([
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'allocated_amount' => $allocAmount,
            ]);

            $this->statusService->recalcStatus($invoice);
        }

        if ($sum > (float) $payment->amount) {
            throw ValidationException::withMessages(['allocations' => __('Total allocations exceed payment amount.')]);
        }

        return $created;
    }

    private function resolveBankAccountId(string $paymentMethod, ?int $companyId, mixed $bankAccountId): ?int
    {
        if ($paymentMethod !== 'bank_transfer') {
            return null;
        }

        $bankAccount = $this->mappingService->resolveBankAccount((int) ($bankAccountId ?? 0), $companyId);
        if (! $bankAccount) {
            throw ValidationException::withMessages([
                'bank_account_id' => __('A bank account is required for bank transfers.'),
            ]);
        }

        if ((int) $bankAccount->company_id !== (int) $companyId) {
            throw ValidationException::withMessages([
                'bank_account_id' => __('The selected bank account does not belong to the payment company.'),
            ]);
        }

        if (! $bankAccount->ledger_account_id) {
            throw ValidationException::withMessages([
                'bank_account_id' => __('The selected bank account must be linked to a ledger account.'),
            ]);
        }

        return (int) $bankAccount->id;
    }
}
