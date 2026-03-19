<?php

namespace App\Services\AR;

use App\Models\ArInvoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\SubledgerEntry;
use App\Services\Ledger\SubledgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ArPaymentDeleteService
{
    public function __construct(
        protected ArAllocationService $allocationService,
        protected SubledgerService $subledgerService,
    ) {
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

            foreach ($allocations as $allocation) {
                $entry = SubledgerEntry::query()
                    ->where('source_type', 'ar_payment_allocation')
                    ->where('source_id', $allocation->id)
                    ->where('event', 'apply')
                    ->first();

                if ($entry) {
                    $this->subledgerService->recordReversalForEntry(
                        $entry,
                        'delete',
                        'AR payment allocation delete '.$allocation->id,
                        now()->toDateString(),
                        $userId
                    );
                }
            }

            $paymentEntry = SubledgerEntry::query()
                ->where('source_type', 'ar_payment')
                ->where('source_id', $payment->id)
                ->where('event', 'payment')
                ->first();

            if ($paymentEntry) {
                $this->subledgerService->recordReversalForEntry(
                    $paymentEntry,
                    'delete',
                    'AR payment delete '.$payment->id,
                    now()->toDateString(),
                    $userId
                );
            }

            PaymentAllocation::query()
                ->where('payment_id', $payment->id)
                ->delete();

            $payment->delete();

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
        });
    }
}
