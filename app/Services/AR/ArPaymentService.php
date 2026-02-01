<?php

namespace App\Services\AR;

use App\Models\ArInvoice;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Services\Ledger\SubledgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ArPaymentService
{
    public function __construct(
        protected ArInvoiceService $invoices,
        protected ArAllocationService $allocations,
        protected SubledgerService $subledgerService,
    ) {
    }

    public function createAdvancePayment(
        int $customerId,
        int $branchId,
        int $amountCents,
        string $method,
        ?string $receivedAt,
        ?string $reference,
        ?string $notes,
        int $actorId
    ): Payment {
        if ($amountCents <= 0) {
            throw ValidationException::withMessages(['amount_cents' => __('Payment amount must be positive.')]);
        }

        $customer = Customer::find($customerId);
        if (! $customer) {
            throw ValidationException::withMessages(['customer_id' => __('Customer not found.')]);
        }

        return DB::transaction(function () use ($customer, $branchId, $amountCents, $method, $receivedAt, $reference, $notes, $actorId) {
            $payment = Payment::create([
                'branch_id' => $branchId,
                'customer_id' => $customer->id,
                'source' => 'ar',
                'method' => $method,
                'amount_cents' => $amountCents,
                'currency' => (string) config('pos.currency'),
                'received_at' => $receivedAt ?? now(),
                'reference' => $reference ?: null,
                'notes' => $notes ?: null,
                'created_by' => $actorId,
            ]);

            $this->subledgerService->recordArPaymentReceived($payment, 0, $amountCents, $actorId);

            return $payment->fresh();
        });
    }

    public function createPaymentWithAllocations(array $payload, int $actorId): Payment
    {
        $amount = (int) ($payload['amount_cents'] ?? 0);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount_cents' => __('Payment amount must be positive.')]);
        }

        $customerId = (int) ($payload['customer_id'] ?? 0);
        if ($customerId <= 0) {
            throw ValidationException::withMessages(['customer_id' => __('Customer is required.')]);
        }

        $customer = Customer::find($customerId);
        if (! $customer) {
            throw ValidationException::withMessages(['customer_id' => __('Customer not found.')]);
        }

        $branchId = (int) ($payload['branch_id'] ?? 0);
        if ($branchId <= 0) {
            $branchId = 1;
        }

        $method = (string) ($payload['method'] ?? 'bank');
        $currency = (string) ($payload['currency'] ?? config('pos.currency'));
        $receivedAt = $payload['received_at'] ?? now();
        $reference = $payload['reference'] ?? null;
        $notes = $payload['notes'] ?? null;
        $rows = $payload['allocations'] ?? [];

        usort($rows, function ($a, $b) {
            return (int) ($a['invoice_id'] ?? 0) <=> (int) ($b['invoice_id'] ?? 0);
        });

        return DB::transaction(function () use ($customer, $branchId, $amount, $method, $currency, $receivedAt, $reference, $notes, $rows, $actorId) {
            $payment = Payment::create([
                'branch_id' => $branchId,
                'customer_id' => $customer->id,
                'source' => 'ar',
                'method' => $method,
                'amount_cents' => $amount,
                'currency' => $currency,
                'received_at' => $receivedAt,
                'reference' => $reference,
                'notes' => $notes,
                'created_by' => $actorId,
            ]);

            $remaining = $amount;
            foreach ($rows as $row) {
                if ($remaining <= 0) {
                    break;
                }

                $invoiceId = (int) ($row['invoice_id'] ?? 0);
                if ($invoiceId <= 0) {
                    throw ValidationException::withMessages(['allocations' => __('Invoice not found.')]);
                }

                $requested = (int) ($row['amount_cents'] ?? 0);
                if ($requested <= 0) {
                    throw ValidationException::withMessages(['allocations' => __('Allocation amount must be positive.')]);
                }

                $invoice = ArInvoice::whereKey($invoiceId)->lockForUpdate()->first();
                if (! $invoice) {
                    throw ValidationException::withMessages(['allocations' => __('Invoice not found.')]);
                }
                if ($invoice->customer_id !== $customer->id) {
                    throw ValidationException::withMessages(['allocations' => __('Invoice customer must match payment customer.')]);
                }
                if (! in_array($invoice->status, ['issued', 'partially_paid', 'paid'], true)) {
                    throw ValidationException::withMessages(['allocations' => __('Invoice must be issued to accept payments.')]);
                }

                $this->invoices->recalc($invoice);
                $invoice = $invoice->fresh();
                $outstanding = (int) $invoice->balance_cents;
                if ($outstanding <= 0) {
                    continue;
                }

                if ($invoice->currency && $invoice->currency !== $currency) {
                    throw ValidationException::withMessages(['allocations' => __('Payment currency must match invoice currency.')]);
                }
                if ((int) $invoice->branch_id !== (int) $payment->branch_id) {
                    throw ValidationException::withMessages(['allocations' => __('Payment branch must match invoice branch.')]);
                }

                $apply = min($requested, $outstanding, $remaining);
                if ($apply <= 0) {
                    continue;
                }

                PaymentAllocation::create([
                    'payment_id' => $payment->id,
                    'allocatable_type' => ArInvoice::class,
                    'allocatable_id' => $invoice->id,
                    'amount_cents' => $apply,
                ]);

                $this->invoices->recalc($invoice);
                $this->allocations->recalcStatus($invoice->fresh());

                $remaining -= $apply;
            }

            $applied = (int) PaymentAllocation::query()
                ->where('payment_id', $payment->id)
                ->whereNull('voided_at')
                ->sum('amount_cents');

            $this->subledgerService->recordArPaymentReceived(
                $payment->fresh(),
                $applied,
                $amount - $applied,
                $actorId
            );

            return $payment->fresh(['allocations']);
        });
    }

    public function applyExistingPaymentToInvoice(int $paymentId, int $invoiceId, int $amountCents, int $actorId): PaymentAllocation
    {
        if ($amountCents <= 0) {
            throw ValidationException::withMessages(['amount_cents' => __('Allocation amount must be positive.')]);
        }

        return DB::transaction(function () use ($paymentId, $invoiceId, $amountCents, $actorId) {
            $payment = Payment::whereKey($paymentId)->lockForUpdate()->firstOrFail();
            $invoice = ArInvoice::whereKey($invoiceId)->lockForUpdate()->firstOrFail();

            if ($payment->source !== 'ar') {
                throw ValidationException::withMessages(['payment' => __('Payment must be an AR payment.')]);
            }
            if (! $payment->customer_id || $payment->customer_id !== $invoice->customer_id) {
                throw ValidationException::withMessages(['payment' => __('Payment customer must match invoice customer.')]);
            }
            if ($payment->currency && $invoice->currency && $payment->currency !== $invoice->currency) {
                throw ValidationException::withMessages(['payment' => __('Payment currency must match invoice currency.')]);
            }
            if ((int) $payment->branch_id !== (int) $invoice->branch_id) {
                throw ValidationException::withMessages(['payment' => __('Payment branch must match invoice branch.')]);
            }
            if (! in_array($invoice->status, ['issued', 'partially_paid'], true)) {
                throw ValidationException::withMessages(['invoice' => __('Invoice must be issued to accept payments.')]);
            }

            $this->invoices->recalc($invoice);
            $invoice = $invoice->fresh();
            $outstanding = (int) $invoice->balance_cents;
            if ($amountCents > $outstanding) {
                throw ValidationException::withMessages(['amount_cents' => __('Allocation exceeds invoice balance.')]);
            }

            $allocated = (int) PaymentAllocation::query()
                ->where('payment_id', $payment->id)
                ->whereNull('voided_at')
                ->lockForUpdate()
                ->sum('amount_cents');

            $remaining = (int) $payment->amount_cents - $allocated;
            if ($amountCents > $remaining) {
                throw ValidationException::withMessages(['amount_cents' => __('Allocation exceeds remaining payment amount.')]);
            }

            $allocation = PaymentAllocation::create([
                'payment_id' => $payment->id,
                'allocatable_type' => ArInvoice::class,
                'allocatable_id' => $invoice->id,
                'amount_cents' => $amountCents,
            ]);

            $this->invoices->recalc($invoice);
            $this->allocations->recalcStatus($invoice->fresh());
            $this->subledgerService->recordArAdvanceApplied($allocation->fresh(['payment']), $actorId);

            return $allocation->fresh(['payment']);
        });
    }
}
