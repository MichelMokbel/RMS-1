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
     * Recompute meals_used from invoiced subscription items and sync the stored counter.
     * Primary path: ArInvoiceItem.meta.subscription_id (available after Step 5 deployed).
     * Fallback path: join through meal_subscription_orders for older items.
     */
    public function resyncMealsUsed(MealSubscription $subscription): int
    {
        // Path A-primary: items tagged with subscription_id in meta (auto-generated delivery invoices, Step 5+)
        $primaryCount = \App\Models\ArInvoiceItem::query()
            ->whereJsonContains('meta->is_subscription', true)
            ->where(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.subscription_id'))"), (string) $subscription->id)
            ->whereHas('invoice', fn ($q) => $q->whereNull('voided_at'))
            ->count();

        // Path A-fallback: delivery items linked via order chain (pre-Step-5 invoices without subscription_id in meta)
        $orderIds = $subscription->subscriptionOrders()->pluck('order_id');
        $fallbackCount = 0;
        if ($orderIds->isNotEmpty()) {
            $fallbackCount = \App\Models\ArInvoiceItem::query()
                ->whereJsonContains('meta->is_subscription', true)
                ->whereHas('invoice', function ($q) use ($orderIds) {
                    $q->whereNull('voided_at')
                      ->whereIn('source_order_id', $orderIds);
                })
                ->whereRaw("(JSON_EXTRACT(meta, '$.subscription_id') IS NULL OR JSON_EXTRACT(meta, '$.subscription_id') != ?)", [$subscription->id])
                ->count();
        }

        // Path B: manually created invoices where MI-000084 / MI-000094 are real sellable items.
        // These items have meta = null so neither path above finds them.
        // IMPORTANT: only count invoices that are allocated to the subscription's source_payment_id.
        // If source_payment_id is null there is no way to distinguish subscription invoices from
        // unrelated historic purchases — count nothing (prod-safe default).
        $planItemIds = array_values(config('subscriptions.plan_menu_item_ids', []));
        $planBCount = 0;
        if (! empty($planItemIds) && $subscription->source_payment_id) {
            $sourcePaymentId = $subscription->source_payment_id;
            $planBCount = (int) \App\Models\ArInvoiceItem::query()
                ->where('sellable_type', \App\Models\MenuItem::class)
                ->whereIn('sellable_id', $planItemIds)
                ->whereHas('invoice', function ($q) use ($subscription, $sourcePaymentId) {
                    $q->where('customer_id', $subscription->customer_id)
                      ->whereNull('voided_at')
                      ->whereHas('paymentAllocations', fn ($q2) => $q2->where('payment_id', $sourcePaymentId));
                })
                ->sum('qty');
            $planBCount = max(0, (int) round($planBCount));
        }

        // Path C: delivery invoice items (meta.is_subscription=true) in invoices allocated to
        // source_payment_id that are NOT already counted by Path A-primary or A-fallback.
        // This covers meals invoiced before the subscription record existed: they have no
        // meta.subscription_id and no meal_subscription_orders row, but the invoice IS allocated
        // to the subscription's linked payment.
        $pathCCount = 0;
        if ($subscription->source_payment_id) {
            $sourcePaymentId = $subscription->source_payment_id;

            $pathCCount = \App\Models\ArInvoiceItem::query()
                ->whereJsonContains('meta->is_subscription', true)
                // Not already counted by Path A-primary
                ->whereRaw("(JSON_EXTRACT(meta, '$.subscription_id') IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(meta, '$.subscription_id')) != ?)", [(string) $subscription->id])
                ->whereHas('invoice', function ($q) use ($subscription, $sourcePaymentId, $orderIds) {
                    $q->where('customer_id', $subscription->customer_id)
                      ->whereNull('voided_at')
                      ->whereHas('paymentAllocations', fn ($q2) => $q2->where('payment_id', $sourcePaymentId));
                    // Not already counted by Path A-fallback (not from the order chain)
                    if ($orderIds->isNotEmpty()) {
                        $q->whereNotIn('source_order_id', $orderIds);
                    }
                })
                ->count();
        }

        $total = $primaryCount + $fallbackCount + $planBCount + $pathCCount;

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
