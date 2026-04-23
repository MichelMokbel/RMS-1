<?php

namespace App\Services\AP;

use App\Models\ApPayment;
use App\Models\ApPaymentAllocation;
use App\Models\SubledgerEntry;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\Banking\BankTransactionService;
use App\Services\Ledger\SubledgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApPaymentVoidService
{
    public function __construct(
        protected SubledgerService $subledgerService,
        protected ApInvoiceStatusService $statusService,
        protected BankTransactionService $bankTransactionService,
        protected AccountingAuditLogService $auditLog
    ) {
    }

    public function void(ApPayment $payment, int $userId): ApPayment
    {
        return DB::transaction(function () use ($payment, $userId) {
            // Guard: check clearance before acquiring payment lock to fail fast.
            if ($payment->payment_method === 'cheque') {
                $activeClearance = \App\Models\ApChequeClearance::where('ap_payment_id', $payment->id)
                    ->whereNull('voided_at')
                    ->lockForUpdate()
                    ->first();
                if ($activeClearance) {
                    throw ValidationException::withMessages([
                        'payment' => __('Cannot void a cheque payment that has already been cleared. Void the cheque clearance first.'),
                    ]);
                }
            }

            $payment = ApPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->voided_at) {
                throw ValidationException::withMessages(['payment' => __('Payment is already voided.')]);
            }

            $allocations = ApPaymentAllocation::where('payment_id', $payment->id)
                ->whereNull('voided_at')
                ->lockForUpdate()
                ->get();

            $invoiceIds = $allocations->pluck('invoice_id')->unique()->values();

            foreach ($allocations as $allocation) {
                $allocation->voided_at = now();
                $allocation->voided_by = $userId;
                $allocation->save();

                $entry = SubledgerEntry::where('source_type', 'ap_payment_allocation')
                    ->where('source_id', $allocation->id)
                    ->where('event', 'apply')
                    ->first();

                if ($entry) {
                    $this->subledgerService->recordReversalForEntry(
                        $entry,
                        'void',
                        'AP payment allocation void '.$allocation->id,
                        now()->toDateString(),
                        $userId
                    );
                }
            }

            $paymentEntry = SubledgerEntry::where('source_type', 'ap_payment')
                ->where('source_id', $payment->id)
                ->where('event', 'payment')
                ->first();

            if ($paymentEntry) {
                $this->subledgerService->recordReversalForEntry(
                    $paymentEntry,
                    'void',
                    'AP payment void '.$payment->id,
                    now()->toDateString(),
                    $userId
                );
            }

            $payment->voided_at = now();
            $payment->voided_by = $userId;
            $payment->save();
            $this->bankTransactionService->voidApPayment($payment, $userId);
            $this->auditLog->log('ap_payment.voided', $userId, $payment, [
                'allocation_count' => $allocations->count(),
            ], (int) ($payment->company_id ?? 0) ?: null);

            foreach ($invoiceIds as $invoiceId) {
                $invoice = \App\Models\ApInvoice::whereKey($invoiceId)->lockForUpdate()->first();
                if ($invoice) {
                    $this->statusService->recalcStatus($invoice);
                }
            }

            return $payment->fresh(['allocations']);
        });
    }
}
