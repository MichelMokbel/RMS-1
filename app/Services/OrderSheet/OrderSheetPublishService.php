<?php

namespace App\Services\OrderSheet;

use App\Models\MealSubscription;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderSheet;
use App\Services\Orders\OrderNumberService;
use App\Services\Orders\OrderTotalsService;
use Illuminate\Support\Facades\DB;

class OrderSheetPublishService
{
    public function __construct(
        protected OrderNumberService $numbers,
        protected OrderTotalsService $totals,
    ) {}

    /**
     * Create or update orders for every filled entry on the sheet.
     * - No order_id → create a new Order and link it.
     * - Has order_id  → resync items on the existing Order (update).
     *
     * @return array{created: int, updated: int}
     */
    public function publish(OrderSheet $sheet, ?int $actorId = null): array
    {
        $sheet->loadMissing(['entries.quantities.dailyDishMenuItem', 'entries.extras']);

        $defaultBranchId = DB::table('branches')->where('is_active', 1)->orderBy('id')->value('id') ?? 1;

        // menu_item_id → role for today's daily dish menu
        $menuRoleByMenuItemId = \App\Models\DailyDishMenuItem::join('daily_dish_menus', 'daily_dish_menu_items.daily_dish_menu_id', '=', 'daily_dish_menus.id')
            ->whereDate('daily_dish_menus.service_date', $sheet->sheet_date)
            ->pluck('daily_dish_menu_items.role', 'daily_dish_menu_items.menu_item_id')
            ->all();

        // Pre-load menu item names in one query
        $menuItemNames = MenuItem::whereIn('id',
            $sheet->entries->flatMap(fn ($e) =>
                $e->quantities->map(fn ($q) => $q->dailyDishMenuItem?->menu_item_id)->filter()
                    ->merge($e->extras->pluck('menu_item_id'))
            )->unique()->all()
        )->pluck('name', 'id')->all();

        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($sheet, $actorId, $defaultBranchId, $menuRoleByMenuItemId, $menuItemNames, &$created, &$updated) {
            foreach ($sheet->entries as $entry) {
                if (blank($entry->customer_name)) {
                    continue;
                }

                $items = $this->buildItems($entry, $menuRoleByMenuItemId, $menuItemNames);

                if ($entry->order_id) {
                    // ── Update existing order ──────────────────────────────
                    $order = Order::find($entry->order_id);
                    if (! $order) {
                        // Order was deleted externally — clear stale link and create fresh
                        $entry->update(['order_id' => null]);
                        $this->createOrder($entry, $items, $sheet, $defaultBranchId, $actorId);
                        $created++;
                        continue;
                    }

                    // Resync items: wipe and re-insert
                    $order->items()->delete();
                    $this->insertItems($order->id, $items);
                    $order->update([
                        'customer_name_snapshot' => $entry->customer_name,
                        'notes'                  => $entry->remarks ?: null,
                    ]);
                    $this->totals->recalc($order);
                    $updated++;
                } else {
                    // ── Create new order ──────────────────────────────────
                    $order = $this->createOrder($entry, $items, $sheet, $defaultBranchId, $actorId);
                    $entry->update(['order_id' => $order->id]);
                    $created++;
                }
            }
        });

        return compact('created', 'updated');
    }

    private function buildItems($entry, array $menuRoleByMenuItemId, array $menuItemNames): array
    {
        $items = [];
        $sort = 0;

        foreach ($entry->quantities as $q) {
            if ($q->quantity <= 0 || ! $q->dailyDishMenuItem) {
                continue;
            }
            $mid = $q->dailyDishMenuItem->menu_item_id;
            $items[] = [
                'menu_item_id'         => $mid,
                'description_snapshot' => $menuItemNames[$mid] ?? $q->dailyDishMenuItem->menuItem?->name ?? 'Unknown',
                'quantity'             => $q->quantity,
                'role'                 => $menuRoleByMenuItemId[$mid] ?? $q->dailyDishMenuItem->role ?? 'main',
                'sort_order'           => $sort++,
            ];
        }

        foreach ($entry->extras as $extra) {
            if ($extra->quantity <= 0) {
                continue;
            }
            $items[] = [
                'menu_item_id'         => $extra->menu_item_id,
                'description_snapshot' => $menuItemNames[$extra->menu_item_id] ?? $extra->menu_item_name,
                'quantity'             => $extra->quantity,
                'role'                 => 'addon',
                'sort_order'           => $sort++,
            ];
        }

        return $items;
    }

    private function insertItems(int $orderId, array $items): void
    {
        foreach ($items as $item) {
            OrderItem::create([
                'order_id'             => $orderId,
                'menu_item_id'         => $item['menu_item_id'],
                'description_snapshot' => $item['description_snapshot'],
                'quantity'             => $item['quantity'],
                'unit_price'           => 0,
                'discount_amount'      => 0,
                'line_total'           => 0,
                'status'               => 'Pending',
                'sort_order'           => $item['sort_order'],
                'role'                 => $item['role'],
            ]);
        }
    }

    private function createOrder($entry, array $items, OrderSheet $sheet, int $defaultBranchId, ?int $actorId): Order
    {
        $source = 'Backoffice';
        if ($entry->customer_id) {
            $hasSub = MealSubscription::where('customer_id', $entry->customer_id)
                ->where('status', 'active')
                ->whereDate('start_date', '<=', $sheet->sheet_date)
                ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $sheet->sheet_date))
                ->exists();
            if ($hasSub) {
                $source = 'Subscription';
            }
        }

        $order = Order::create([
            'order_number'            => $this->numbers->generate(),
            'branch_id'               => $defaultBranchId,
            'source'                  => $source,
            'is_daily_dish'           => true,
            'daily_dish_portion_type' => 'plate',
            'type'                    => 'Delivery',
            'status'                  => 'Confirmed',
            'customer_id'             => $entry->customer_id,
            'customer_name_snapshot'  => $entry->customer_name,
            'scheduled_date'          => $sheet->sheet_date,
            'notes'                   => $entry->remarks ?: null,
            'order_discount_amount'   => 0,
            'total_before_tax'        => 0,
            'tax_amount'              => 0,
            'total_amount'            => 0,
            'created_by'              => $actorId,
        ]);

        $this->insertItems($order->id, $items);
        $this->totals->recalc($order);

        return $order;
    }
}
