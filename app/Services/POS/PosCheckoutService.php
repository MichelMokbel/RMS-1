<?php

namespace App\Services\POS;

use App\Events\PaymentReceived;
use App\Events\SaleClosed;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Sale;
use App\Services\AR\ArInvoiceService;
use App\Services\Sales\SaleService;
use App\Services\Sequences\DocumentSequenceService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PosCheckoutService
{
    public function __construct(
        protected PosShiftService $shifts,
        protected SaleService $sales,
        protected DocumentSequenceService $sequences,
    ) {
    }

    /**
     * @param array $payments Each row: ['method' => 'cash|card|online|bank|voucher', 'amount_cents' => int]
     */
    public function checkout(Sale $sale, array $payments, int $actorId): Sale
    {
        $actor = \App\Models\User::find($actorId);
        $isAdmin = $actor?->hasRole('admin') ?? false;
        if ($actor) {
            Gate::forUser($actor)->authorize('sale.checkout', $sale);
        }

        if (! $sale->isOpen()) {
            throw ValidationException::withMessages(['sale' => __('Sale is not open.')]);
        }
        if ($sale->is_credit) {
            throw ValidationException::withMessages(['sale' => __('Credit sales must use credit checkout.')]);
        }

        $sale = $this->sales->recalc($sale->fresh(['items', 'paymentAllocations']));

        if ($sale->items()->count() === 0) {
            throw ValidationException::withMessages(['sale' => __('Add at least one item before checkout.')]);
        }

        $shift = $this->shifts->activeShiftFor($sale->branch_id, $actorId);
        if (! $shift && ! $isAdmin) {
            throw ValidationException::withMessages(['shift' => __('Active shift required to checkout.')]);
        }

        $due = (int) $sale->due_total_cents;
        if ($due <= 0) {
            throw ValidationException::withMessages(['sale' => __('Sale has no amount due.')]);
        }

        $totalPay = 0;
        foreach ($payments as $row) {
            $amount = (int) ($row['amount_cents'] ?? 0);
            if ($amount <= 0) {
                throw ValidationException::withMessages(['payments' => __('Payment amounts must be positive.')]);
            }
            $totalPay += $amount;
        }

        if ($totalPay !== $due) {
            throw ValidationException::withMessages([
                'payments' => __('Payment total must equal amount due.'),
            ]);
        }

        return DB::transaction(function () use ($sale, $payments, $actorId, $shift) {
            // Lock sale row for update so concurrent checkouts can't double-close it.
            $lockedSale = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if (! $lockedSale->isOpen()) {
                throw ValidationException::withMessages(['sale' => __('Sale is not open.')]);
            }

            $lockedSale->update([
                'pos_shift_id' => $shift?->id,
                'updated_by' => $actorId,
            ]);

            $currency = $lockedSale->currency ?: (string) config('pos.currency');
            $due = (int) $lockedSale->due_total_cents;

            $remaining = $due;
            foreach ($payments as $row) {
                $method = (string) ($row['method'] ?? 'cash');
                $amount = (int) ($row['amount_cents'] ?? 0);

                $payment = Payment::create([
                    'branch_id' => $lockedSale->branch_id,
                    'customer_id' => $lockedSale->customer_id,
                    'source' => 'pos',
                    'method' => $method,
                    'amount_cents' => $amount,
                    'currency' => $currency,
                    'received_at' => now(),
                    'reference' => $row['reference'] ?? null,
                    'notes' => $row['notes'] ?? null,
                    'created_by' => $actorId,
                ]);

                PaymentReceived::dispatch($payment);

                $alloc = min($remaining, $amount);
                $remaining -= $alloc;

                PaymentAllocation::create([
                    'payment_id' => $payment->id,
                    'allocatable_type' => Sale::class,
                    'allocatable_id' => $lockedSale->id,
                    'amount_cents' => $alloc,
                ]);
            }

            $lockedSale = $this->sales->recalc($lockedSale);

            if ($lockedSale->due_total_cents !== 0) {
                throw ValidationException::withMessages(['sale' => __('Sale payment allocation incomplete.')]);
            }

            if (! $lockedSale->sale_number) {
                $year = now()->format('Y');
                $seq = $this->sequences->next('sale', (int) $lockedSale->branch_id, $year);
                $lockedSale->sale_number = 'SAL'.$year.'-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
            }

            $lockedSale->status = 'closed';
            $lockedSale->closed_at = now();
            $lockedSale->closed_by = $actorId;
            $lockedSale->save();

            SaleClosed::dispatch($lockedSale->fresh(['items', 'paymentAllocations']));

            return $lockedSale->fresh(['items', 'paymentAllocations']);
        });
    }

    /**
     * Quick-pay: single payment for full due amount (e.g. Cash F1, Card F12).
     */
    public function quickPay(Sale $sale, string $method, int $actorId): Sale
    {
        $sale = $this->sales->recalc($sale->fresh(['items', 'paymentAllocations']));
        $due = (int) $sale->due_total_cents;
        if ($due <= 0) {
            throw ValidationException::withMessages(['sale' => __('Sale has no amount due.')]);
        }

        return $this->checkout($sale, [
            ['method' => $method, 'amount_cents' => $due],
        ], $actorId);
    }

    public function checkoutCredit(Sale $sale, int $actorId): Sale
    {
        $actor = \App\Models\User::find($actorId);
        $isAdmin = $actor?->hasRole('admin') ?? false;
        if ($actor) {
            Gate::forUser($actor)->authorize('sale.checkout', $sale);
        }

        if (! $sale->isOpen()) {
            throw ValidationException::withMessages(['sale' => __('Sale is not open.')]);
        }

        $sale = $this->sales->recalc($sale->fresh(['items', 'paymentAllocations']));

        if ($sale->items()->count() === 0) {
            throw ValidationException::withMessages(['sale' => __('Add at least one item before checkout.')]);
        }

        if (! $sale->customer_id) {
            throw ValidationException::withMessages(['customer_id' => __('Customer is required for credit sales.')]);
        }

        $shift = $this->shifts->activeShiftFor($sale->branch_id, $actorId);
        if (! $shift && ! $isAdmin) {
            throw ValidationException::withMessages(['shift' => __('Active shift required to checkout.')]);
        }

        return DB::transaction(function () use ($sale, $actorId, $shift) {
            $lockedSale = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if (! $lockedSale->isOpen()) {
                throw ValidationException::withMessages(['sale' => __('Sale is not open.')]);
            }

            $lockedSale->update([
                'pos_shift_id' => $shift?->id,
                'updated_by' => $actorId,
            ]);

            /** @var ArInvoiceService $invoices */
            $invoices = app(ArInvoiceService::class);
            $invoice = $invoices->createFromSale($lockedSale->fresh(['items']), $actorId);
            $invoice = $invoices->issue($invoice, $actorId);

            if (! $lockedSale->sale_number) {
                $year = now()->format('Y');
                $seq = $this->sequences->next('sale', (int) $lockedSale->branch_id, $year);
                $lockedSale->sale_number = 'SAL'.$year.'-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
            }

            $lockedSale->update([
                'status' => 'closed',
                'is_credit' => true,
                'credit_invoice_id' => $invoice->id,
                'closed_at' => now(),
                'closed_by' => $actorId,
            ]);

            SaleClosed::dispatch($lockedSale->fresh(['items', 'paymentAllocations']));

            return $lockedSale->fresh(['items', 'paymentAllocations']);
        });
    }
}

