<?php

namespace App\Services\Orders;

use App\Models\MealSubscription;
use App\Models\MealSubscriptionOrder;
use App\Models\Order;

class OrderTotalsService
{
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
                    $sum = $this->subscriptionOrderPrice($subscription);
                }
            }
        }

        $order->total_before_tax = $sum;
        $order->tax_amount = 0;
        $order->total_amount = $sum;
        $order->save();

        return $order->fresh(['items']);
    }

    private function subscriptionOrderPrice(MealSubscription $sub): float
    {
        $totalMeals = $sub->plan_meals_total;
        if ($totalMeals !== null) {
            if ((int) $totalMeals === 20) {
                return 40.000;
            }
            if ((int) $totalMeals === 26) {
                return 42.300;
            }
        }

        $hasSalad = (bool) $sub->include_salad;
        $hasDessert = (bool) $sub->include_dessert;

        if ($hasSalad && $hasDessert) {
            return 65.000;
        }
        if ($hasSalad || $hasDessert) {
            return 55.000;
        }

        return 50.000;
    }
}

