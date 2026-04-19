<?php

namespace App\Services\AR;

use App\Models\ArInvoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\Banking\BankTransactionService;
use App\Services\Ledger\SubledgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ArPaymentDeleteService
{
    public function __construct(
        protected ArAllocationService $allocationService,
        protected SubledgerService $subledgerService,
        protected BankTransactionService $bankTransactionService,
        protected AccountingAuditLogService $auditLog,
    ) {
    }

    public function removeAllocation(PaymentAllocation $allocation, int $userId): void
    {
        DB::transaction(function () use ($allocation, $userId): void {
            $allocation = PaymentAllocation::query()
                ->whereKey($allocation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($allocation->voided_at !== null) {
                throw ValidationException::withMessages([
                    'allocation' => __('This allocation has already been removed.'),
                ]);
            }

            if ($allocation->allocatable_type !== ArInvoice::class) {
                throw ValidationException::withMessages([
                    'allocation' => __('Only AR invoice allocations can be removed here.'),
                ]);
            }

            $this->subledgerService->recordArAllocationReleased($allocation, $userId, 'delete');

            // Void the allocation record
            $allocation->voided_at = now();
            $allocation->voided_by = $userId;
            $allocation->void_reason = 'Manually removed';
            $allocation->save();

            // Recalculate the invoice balance and status
            $invoiceId = (int) $allocation->allocatable_id;
            $invoice = ArInvoice::query()->whereKey($invoiceId)->lockForUpdate()->first();

            if ($invoice) {
                $paid = (int) PaymentAllocation::query()
                    ->where('allocatable_type', ArInvoice::class)
                    ->where('allocatable_id', $invoice->id)
                    ->whereNull('voided_at')
                    ->sum('amount_cents');

                $invoice->update([
                    'paid_total_cents' => $paid,
                    'balance_cents'    => (int) $invoice->total_cents - $paid,
                ]);

                $this->allocationService->recalcStatus($invoice->fresh());
            }

            $this->auditLog->log('ar_payment.allocation_removed', $userId, $allocation, [
                'invoice_id' => $invoiceId,
                'payment_id' => (int) $allocation->payment_id,
                'amount_cents' => (int) $allocation->amount_cents,
            ]);
        });
    }

    public function delete(Payment $payment, int $userId): void
    {
        DB::transaction(function () use ($payment, $userId): void {
            $payment = Payment::query()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((string) $payment->source !== 'ar') {
                throw ValidationException::withMessages([
                    'payment' => __('Only AR customer payments can be deleted here.'),
                ]);
            }
            if ($payment->voided_at !== null) {
                throw ValidationException::withMessages([
                    'payment' => __('This payment has already been voided.'),
                ]);
            }

            $allocations = PaymentAllocation::query()
                ->where('payment_id', $payment->id)
                ->lockForUpdate()
                ->get();

            $invoiceIds = $allocations
                ->filter(fn (PaymentAllocation $allocation) => $allocation->allocatable_type === ArInvoice::class)
                ->pluck('allocatable_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();

            $voidedAt = now();
            $voidDate = $voidedAt->toDateString();

            foreach ($allocations as $allocation) {
                $this->subledgerService->recordArAllocationReleased($allocation, $userId, 'delete', $voidDate);
            }

            $this->subledgerService->recordArPaymentVoided($payment, $userId, $voidDate);

            PaymentAllocation::query()
                ->where('payment_id', $payment->id)
                ->whereNull('voided_at')
                ->update([
                    'voided_at' => $voidedAt,
                    'voided_by' => $userId,
                    'void_reason' => 'Payment voided',
                    'updated_at' => $voidedAt,
                ]);

            $payment->voided_at = $voidedAt;
            $payment->voided_by = $userId;
            $payment->void_reason = 'Payment voided';
            $payment->save();
            $this->bankTransactionService->voidArPayment($payment, $userId);

            foreach ($invoiceIds as $invoiceId) {
                $invoice = ArInvoice::query()
                    ->whereKey($invoiceId)
                    ->lockForUpdate()
                    ->first();

                if (! $invoice) {
                    continue;
                }

                $paid = (int) PaymentAllocation::query()
                    ->where('allocatable_type', ArInvoice::class)
                    ->where('allocatable_id', $invoice->id)
                    ->whereNull('voided_at')
                    ->sum('amount_cents');

                $invoice->update([
                    'paid_total_cents' => $paid,
                    'balance_cents' => (int) $invoice->total_cents - $paid,
                ]);

                $this->allocationService->recalcStatus($invoice->fresh());
            }

            $this->auditLog->log('ar_payment.voided', $userId, $payment, [
                'allocation_count' => (int) $allocations->count(),
            ]);
        });
    }
}
