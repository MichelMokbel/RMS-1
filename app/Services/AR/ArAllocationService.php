<?php

namespace App\Services\AR;

use App\Models\ArInvoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Services\Ledger\SubledgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ArAllocationService
{
    public function __construct(
        protected ArInvoiceService $invoices,
        protected SubledgerService $subledgerService
    )
    {
    }

    /**
     * Create an AR payment and allocate it to one invoice (partial/full).
     *
     * @return array{payment: Payment, allocated_cents: int, remainder_cents: int}
     */
    public function createPaymentAndAllocate(array $payload, int $actorId): array
    {
        $invoiceId = (int) ($payload['invoice_id'] ?? 0);
        $invoice = ArInvoice::whereKey($invoiceId)->first();
        if (! $invoice) {
            throw ValidationException::withMessages(['invoice_id' => __('Invoice not found.')]);
        }

        $amount = (int) ($payload['amount_cents'] ?? 0);
        if ($amount === 0) {
            throw ValidationException::withMessages(['amount_cents' => __('Amount is required.')]);
        }

        $method = (string) ($payload['method'] ?? 'bank');
        $currency = (string) ($payload['currency'] ?? ($invoice->currency ?: config('pos.currency')));

        return DB::transaction(function () use ($invoiceId, $amount, $method, $currency, $payload, $actorId) {
            $invoice = ArInvoice::whereKey($invoiceId)->lockForUpdate()->firstOrFail();
            if (! in_array($invoice->status, ['issued', 'partially_paid', 'paid'], true)) {
                throw ValidationException::withMessages(['invoice' => __('Invoice must be issued to accept payments.')]);
            }
            if ($invoice->status === 'paid' && $invoice->balance_cents === 0) {
                throw ValidationException::withMessages(['invoice' => __('Invoice is already paid.')]);
            }

            $this->invoices->recalc($invoice);
            $invoice = $invoice->fresh();

            $outstanding = (int) $invoice->balance_cents;
            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount_cents' => __('Payment amount must be positive.')]);
            }

            $allocated = min($amount, max(0, $outstanding));

            $payment = Payment::create([
                'branch_id' => $invoice->branch_id,
                'customer_id' => $invoice->customer_id,
                'source' => 'ar',
                'method' => $method,
                'amount_cents' => $amount,
                'currency' => $currency,
                'received_at' => $payload['received_at'] ?? now(),
                'reference' => $payload['reference'] ?? null,
                'notes' => $payload['notes'] ?? null,
                'created_by' => $actorId,
            ]);

            if ($allocated > 0) {
                PaymentAllocation::create([
                    'payment_id' => $payment->id,
                    'allocatable_type' => ArInvoice::class,
                    'allocatable_id' => $invoice->id,
                    'amount_cents' => $allocated,
                ]);
            }

            $this->invoices->recalc($invoice);
            $this->recalcStatus($invoice);

            $this->subledgerService->recordArPaymentReceived(
                $payment->fresh(),
                $allocated,
                $amount - $allocated,
                $actorId
            );

            return [
                'payment' => $payment->fresh(['allocations']),
                'allocated_cents' => $allocated,
                'remainder_cents' => $amount - $allocated,
            ];
        });
    }

    /**
     * Allocate a credit note to an invoice by creating a negative payment.
     */
    public function applyCreditNote(ArInvoice $creditNote, ArInvoice $targetInvoice, int $actorId): Payment
    {
        if (! $creditNote->isCreditNote()) {
            throw ValidationException::withMessages(['credit_note' => __('Invoice is not a credit note.')]);
        }
        if (! in_array($creditNote->status, ['issued', 'partially_paid', 'paid'], true)) {
            throw ValidationException::withMessages(['credit_note' => __('Credit note must be issued.')]);
        }
        if ($creditNote->customer_id !== $targetInvoice->customer_id) {
            throw ValidationException::withMessages(['credit_note' => __('Credit note customer must match invoice.')]);
        }

        $creditNote = $creditNote->fresh();
        $targetInvoice = $targetInvoice->fresh();

        $creditAvailable = abs((int) $creditNote->balance_cents);
        if ($creditAvailable <= 0) {
            throw ValidationException::withMessages(['credit_note' => __('No credit available.')]);
        }

        return DB::transaction(function () use ($creditNote, $targetInvoice, $creditAvailable, $actorId) {
            $target = ArInvoice::whereKey($targetInvoice->id)->lockForUpdate()->firstOrFail();
            $credit = ArInvoice::whereKey($creditNote->id)->lockForUpdate()->firstOrFail();

            $this->invoices->recalc($target);
            $this->invoices->recalc($credit);

            $target = $target->fresh();
            $credit = $credit->fresh();

            $apply = min((int) $target->balance_cents, abs((int) $credit->balance_cents));
            if ($apply <= 0) {
                throw ValidationException::withMessages(['credit_note' => __('Nothing to apply.')]);
            }

            // Represent credit note application as a "transfer" payment (amount 0),
            // with two allocations: +apply to the target invoice, -apply to the credit note.
            $payment = Payment::create([
                'branch_id' => $target->branch_id,
                'customer_id' => $target->customer_id,
                'source' => 'ar',
                'method' => 'voucher',
                'amount_cents' => 0,
                'currency' => $target->currency ?: (string) config('pos.currency'),
                'received_at' => now(),
                'notes' => __('Credit note allocation #:id', ['id' => $credit->id]),
                'created_by' => $actorId,
            ]);

            PaymentAllocation::create([
                'payment_id' => $payment->id,
                'allocatable_type' => ArInvoice::class,
                'allocatable_id' => $target->id,
                'amount_cents' => $apply,
            ]);

            // Reduce credit note balance by decreasing its "paid" (negative allocation).
            PaymentAllocation::create([
                'payment_id' => $payment->id,
                'allocatable_type' => ArInvoice::class,
                'allocatable_id' => $credit->id,
                'amount_cents' => -$apply,
            ]);

            $this->invoices->recalc($target);
            $this->invoices->recalc($credit);
            $this->recalcStatus($target->fresh());
            $this->recalcStatus($credit->fresh());

            return $payment->fresh(['allocations']);
        });
    }

    public function recalcStatus(ArInvoice $invoice): void
    {
        $invoice = $invoice->fresh();
        if ($invoice->status === 'voided') {
            return;
        }
        if ($invoice->status === 'draft') {
            return;
        }

        if ($invoice->balance_cents === 0) {
            $invoice->update(['status' => 'paid']);
            return;
        }

        if ($invoice->paid_total_cents !== 0) {
            $invoice->update(['status' => 'partially_paid']);
            return;
        }

        $invoice->update(['status' => 'issued']);
    }
}

