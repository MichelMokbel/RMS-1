<?php

namespace App\Services\Orders;

use App\Models\DailyDishMenu;
use App\Models\MealSubscription;
use App\Models\MealSubscriptionOrder;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Pricing\DailyDishPricingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderCreateService
{
    public function __construct(
        protected OrderNumberService $numbers,
        protected OrderTotalsService $totals,
        protected DailyDishPricingService $dailyDishPricing,
        protected SubscriptionOrderGenerationService $subscriptionGen
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
        $subscriptionId = (int) ($data['subscription_id'] ?? 0);
        $actorId = ($actorId && $actorId > 0) ? $actorId : null;
        $source = ($data['source'] ?? '') === 'Subscription' || ($isDailyDish && $subscriptionId > 0)
            ? 'Subscription'
            : (($data['source'] ?? '') === 'Subscription' ? 'Backoffice' : ($data['source'] ?? 'Backoffice'));

        $menu = null;
        if ($isDailyDish) {
            if ($subscriptionId > 0) {
                $serviceDate = $data['scheduled_date'] ?? null;
                $menu = DailyDishMenu::with('items.menuItem')
                    ->where('branch_id', $branchId)
                    ->whereDate('service_date', $serviceDate)
                    ->where('status', 'published')
                    ->first();
                if (! $menu) {
                    throw ValidationException::withMessages([
                        'menu_id' => __('No published daily dish menu for the scheduled date.'),
                    ]);
                }
            } else {
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
        }

        if ($isDailyDish && $source === 'Subscription' && $subscriptionId > 0) {
            $sub = MealSubscription::with('customer')->find($subscriptionId);
            if (! $sub) {
                throw ValidationException::withMessages([
                    'subscription_id' => __('Subscription not found.'),
                ]);
            }
            if ($sub->branch_id != $branchId) {
                throw ValidationException::withMessages([
                    'subscription_id' => __('Subscription is for a different branch.'),
                ]);
            }
            return $this->createSubscriptionDailyDishOrder($data, $sub, $menu, $actorId);
        }

        $items = collect($data['selected_items'] ?? [])
            ->filter(fn ($row) => ! empty($row['menu_item_id']))
            ->values();

        if ($items->isEmpty()) {
            throw ValidationException::withMessages([
                'selected_items' => __('Select at least one menu item.'),
            ]);
        }

        if ($isDailyDish && $source !== 'Subscription') {
            return $this->createOneOffDailyDishOrder($data, $items, $menu, $actorId);
        }

        $menuItems = $this->loadMenuItems($items);
        $subtotal = $this->computeSubtotal($items, $menuItems);
        $orderDiscount = (float) ($data['order_discount_amount'] ?? 0);
        if ($orderDiscount > $subtotal + 0.0001) {
            throw ValidationException::withMessages([
                'order_discount_amount' => __('Order discount cannot exceed subtotal.'),
            ]);
        }

        return DB::transaction(function () use ($data, $items, $menuItems, $actorId, $source) {
            $order = Order::create([
                'order_number' => $this->numbers->generate(),
                'branch_id' => $data['branch_id'],
                'source' => $source,
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

    private function createOneOffDailyDishOrder(array $data, Collection $items, DailyDishMenu $menu, ?int $actorId): Order
    {
        $portionType = (string) ($data['daily_dish_portion_type'] ?? 'plate');
        $portionQuantity = isset($data['daily_dish_portion_quantity']) ? (int) $data['daily_dish_portion_quantity'] : null;
        $itemsWithQty = $items->filter(fn ($row) => (float) ($row['quantity'] ?? 0) > 0)->values();

        $requiresItems = $portionType === 'plate';
        if ($requiresItems && $itemsWithQty->isEmpty()) {
            throw ValidationException::withMessages([
                'selected_items' => __('Select at least one menu item with quantity.'),
            ]);
        }

        $roleByMenuItemId = $menu->items->keyBy('menu_item_id')->map(fn ($item) => $item->role)->all();
        $selectedWithRoles = $itemsWithQty->map(fn ($row) => [
            'menu_item_id' => (int) $row['menu_item_id'],
            'quantity' => (float) ($row['quantity'] ?? 0),
            'role' => $roleByMenuItemId[(int) $row['menu_item_id']] ?? 'main',
        ])->all();

        $subtotal = $this->dailyDishPricing->computeFromSelection($selectedWithRoles, $portionType, $portionQuantity);
        $orderDiscount = (float) ($data['order_discount_amount'] ?? 0);
        if ($orderDiscount > $subtotal + 0.0001) {
            throw ValidationException::withMessages([
                'order_discount_amount' => __('Order discount cannot exceed subtotal.'),
            ]);
        }
        $total = max(0, round($subtotal - $orderDiscount, 3));

        $menuItems = $itemsWithQty->isEmpty() ? collect() : $this->loadMenuItems($itemsWithQty);

        return DB::transaction(function () use ($data, $itemsWithQty, $menu, $roleByMenuItemId, $menuItems, $portionType, $portionQuantity, $total, $orderDiscount, $actorId) {
            $order = Order::create([
                'order_number' => $this->numbers->generate(),
                'branch_id' => $data['branch_id'],
                'source' => ($data['source'] ?? '') === 'Subscription' ? 'Backoffice' : ($data['source'] ?? 'Backoffice'),
                'is_daily_dish' => true,
                'daily_dish_portion_type' => $portionType,
                'daily_dish_portion_quantity' => $portionQuantity,
                'type' => $data['type'],
                'status' => $data['status'],
                'customer_id' => $data['customer_id'] ?? null,
                'customer_name_snapshot' => $data['customer_name_snapshot'] ?? null,
                'customer_phone_snapshot' => $data['customer_phone_snapshot'] ?? null,
                'delivery_address_snapshot' => $data['delivery_address_snapshot'] ?? null,
                'scheduled_date' => $data['scheduled_date'],
                'scheduled_time' => $data['scheduled_time'] ?? null,
                'notes' => $data['notes'] ?? null,
                'order_discount_amount' => $orderDiscount,
                'total_before_tax' => $total,
                'tax_amount' => 0,
                'total_amount' => $total,
                'created_by' => $actorId,
                'created_at' => now(),
            ]);

            foreach ($itemsWithQty as $idx => $row) {
                $menuItem = $menuItems->get((int) $row['menu_item_id']);
                $qty = (float) ($row['quantity'] ?? 0);
                $role = $roleByMenuItemId[(int) $row['menu_item_id']] ?? 'main';

                OrderItem::create([
                    'order_id' => $order->id,
                    'menu_item_id' => (int) $row['menu_item_id'],
                    'description_snapshot' => trim(($menuItem?->code ?? '').' '.($menuItem?->name ?? '')),
                    'quantity' => $qty,
                    'unit_price' => 0,
                    'discount_amount' => 0,
                    'line_total' => 0,
                    'status' => 'Pending',
                    'sort_order' => $row['sort_order'] ?? $idx,
                    'role' => $role,
                ]);
            }

            $this->totals->recalc($order);

            return $order->fresh();
        });
    }

    private function createSubscriptionDailyDishOrder(array $data, MealSubscription $sub, DailyDishMenu $menu, ?int $actorId): Order
    {
        $serviceDate = $data['scheduled_date'] ?? $menu->service_date?->format('Y-m-d');
        $menuItemsGrouped = $menu->items->groupBy('role');
        $selectedMainMenuItemId = (int) ($data['subscription_main_menu_item_id'] ?? 0);
        if ($selectedMainMenuItemId <= 0) {
            throw ValidationException::withMessages([
                'subscription_main_menu_item_id' => __('Select a main dish for this subscription order.'),
            ]);
        }
        $selectedItems = $this->subscriptionGen->getItemsForSubscriptionWithSelectedMain($sub, $menuItemsGrouped, $selectedMainMenuItemId);
        if ($selectedItems->isEmpty()) {
            throw ValidationException::withMessages([
                'subscription_main_menu_item_id' => __('Selected main dish is not in the menu for the scheduled date.'),
            ]);
        }

        $quantity = (float) config('subscriptions.generated_item_quantity', 1.0);

        return DB::transaction(function () use ($data, $sub, $selectedItems, $serviceDate, $quantity, $actorId) {
            $order = Order::create([
                'order_number' => $this->numbers->generate(),
                'branch_id' => $data['branch_id'],
                'source' => 'Subscription',
                'is_daily_dish' => true,
                'type' => $data['type'] ?? $sub->default_order_type,
                'status' => $data['status'] ?? config('subscriptions.generated_order_status', 'Confirmed'),
                'customer_id' => $sub->customer_id,
                'customer_name_snapshot' => $sub->customer->name ?? null,
                'customer_phone_snapshot' => $sub->phone_snapshot ?? $sub->customer->phone ?? null,
                'delivery_address_snapshot' => $data['delivery_address_snapshot'] ?? $sub->address_snapshot ?? $sub->customer->delivery_address ?? null,
                'scheduled_date' => $serviceDate,
                'scheduled_time' => $data['scheduled_time'] ?? $sub->delivery_time,
                'notes' => $data['notes'] ?? $sub->notes,
                'order_discount_amount' => 0,
                'total_before_tax' => 0,
                'tax_amount' => 0,
                'total_amount' => 0,
                'created_by' => $actorId,
                'created_at' => now(),
            ]);

            foreach ($selectedItems->values() as $idx => $menuItem) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'menu_item_id' => $menuItem->menu_item_id,
                    'description_snapshot' => trim(($menuItem->menuItem->code ?? '').' '.($menuItem->menuItem->name ?? '')),
                    'quantity' => $quantity,
                    'unit_price' => 0,
                    'discount_amount' => 0,
                    'line_total' => 0,
                    'status' => 'Pending',
                    'sort_order' => $menuItem->sort_order ?? $idx,
                    'role' => $menuItem->role ?? null,
                ]);
            }

            MealSubscriptionOrder::firstOrCreate(
                [
                    'subscription_id' => $sub->id,
                    'order_id' => $order->id,
                ],
                [
                    'service_date' => $serviceDate,
                    'branch_id' => $order->branch_id,
                ]
            );

            // Increment quota usage if this subscription is quota-bound.
            if ($sub->plan_meals_total !== null) {
                $lockedSub = MealSubscription::lockForUpdate()->find($sub->id);
                if ($lockedSub) {
                    $lockedSub->meals_used = (int) ($lockedSub->meals_used ?? 0) + 1;
                    // If quota reached, expire it at this date.
                    if ((int) $lockedSub->meals_used >= (int) $lockedSub->plan_meals_total) {
                        $lockedSub->status = 'expired';
                        $lockedSub->end_date = $serviceDate;
                    }
                    $lockedSub->save();
                }
            }

            $this->totals->recalc($order);

            return $order->fresh(['items']);
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

