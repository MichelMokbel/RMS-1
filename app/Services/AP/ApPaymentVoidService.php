<?php

namespace App\Services\AP;

use App\Models\ApPayment;
use App\Models\ApPaymentAllocation;
use App\Models\SubledgerEntry;
use App\Services\Ledger\SubledgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApPaymentVoidService
{
    public function __construct(
        protected SubledgerService $subledgerService,
        protected ApInvoiceStatusService $statusService
    ) {
    }

    public function void(ApPayment $payment, int $userId): ApPayment
    {
        return DB::transaction(function () use ($payment, $userId) {
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
