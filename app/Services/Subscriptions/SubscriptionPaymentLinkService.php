<?php

namespace App\Services\Subscriptions;

use App\Models\MealSubscription;
use App\Models\MenuItem;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubscriptionPaymentLinkService
{
    /**
     * Link an existing payment to an existing subscription (Direction A).
     * Validates same customer, payment not voided, subscription not already linked.
     */
    public function linkPaymentToSubscription(Payment $payment, MealSubscription $subscription, int $actorId): void
    {
        if ($payment->customer_id !== $subscription->customer_id) {
            throw ValidationException::withMessages([
                'subscription' => __('Payment and subscription must belong to the same customer.'),
            ]);
        }

        if ($payment->voided_at !== null) {
            throw ValidationException::withMessages([
                'payment' => __('Cannot link a voided payment to a subscription.'),
            ]);
        }

        if ($subscription->source_payment_id !== null && $subscription->source_payment_id !== $payment->id) {
            throw ValidationException::withMessages([
                'subscription' => __('This subscription is already linked to a different payment.'),
            ]);
        }

        // Idempotent: same payment already linked and tracking already enabled
        if ($subscription->source_payment_id === $payment->id && $subscription->uses_invoice_tracking) {
            return;
        }

        $wasTrackingEnabled = (bool) $subscription->uses_invoice_tracking;

        $subscription->source_payment_id    = $payment->id;
        $subscription->uses_invoice_tracking = true;
        $subscription->save();

        // If tracking was just enabled for the first time, resync meals_used immediately
        // so that invoices issued before linking are counted correctly.
        if (! $wasTrackingEnabled) {
            $this->resyncMealsUsed($subscription);
        }
    }

    /**
     * Detect subscription plan purchases in the invoices allocated to a payment.
     * Returns array of ['menu_item_id' => int, 'plan_meals_total' => int] for each detected plan item.
     */
    public function detectPlanFromPayment(Payment $payment): array
    {
        if (! $payment->customer_id) {
            return [];
        }

        $planItemIds = config('subscriptions.plan_menu_item_ids', []);
        if (empty($planItemIds)) {
            return [];
        }

        // Flip to map sellable_id → plan_meals_total
        $idToMeals = array_flip($planItemIds); // [84 => 20, 94 => 26]

        $payment->loadMissing('allocations');

        $detected = [];

        foreach ($payment->allocations as $allocation) {
            $invoice = $allocation->allocatable;
            if (! $invoice) {
                continue;
            }

            $invoice->loadMissing('items');

            foreach ($invoice->items as $item) {
                if (
                    $item->sellable_type === MenuItem::class &&
                    isset($idToMeals[$item->sellable_id])
                ) {
                    $mealsTotal = (int) $idToMeals[$item->sellable_id];
                    $key = $item->sellable_id . '_' . $mealsTotal;
                    $detected[$key] = [
                        'menu_item_id'    => (int) $item->sellable_id,
                        'plan_meals_total' => $mealsTotal,
                    ];
                }
            }
        }

        return array_values($detected);
    }

    /**
     * Create a subscription from a payment (Direction B).
     * Caller provides all subscription fields in $payload.
     * Sets source_payment_id and uses_invoice_tracking=true after service creates the subscription.
     */
    public function createSubscriptionFromPayment(
        Payment $payment,
        array $payload,
        int $actorId,
        MealSubscriptionService $subscriptionService
    ): MealSubscription {
        if ($payment->voided_at !== null) {
            throw ValidationException::withMessages([
                'payment' => __('Cannot create a subscription from a voided payment.'),
            ]);
        }

        $sub = $subscriptionService->save($payload, null, $actorId);

        // MealSubscriptionService::save() does not handle these fields — set directly
        $sub->source_payment_id    = $payment->id;
        $sub->uses_invoice_tracking = true;
        $sub->save();

        return $sub->fresh(['days', 'pauses', 'customer', 'sourcePayment']);
    }

    /**
     * Recompute meals_used from invoiced records and sync the stored counter.
     *
     * When source_payment_id is set (payment-anchored subscriptions):
     *   Every non-voided, non-credit-note invoice allocated to source_payment_id = 1 meal.
     *   This is authoritative regardless of item metadata — it handles auto-generated invoices,
     *   manually created MI-000084/94 invoices, and imported invoices with no metadata.
     *
     * When source_payment_id is null (legacy metadata-only subscriptions):
     *   Falls back to subscription_id in item meta (primary) and the order chain (fallback).
     */
    public function resyncMealsUsed(MealSubscription $subscription): int
    {
        if ($subscription->source_payment_id) {
            // Payment-anchored: sum qty of subscription items across three identification paths.

            // (B) Items tagged meta.subscription_id = this subscription — sum qty directly
            $metaItemsQuery = \App\Models\ArInvoiceItem::query()
                ->whereJsonContains('meta->is_subscription', true)
                ->where(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.subscription_id'))"), (string) $subscription->id)
                ->whereHas('invoice', fn ($q) => $q->whereNull('voided_at')->where('type', '!=', 'credit_note'));

            $byMetaInvoiceIds = $metaItemsQuery->pluck('invoice_id')->unique();
            $byMetaQty        = (int) (clone $metaItemsQuery)->sum('qty');

            // (A) Invoices allocated to the subscription's source payment, not already in (B)
            $byPaymentOnlyIds = \App\Models\ArInvoice::query()
                ->whereNull('voided_at')
                ->where('type', '!=', 'credit_note')
                ->whereHas('paymentAllocations', fn ($q) => $q->where('payment_id', $subscription->source_payment_id))
                ->when($byMetaInvoiceIds->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $byMetaInvoiceIds->all()))
                ->pluck('id');

            $byPaymentQty = 0;
            if ($byPaymentOnlyIds->isNotEmpty()) {
                $planItemIds = array_values(config('subscriptions.plan_menu_item_ids', []));
                $qty = (int) \App\Models\ArInvoiceItem::query()
                    ->whereIn('invoice_id', $byPaymentOnlyIds)
                    ->where(function ($q) use ($planItemIds) {
                        $q->whereJsonContains('meta->is_subscription', true);
                        if ($planItemIds) {
                            $q->orWhere(fn ($q2) => $q2
                                ->where('sellable_type', \App\Models\MenuItem::class)
                                ->whereIn('sellable_id', $planItemIds));
                        }
                    })
                    ->sum('qty');
                // Fall back to 1 per invoice when no subscription items are identifiable
                $byPaymentQty = $qty > 0 ? $qty : $byPaymentOnlyIds->count();
            }

            // (C) Invoices linked via subscription order chain, not already counted
            $orderIds = $subscription->subscriptionOrders()->pluck('order_id');
            $byOrderChainQty = 0;
            if ($orderIds->isNotEmpty()) {
                $alreadyCounted = $byMetaInvoiceIds->merge($byPaymentOnlyIds)->unique();
                $byOrderChainIds = \App\Models\ArInvoice::query()
                    ->whereNull('voided_at')
                    ->where('type', '!=', 'credit_note')
                    ->whereIn('source_order_id', $orderIds)
                    ->when($alreadyCounted->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $alreadyCounted->all()))
                    ->pluck('id');

                if ($byOrderChainIds->isNotEmpty()) {
                    $planItemIds = array_values(config('subscriptions.plan_menu_item_ids', []));
                    $qty = (int) \App\Models\ArInvoiceItem::query()
                        ->whereIn('invoice_id', $byOrderChainIds)
                        ->where(function ($q) use ($planItemIds) {
                            $q->whereJsonContains('meta->is_subscription', true);
                            if ($planItemIds) {
                                $q->orWhere(fn ($q2) => $q2
                                    ->where('sellable_type', \App\Models\MenuItem::class)
                                    ->whereIn('sellable_id', $planItemIds));
                            }
                        })
                        ->sum('qty');
                    $byOrderChainQty = $qty > 0 ? $qty : $byOrderChainIds->count();
                }
            }

            $total = $byMetaQty + $byPaymentQty + $byOrderChainQty;
        } else {
            // Metadata-anchored: no payment link, derive from subscription item metadata.

            // Primary: items tagged with subscription_id in meta (auto-generated, Step 5+)
            $metaItems = \App\Models\ArInvoiceItem::query()
                ->whereJsonContains('meta->is_subscription', true)
                ->where(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.subscription_id'))"), (string) $subscription->id)
                ->whereHas('invoice', fn ($q) => $q->whereNull('voided_at'));

            $metaInvoiceIds = $metaItems->pluck('invoice_id')->unique();
            $primaryCount   = (int) (clone $metaItems)->sum('qty');

            // Fallback: items linked via order chain (pre-Step-5 invoices without subscription_id in meta)
            $orderIds = $subscription->subscriptionOrders()->pluck('order_id');
            $orderChainInvoiceIds = collect();
            $fallbackCount = 0;
            if ($orderIds->isNotEmpty()) {
                $fallbackItems = \App\Models\ArInvoiceItem::query()
                    ->whereJsonContains('meta->is_subscription', true)
                    ->whereHas('invoice', function ($q) use ($orderIds) {
                        $q->whereNull('voided_at')
                          ->whereIn('source_order_id', $orderIds);
                    })
                    ->whereRaw("(JSON_EXTRACT(meta, '$.subscription_id') IS NULL OR JSON_EXTRACT(meta, '$.subscription_id') != ?)", [$subscription->id]);

                $orderChainInvoiceIds = $fallbackItems->pluck('invoice_id')->unique();
                $fallbackCount        = (int) (clone $fallbackItems)->sum('qty');
            }

            // Path C: manually created plan-purchase invoices that have no subscription metadata.
            // These are invoices containing the plan menu item (e.g. MI-000084 / MI-000094) for
            // this customer, which were not captured by the metadata or order-chain paths above.
            $planPathCount = 0;
            if ($subscription->plan_meals_total !== null) {
                $planItemIds = config('subscriptions.plan_menu_item_ids', []);
                $matchingMenuItemId = $planItemIds[(int) $subscription->plan_meals_total] ?? null;

                if ($matchingMenuItemId) {
                    $alreadyCounted = $metaInvoiceIds->merge($orderChainInvoiceIds)->unique();

                    $planPathCount = (int) \App\Models\ArInvoiceItem::query()
                        ->where('sellable_type', \App\Models\MenuItem::class)
                        ->where('sellable_id', $matchingMenuItemId)
                        ->whereHas('invoice', function ($q) use ($subscription, $alreadyCounted) {
                            $q->whereNull('voided_at')
                              ->where('type', '!=', 'credit_note')
                              ->where('customer_id', $subscription->customer_id)
                              ->when($alreadyCounted->isNotEmpty(), fn ($q2) => $q2->whereNotIn('id', $alreadyCounted->all()));
                        })
                        ->sum('qty');
                }
            }

            $total = $primaryCount + $fallbackCount + $planPathCount;
        }

        DB::transaction(function () use ($subscription, $total) {
            $sub = MealSubscription::lockForUpdate()->findOrFail($subscription->id);
            $sub->meals_used = $total;
            if ($sub->plan_meals_total !== null) {
                if ($total >= $sub->plan_meals_total && $sub->status === 'active') {
                    $sub->status   = 'expired';
                    $sub->end_date = $sub->end_date ?? now()->toDateString();
                } elseif ($total < $sub->plan_meals_total && $sub->status === 'expired') {
                    $sub->status   = 'active';
                    $sub->end_date = null;
                }
            }
            $sub->save();
        });

        $subscription->refresh();
        return $total;
    }
}
