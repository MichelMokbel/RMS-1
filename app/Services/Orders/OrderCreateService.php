<?php

namespace App\Services\Orders;

use App\Models\DailyDishMenu;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderCreateService
{
    public function __construct(
        protected OrderNumberService $numbers,
        protected OrderTotalsService $totals
    ) {
    }

    /**
     * @param  array  $data validated order fields (incl selected_items, menu_id, etc.)
     * @param  int|null $actorId user id creating the order (nullable in tests)
     */
    public function create(array $data, ?int $actorId): Order
    {
        $isDailyDish = (bool) ($data['is_daily_dish'] ?? false);
        $branchId = (int) ($data['branch_id'] ?? 0);
        $actorId = ($actorId && $actorId > 0) ? $actorId : null;

        if ($isDailyDish) {
            $menuId = (int) ($data['menu_id'] ?? 0);
            $menu = DailyDishMenu::with('items.menuItem')
                ->where('id', $menuId)
                ->where('branch_id', $branchId)
                ->where('status', 'published')
                ->first();
            if (! $menu) {
                throw ValidationException::withMessages([
                    'menu_id' => __('A published daily dish menu is required for daily dish orders.'),
                ]);
            }
        }

        $items = collect($data['selected_items'] ?? [])
            ->filter(fn ($row) => ! empty($row['menu_item_id']))
            ->values();

        if ($items->isEmpty()) {
            throw ValidationException::withMessages([
                'selected_items' => __('Select at least one menu item.'),
            ]);
        }

        $menuItems = $this->loadMenuItems($items);

        $subtotal = $this->computeSubtotal($items, $menuItems);
        $orderDiscount = (float) ($data['order_discount_amount'] ?? 0);
        if ($orderDiscount > $subtotal + 0.0001) {
            throw ValidationException::withMessages([
                'order_discount_amount' => __('Order discount cannot exceed subtotal.'),
            ]);
        }

        return DB::transaction(function () use ($data, $items, $menuItems, $actorId) {
            $order = Order::create([
                'order_number' => $this->numbers->generate(),
                'branch_id' => $data['branch_id'],
                'source' => ($data['source'] ?? '') === 'Subscription' ? 'Backoffice' : ($data['source'] ?? 'Backoffice'),
                'is_daily_dish' => (bool) ($data['is_daily_dish'] ?? false),
                'type' => $data['type'],
                'status' => $data['status'],
                'customer_id' => $data['customer_id'] ?? null,
                'customer_name_snapshot' => $data['customer_name_snapshot'] ?? null,
                'customer_phone_snapshot' => $data['customer_phone_snapshot'] ?? null,
                'delivery_address_snapshot' => $data['delivery_address_snapshot'] ?? null,
                'scheduled_date' => $data['scheduled_date'],
                'scheduled_time' => $data['scheduled_time'] ?? null,
                'notes' => $data['notes'] ?? null,
                'order_discount_amount' => (float) ($data['order_discount_amount'] ?? 0),
                'total_before_tax' => 0,
                'tax_amount' => 0,
                'total_amount' => 0,
                'created_by' => $actorId,
                'created_at' => now(),
            ]);

            foreach ($items as $idx => $row) {
                $menuItem = $menuItems->get((int) $row['menu_item_id']);
                $qty = (float) ($row['quantity'] ?? 1);
                $price = isset($row['unit_price'])
                    ? (float) $row['unit_price']
                    : (float) ($menuItem?->selling_price_per_unit ?? 0);
                $discount = (float) ($row['discount_amount'] ?? 0);
                $lineTotal = max(0, ($qty * $price) - $discount);

                OrderItem::create([
                    'order_id' => $order->id,
                    'menu_item_id' => (int) $row['menu_item_id'],
                    'description_snapshot' => trim(($menuItem?->code ?? '').' '.($menuItem?->name ?? '')),
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'discount_amount' => $discount,
                    'line_total' => round($lineTotal, 3),
                    'status' => 'Pending',
                    'sort_order' => $row['sort_order'] ?? $idx,
                ]);
            }

            $this->totals->recalc($order);

            return $order->fresh();
        });
    }

    private function loadMenuItems(Collection $items): Collection
    {
        $ids = $items->pluck('menu_item_id')->map(fn ($id) => (int) $id)->all();

        $menuItems = MenuItem::query()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        if ($menuItems->count() !== $items->count()) {
            throw ValidationException::withMessages([
                'selected_items' => __('Some menu items could not be found.'),
            ]);
        }

        return $menuItems;
    }

    private function computeSubtotal(Collection $items, Collection $menuItems): float
    {
        return (float) $items->reduce(function (float $carry, array $row) use ($menuItems): float {
            $qty = (float) ($row['quantity'] ?? 1);
            $menuItem = $menuItems->get((int) ($row['menu_item_id'] ?? 0));
            $price = isset($row['unit_price']) ? (float) $row['unit_price'] : (float) ($menuItem?->selling_price_per_unit ?? 0);
            $discount = (float) ($row['discount_amount'] ?? 0);
            return $carry + max(0, ($qty * $price) - $discount);
        }, 0.0);
    }
}

