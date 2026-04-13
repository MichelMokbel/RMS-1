<?php

namespace App\Services\PastryOrders;

use App\Models\PastryOrder;

class PastryOrderTotalsService
{
    public function recalc(PastryOrder $order): PastryOrder
    {
        $sum      = (float) $order->items()->sum('line_total');
        $discount = (float) ($order->order_discount_amount ?? 0);
        $sum      = max(0, round($sum - $discount, 3));

        $order->total_before_tax = $sum;
        $order->tax_amount       = 0;
        $order->total_amount     = $sum;
        $order->save();

        return $order->fresh(['items']);
    }
}
