<?php

namespace App\Services\Orders;

use App\Models\OpsEvent;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderWorkflowService
{
    public function advanceOrder(Order $order, string $toStatus, int $actorId): Order
    {
        return DB::transaction(function () use ($order, $toStatus, $actorId) {
            /** @var Order $locked */
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, ['Cancelled', 'Delivered'], true)) {
                return $locked->fresh(['items']);
            }

            $valid = ['Draft', 'Confirmed', 'InProduction', 'Ready', 'OutForDelivery', 'Delivered', 'Cancelled'];
            if (! in_array($toStatus, $valid, true)) {
                throw ValidationException::withMessages(['status' => __('Invalid status')]);
            }

            $from = $locked->status;
            if (! $this->canTransitionOrder($locked, $from, $toStatus)) {
                throw ValidationException::withMessages(['status' => __('Invalid transition')]);
            }

            $locked->status = $toStatus;
            $locked->save();

            // Cascade item status when order status changes (kitchen flow)
            if ($toStatus === 'InProduction') {
                $locked->items()->update(['status' => 'InProduction']);
            }
            if ($toStatus === 'Ready') {
                $locked->items()->update(['status' => 'Completed']);
            }
            if ($toStatus === 'Delivered') {
                $locked->items()->update(['status' => 'Completed']);
            }
            if ($toStatus === 'Cancelled') {
                $locked->items()->update(['status' => 'Cancelled']);
            }

            OpsEvent::create([
                'event_type' => 'order_status_changed',
                'branch_id' => $locked->branch_id,
                'service_date' => $locked->scheduled_date?->format('Y-m-d'),
                'order_id' => $locked->id,
                'actor_user_id' => $actorId,
                'metadata_json' => [
                    'from' => $from,
                    'to' => $toStatus,
                ],
                'created_at' => now(),
            ]);

            return $locked->fresh(['items']);
        });
    }

    public function setItemStatus(OrderItem $item, string $toStatus, int $actorId): OrderItem
    {
        return DB::transaction(function () use ($item, $toStatus, $actorId) {
            /** @var OrderItem $locked */
            $locked = OrderItem::with('order')->lockForUpdate()->findOrFail($item->id);

            $order = $locked->order;
            if (! $order || in_array($order->status, ['Cancelled', 'Delivered'], true)) {
                return $locked->fresh(['order']);
            }

            $valid = ['Pending', 'InProduction', 'Ready', 'Completed', 'Cancelled'];
            if (! in_array($toStatus, $valid, true)) {
                throw ValidationException::withMessages(['status' => __('Invalid item status')]);
            }

            $from = $locked->status;
            if (! $this->canTransitionItem($from, $toStatus)) {
                throw ValidationException::withMessages(['status' => __('Invalid transition')]);
            }

            $locked->status = $toStatus;
            $locked->save();

            OpsEvent::create([
                'event_type' => 'item_status_changed',
                'branch_id' => $order->branch_id,
                'service_date' => $order->scheduled_date?->format('Y-m-d'),
                'order_id' => $order->id,
                'order_item_id' => $locked->id,
                'actor_user_id' => $actorId,
                'metadata_json' => [
                    'from' => $from,
                    'to' => $toStatus,
                ],
                'created_at' => now(),
            ]);

            return $locked->fresh(['order']);
        });
    }

    private function canTransitionOrder(Order $order, string $from, string $to): bool
    {
        if ($to === 'Cancelled') {
            return ! in_array($from, ['Cancelled', 'Delivered'], true);
        }

        $map = [
            'Draft' => ['Confirmed', 'Cancelled'],
            'Confirmed' => ['InProduction', 'Cancelled'],
            'InProduction' => ['Ready', 'Cancelled'],
            'Ready' => array_values(array_filter([
                $order->type === 'Delivery' ? 'OutForDelivery' : null,
                'Cancelled',
            ])),
            'OutForDelivery' => ['Delivered', 'Cancelled'],
        ];

        return in_array($to, $map[$from] ?? [], true);
    }

    private function canTransitionItem(string $from, string $to): bool
    {
        if ($to === 'Cancelled') {
            return in_array($from, ['Pending', 'InProduction', 'Ready'], true);
        }

        $map = [
            'Pending' => ['InProduction'],
            'InProduction' => ['Ready'],
            'Ready' => ['Completed'],
        ];

        return in_array($to, $map[$from] ?? [], true);
    }
}


