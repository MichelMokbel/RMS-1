<?php

namespace App\Services\Orders;

use App\Models\Order;
use Illuminate\Validation\ValidationException;

class OrderStatusService
{
    protected array $itemCascade = [
        'Delivered' => 'Completed',
        'Cancelled' => 'Cancelled',
    ];

    public function setStatus(Order $order, string $status): Order
    {
        $valid = ['Draft','Confirmed','InProduction','Ready','OutForDelivery','Delivered','Cancelled'];
        if (! in_array($status, $valid, true)) {
            throw ValidationException::withMessages(['status' => __('Invalid status')]);
        }

        $order->status = $status;
        $order->save();

        if (isset($this->itemCascade[$status])) {
            $itemStatus = $this->itemCascade[$status];
            $order->items()->update(['status' => $itemStatus]);
        }

        return $order->fresh(['items']);
    }
}

