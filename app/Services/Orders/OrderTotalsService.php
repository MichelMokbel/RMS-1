<?php

namespace App\Services\Orders;

use App\Models\MealSubscription;
use App\Models\MealSubscriptionOrder;
use App\Models\Order;
use App\Services\Pricing\MealPlanPricingService;

class OrderTotalsService
{
    public function __construct(protected MealPlanPricingService $pricing)
    {
    }

    public function recalc(Order $order): Order
    {
        $sum = (float) $order->items()->sum('line_total');
        $discount = (float) ($order->order_discount_amount ?? 0);
        $sum = max(0, $sum - $discount);

        if ($order->source === 'Subscription') {
            $subscriptionId = MealSubscriptionOrder::query()
                ->where('order_id', $order->id)
                ->value('subscription_id');
            if ($subscriptionId) {
                $subscription = MealSubscription::find($subscriptionId);
                if ($subscription) {
                    $sum = $this->pricing->subscriptionPrice($subscription);
                }
            }
        }

        $order->total_before_tax = $sum;
        $order->tax_amount = 0;
        $order->total_amount = $sum;
        $order->save();

        return $order->fresh(['items']);
    }
}
