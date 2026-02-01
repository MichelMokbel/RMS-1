<?php

namespace App\Services\AR;

use App\Events\InvoiceIssued;
use App\Models\ArInvoice;
use App\Models\ArInvoiceItem;
use App\Models\Customer;
use App\Models\MealSubscriptionOrder;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentTerm;
use App\Models\Sale;
use App\Services\Sequences\DocumentSequenceService;
use App\Services\Ledger\SubledgerService;
use App\Support\Money\MinorUnits;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ArInvoiceService
{
    public function __construct(
        protected DocumentSequenceService $sequences,
        protected SubledgerService $subledgerService
    )
    {
    }

    public function createDraft(
        int $branchId,
        int $customerId,
        array $items,
        int $actorId,
        ?string $currency = null,
        ?string $posReference = null,
        ?string $source = null,
        ?int $sourceSaleId = null,
        string $type = 'invoice',
        ?string $issueDate = null,
        ?string $paymentType = null,
        ?int $paymentTermId = null,
        int $paymentTermDays = 0,
        ?int $salesPersonId = null,
        ?string $lpoReference = null,
        string $invoiceDiscountType = 'fixed',
        int $invoiceDiscountValue = 0,
    ): ArInvoice
    {
        if ($branchId <= 0) {
            $branchId = 1;
        }
        if ($customerId <= 0) {
            throw ValidationException::withMessages(['customer_id' => __('Customer is required.')]);
        }

        $customer = Customer::find($customerId);
        if (! $customer) {
            throw ValidationException::withMessages(['customer_id' => __('Customer not found.')]);
        }

        return DB::transaction(function () use ($branchId, $customer, $items, $actorId, $currency, $posReference, $source, $sourceSaleId, $type, $issueDate, $paymentType, $paymentTermId, $paymentTermDays, $salesPersonId, $lpoReference, $invoiceDiscountType, $invoiceDiscountValue) {
            $currency = $currency ?: (string) config('pos.currency');
            $source = $source ?: 'dashboard';
            $termDays = max(0, $paymentTermDays);
            if ($paymentTermId) {
                $term = PaymentTerm::find($paymentTermId);
                if ($term) {
                    $termDays = (int) $term->days;
                }
            }

            $invoice = ArInvoice::create([
                'branch_id' => $branchId,
                'customer_id' => $customer->id,
                'source_sale_id' => $sourceSaleId,
                'pos_reference' => $posReference,
                'source' => $source,
                'type' => $type,
                'invoice_number' => null,
                'status' => 'draft',
                'payment_type' => $paymentType,
                'payment_term_id' => $paymentTermId,
                'payment_term_days' => $termDays,
                'sales_person_id' => $salesPersonId,
                'lpo_reference' => $lpoReference,
                'issue_date' => $issueDate,
                'due_date' => $issueDate ? now()->parse($issueDate)->addDays($termDays)->toDateString() : null,
                'currency' => $currency,
                'subtotal_cents' => 0,
                'discount_total_cents' => 0,
                'invoice_discount_type' => $invoiceDiscountType === 'percent' ? 'percent' : 'fixed',
                'invoice_discount_value' => max(0, $invoiceDiscountValue),
                'invoice_discount_cents' => 0,
                'tax_total_cents' => 0,
                'total_cents' => 0,
                'paid_total_cents' => 0,
                'balance_cents' => 0,
                'notes' => null,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);

            foreach ($items as $row) {
                $desc = (string) ($row['description'] ?? '');
                $qty = (string) ($row['qty'] ?? '1.000');
                $unit = (int) ($row['unit_price_cents'] ?? 0);
                $discount = (int) ($row['discount_cents'] ?? 0);
                $tax = (int) ($row['tax_cents'] ?? 0);
                $line = (int) ($row['line_total_cents'] ?? ($unit - $discount + $tax));

                if (trim($desc) === '') {
                    continue;
                }

                ArInvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => $desc,
                    'qty' => $qty,
                    'unit' => $row['unit'] ?? null,
                    'unit_price_cents' => $unit,
                    'discount_cents' => $discount,
                    'tax_cents' => $tax,
                    'line_total_cents' => $line,
                    'line_notes' => $row['line_notes'] ?? null,
                    'sellable_type' => $row['sellable_type'] ?? null,
                    'sellable_id' => $row['sellable_id'] ?? null,
                    'name_snapshot' => $row['name_snapshot'] ?? null,
                    'sku_snapshot' => $row['sku_snapshot'] ?? null,
                    'meta' => $row['meta'] ?? null,
                ]);
            }

            $this->recalc($invoice->fresh(['items']));

            return $invoice->fresh(['items']);
        });
    }

    public function createFromSale(Sale $sale, int $actorId, ?string $notes = null): ArInvoice
    {
        if (! $sale->customer_id) {
            throw ValidationException::withMessages(['customer_id' => __('Customer is required to invoice a sale.')]);
        }

        $sale = $sale->fresh(['items']);
        $items = $sale->items->map(function ($i) {
            return [
                'description' => $i->name_snapshot,
                'qty' => (string) $i->qty,
                'unit_price_cents' => (int) $i->unit_price_cents,
                'discount_cents' => (int) $i->discount_cents,
                'tax_cents' => (int) $i->tax_cents,
                'line_total_cents' => (int) $i->line_total_cents,
                'sellable_type' => $i->sellable_type,
                'sellable_id' => $i->sellable_id,
                'name_snapshot' => $i->name_snapshot,
                'sku_snapshot' => $i->sku_snapshot,
                'meta' => $i->meta,
            ];
        })->all();

        $invoice = $this->createDraft(
            branchId: (int) $sale->branch_id,
            customerId: (int) $sale->customer_id,
            items: $items,
            actorId: $actorId,
            currency: (string) ($sale->currency ?: config('pos.currency')),
            posReference: $sale->pos_reference ?? null,
            source: $sale->source === 'pos' ? 'pos' : 'dashboard',
            sourceSaleId: (int) $sale->id,
            type: 'invoice',
        );

        if ($notes) {
            $invoice->update(['notes' => $notes, 'updated_by' => $actorId]);
        }

        return $invoice->fresh(['items']);
    }

    public function createFromOrder(Order $order, int $actorId, ?string $notes = null): ArInvoice
    {
        if (! $order->customer_id) {
            throw ValidationException::withMessages(['customer_id' => __('Customer is required to invoice an order.')]);
        }

        if ($order->isInvoiced()) {
            throw ValidationException::withMessages(['order' => __('Order has already been invoiced.')]);
        }

        $order = $order->fresh(['items.menuItem', 'customer']);
        $scale = MinorUnits::posScale();

        // Check if this is a daily dish or subscription order with flat pricing
        $isDailyDishOrSubscription = $order->is_daily_dish || $order->source === 'Subscription';

        if ($isDailyDishOrSubscription) {
            // For daily dish and subscription orders, use the order total as a single line item
            $items = $this->buildDailyDishInvoiceItems($order, $scale);
        } else {
            // For regular orders, use individual item prices
            $items = $order->items->map(function ($item) use ($scale) {
                $unitPriceCents = MinorUnits::parse((string) ($item->unit_price ?? '0'), $scale);
                $discountCents = MinorUnits::parse((string) ($item->discount_amount ?? '0'), $scale);
                $qtyMilli = MinorUnits::parseQtyMilli((string) ($item->quantity ?? '1'));
                $lineSubtotal = MinorUnits::mulQty($unitPriceCents, $qtyMilli);
                $lineTotalCents = max(0, $lineSubtotal - $discountCents);

                $menuItem = $item->menuItem;

                return [
                    'description' => $item->description_snapshot ?: ($menuItem?->name ?? 'Item'),
                    'qty' => (string) $item->quantity,
                    'unit' => $menuItem?->unit ?? null,
                    'unit_price_cents' => $unitPriceCents,
                    'discount_cents' => $discountCents,
                    'tax_cents' => 0,
                    'line_total_cents' => $lineTotalCents,
                    'sellable_type' => $menuItem ? MenuItem::class : null,
                    'sellable_id' => $menuItem?->id,
                    'name_snapshot' => $menuItem?->name ?? $item->description_snapshot,
                    'sku_snapshot' => $menuItem?->code ?? null,
                    'meta' => null,
                ];
            })->all();
        }

        return DB::transaction(function () use ($order, $items, $actorId, $notes) {
            $invoice = $this->createDraft(
                branchId: (int) $order->branch_id,
                customerId: (int) $order->customer_id,
                items: $items,
                actorId: $actorId,
                currency: (string) config('pos.currency'),
                posReference: $order->order_number,
                source: 'order',
                sourceSaleId: null,
                type: 'invoice',
            );

            // Link invoice to order
            $invoice->update(['source_order_id' => $order->id, 'updated_by' => $actorId]);

            // Mark order as invoiced
            $order->update(['invoiced_at' => now()]);

            if ($notes) {
                $invoice->update(['notes' => $notes, 'updated_by' => $actorId]);
            }

            return $invoice->fresh(['items']);
        });
    }

    /**
     * Build invoice items for daily dish and subscription orders.
     * These orders use flat pricing stored in order.total_amount.
     */
    protected function buildDailyDishInvoiceItems(Order $order, int $scale): array
    {
        $totalAmountCents = MinorUnits::parse((string) ($order->total_amount ?? '0'), $scale);
        $discountCents = MinorUnits::parse((string) ($order->order_discount_amount ?? '0'), $scale);

        // Build a descriptive line item
        $isSubscription = $order->source === 'Subscription';
        $portionType = $order->daily_dish_portion_type;
        $portionQty = $order->daily_dish_portion_quantity;

        // Create description based on order type
        if ($isSubscription) {
            $description = __('Daily Dish Subscription - :date', [
                'date' => $order->scheduled_date?->format('d M Y') ?? '',
            ]);
        } else {
            $portionLabel = match ($portionType) {
                'plate' => __('Plate'),
                'half_tray' => __('Half Tray'),
                'full_tray' => __('Full Tray'),
                default => ucfirst(str_replace('_', ' ', $portionType ?? 'Plate')),
            };
            $description = $portionQty && $portionQty > 1
                ? __('Daily Dish - :portion (x:qty)', ['portion' => $portionLabel, 'qty' => $portionQty])
                : __('Daily Dish - :portion', ['portion' => $portionLabel]);
        }

        // Add item details as sub-description (name only, no code)
        $itemNames = $order->items->map(function ($item) {
            $name = $item->menuItem?->name ?? trim(preg_replace('/^\S+\s+/', '', $item->description_snapshot ?? '')) ?: 'Item';
            $qty = (float) $item->quantity;
            return $qty > 1 ? "{$name} x{$qty}" : $name;
        })->filter()->take(5)->implode(', ');

        if ($itemNames) {
            $description .= ' (' . $itemNames . ')';
            if ($order->items->count() > 5) {
                $description .= ' +' . ($order->items->count() - 5) . ' more';
            }
        }

        // For subscription orders with zero total, we may need to calculate from the subscription
        if ($isSubscription && $totalAmountCents === 0) {
            // Check if there's a subscription linked to get the per-meal price
            $subscriptionOrder = MealSubscriptionOrder::where('order_id', $order->id)->first();
            if ($subscriptionOrder) {
                $subscription = \App\Models\MealSubscription::find($subscriptionOrder->subscription_id);
                if ($subscription) {
                    // Calculate per-meal price from subscription
                    $perMealPrice = $this->calculateSubscriptionMealPrice($subscription);
                    $totalAmountCents = $perMealPrice;
                }
            }
        }

        // Return as single line item with the order total
        return [
            [
                'description' => $description,
                'qty' => '1',
                'unit' => null,
                'unit_price_cents' => $totalAmountCents + $discountCents, // Add back discount for gross price
                'discount_cents' => $discountCents,
                'tax_cents' => 0,
                'line_total_cents' => $totalAmountCents,
                'sellable_type' => null,
                'sellable_id' => null,
                'name_snapshot' => $description,
                'sku_snapshot' => null,
                'meta' => [
                    'order_id' => $order->id,
                    'is_daily_dish' => $order->is_daily_dish,
                    'is_subscription' => $isSubscription,
                    'portion_type' => $portionType,
                    'portion_quantity' => $portionQty,
                ],
            ],
        ];
    }

    /**
     * Calculate the per-meal price for a subscription order.
     */
    protected function calculateSubscriptionMealPrice(\App\Models\MealSubscription $subscription): int
    {
        $scale = MinorUnits::posScale();

        // Get subscription pricing from config
        $planPricing = config('pricing.meal_plan.plan_prices', []);
        $planMealsTotal = $subscription->plan_meals_total;

        // Check if we have plan-based pricing (20 or 26 meal plans)
        if ($planMealsTotal && isset($planPricing[(string) $planMealsTotal])) {
            // Plan price is per-meal already
            $perMealPrice = (float) $planPricing[(string) $planMealsTotal];
            return MinorUnits::parse((string) $perMealPrice, $scale);
        }

        // Fallback to base pricing based on subscription options
        $basePricing = config('pricing.meal_plan.base_prices', []);
        $includeSalad = (bool) $subscription->include_salad;
        $includeDessert = (bool) $subscription->include_dessert;

        if ($includeSalad && $includeDessert) {
            $price = (float) ($basePricing['main_plus_both'] ?? 65.0);
        } elseif ($includeSalad || $includeDessert) {
            $price = (float) ($basePricing['main_plus_one'] ?? 55.0);
        } else {
            $price = (float) ($basePricing['main_only'] ?? 50.0);
        }

        return MinorUnits::parse((string) $price, $scale);
    }

    public function issue(ArInvoice $invoice, int $actorId): ArInvoice
    {
        $invoice = $invoice->fresh(['items', 'customer']);

        if (! $invoice->isDraft()) {
            throw ValidationException::withMessages(['invoice' => __('Invoice is not draft.')]);
        }

        if ($invoice->items()->count() === 0) {
            throw ValidationException::withMessages(['items' => __('Add at least one invoice item.')]);
        }

        return DB::transaction(function () use ($invoice, $actorId) {
            $locked = ArInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages(['invoice' => __('Invoice is not draft.')]);
            }

            $this->recalc($locked->fresh(['items']));

            $year = now()->format('Y');
            $seq = $this->sequences->next('ar_invoice', (int) $locked->branch_id, $year);
            $locked->invoice_number = 'INV'.$year.'-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);

            $issueDate = $locked->issue_date ? $locked->issue_date->toDateString() : now()->toDateString();
            $termsDays = (int) ($locked->payment_term_days ?? 0);
            if ($termsDays <= 0 && $locked->payment_term_id) {
                $term = PaymentTerm::find($locked->payment_term_id);
                $termsDays = $term ? (int) $term->days : 0;
            }
            if ($termsDays <= 0) {
                $termsDays = (int) ($locked->customer?->credit_terms_days ?? 0);
            }
            $dueDate = $termsDays > 0 ? now()->parse($issueDate)->addDays($termsDays)->toDateString() : $issueDate;

            $locked->issue_date = $issueDate;
            $locked->due_date = $locked->due_date ?: $dueDate;
            $locked->status = 'issued';
            $locked->updated_by = $actorId;
            $locked->save();

            $this->subledgerService->recordArInvoiceIssued($locked->fresh(), $actorId);

            InvoiceIssued::dispatch($locked->fresh());

            // Auto-allocate subscription payments if this is a subscription order invoice
            $issued = $locked->fresh(['items']);
            $this->autoAllocateSubscriptionPayment($issued, $actorId);

            return $issued->fresh(['items']);
        });
    }

    /**
     * Auto-allocate advance payments to subscription order invoices.
     * Only applies to invoices linked to subscription orders.
     */
    protected function autoAllocateSubscriptionPayment(ArInvoice $invoice, int $actorId): void
    {
        // Only process if invoice has a source order
        if (! $invoice->source_order_id) {
            return;
        }

        // Check if this order is a subscription order
        $isSubscriptionOrder = MealSubscriptionOrder::where('order_id', $invoice->source_order_id)->exists();
        if (! $isSubscriptionOrder) {
            return;
        }

        // Find unallocated advance payments for this customer (FIFO by received_at)
        $customerId = $invoice->customer_id;
        $branchId = $invoice->branch_id;
        $invoiceBalance = (int) $invoice->balance_cents;

        if ($invoiceBalance <= 0) {
            return;
        }

        // Get payments with unallocated balance
        $payments = Payment::query()
            ->where('customer_id', $customerId)
            ->where('branch_id', $branchId)
            ->where('source', 'ar')
            ->whereNull('voided_at')
            ->orderBy('received_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $remainingBalance = $invoiceBalance;

        foreach ($payments as $payment) {
            if ($remainingBalance <= 0) {
                break;
            }

            $unallocated = $payment->unallocatedCents();
            if ($unallocated <= 0) {
                continue;
            }

            // Check currency and branch match
            if ($payment->currency && $invoice->currency && $payment->currency !== $invoice->currency) {
                continue;
            }

            $allocateAmount = min($unallocated, $remainingBalance);
            if ($allocateAmount <= 0) {
                continue;
            }

            // Create allocation
            PaymentAllocation::create([
                'payment_id' => $payment->id,
                'allocatable_type' => ArInvoice::class,
                'allocatable_id' => $invoice->id,
                'amount_cents' => $allocateAmount,
            ]);

            $remainingBalance -= $allocateAmount;

            // Record subledger entry for advance applied
            $allocation = PaymentAllocation::query()
                ->where('payment_id', $payment->id)
                ->where('allocatable_id', $invoice->id)
                ->where('allocatable_type', ArInvoice::class)
                ->whereNull('voided_at')
                ->latest('id')
                ->first();

            if ($allocation) {
                $this->subledgerService->recordArAdvanceApplied($allocation->fresh(['payment']), $actorId);
            }
        }

        // Recalculate invoice totals and status
        if ($remainingBalance < $invoiceBalance) {
            $this->recalc($invoice);
            $invoice = $invoice->fresh();

            // Update status based on balance
            if ($invoice->balance_cents === 0) {
                $invoice->update(['status' => 'paid']);
            } elseif ($invoice->paid_total_cents > 0) {
                $invoice->update(['status' => 'partially_paid']);
            }
        }
    }

    public function recalc(ArInvoice $invoice): ArInvoice
    {
        return DB::transaction(function () use ($invoice) {
            $invoice = $invoice->fresh(['items', 'paymentAllocations']);

            $subtotal = 0;
            $discount = 0;
            $tax = 0;
            $total = 0;

            foreach ($invoice->items as $item) {
                $qtyMilli = MinorUnits::parseQtyMilli((string) $item->qty);
                $lineSubtotal = MinorUnits::mulQty((int) $item->unit_price_cents, $qtyMilli);
                $subtotal += $lineSubtotal;
                $discount += (int) $item->discount_cents;
                $tax += (int) $item->tax_cents;
                $total += (int) $item->line_total_cents;
            }

            $invoiceDiscount = 0;
            $discountType = $invoice->invoice_discount_type ?? 'fixed';
            $discountValue = (int) ($invoice->invoice_discount_value ?? 0);
            if ($discountType === 'percent') {
                $invoiceDiscount = MinorUnits::percentBps(max(0, $subtotal - $discount), $discountValue);
            } else {
                $invoiceDiscount = $discountValue;
            }
            $invoiceDiscount = max(0, min($invoiceDiscount, $total));
            $total = $total - $invoiceDiscount;
            if ($invoice->isCreditNote()) {
                $total = min(0, $total);
            } else {
                $total = max(0, $total);
            }

            $paid = (int) $invoice->paymentAllocations()->sum('amount_cents');
            $balance = $total - $paid;

            $invoice->update([
                'subtotal_cents' => $subtotal,
                'discount_total_cents' => $discount + $invoiceDiscount,
                'invoice_discount_cents' => $invoiceDiscount,
                'tax_total_cents' => $tax,
                'total_cents' => $total,
                'paid_total_cents' => $paid,
                'balance_cents' => $balance,
            ]);

            return $invoice->fresh();
        });
    }
}

