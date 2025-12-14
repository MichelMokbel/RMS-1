<?php

namespace App\Services\Orders;

use App\Models\Order;

class OrderTotalsService
{
    public function recalc(Order $order): Order
    {
        $sum = (float) $order->items()->sum('line_total');

        $order->total_before_tax = $sum;
        $order->tax_amount = 0;
        $order->total_amount = $sum;
        $order->save();

        return $order->fresh(['items']);
    }
}

