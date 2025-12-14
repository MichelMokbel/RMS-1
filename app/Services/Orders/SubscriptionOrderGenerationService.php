<?php

namespace App\Services\Orders;

use App\Models\DailyDishMenu;
use App\Models\DailyDishMenuItem;
use App\Models\MealSubscription;
use App\Models\MealSubscriptionOrder;
use App\Models\MenuItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubscriptionOrderGenerationService
{
    public function generateForDate(string $serviceDate, int $branchId, int $userId, bool $dryRun = false): array
    {
        $result = [
            'created_count' => 0,
            'skipped_existing_count' => 0,
            'skipped_no_menu_count' => 0,
            'skipped_no_items_count' => 0,
            'errors' => [],
        ];

        $menu = DailyDishMenu::with(['items.menuItem'])
            ->where('branch_id', $branchId)
            ->whereDate('service_date', $serviceDate)
            ->where('status', 'published')
            ->first();

        if (! $menu) {
            $result['skipped_no_menu_count'] = 1;
            $result['errors'][] = __('No published daily dish menu for date.');
            return $result;
        }

        $subs = MealSubscription::with(['days', 'pauses', 'customer'])
            ->where('branch_id', $branchId)
            ->where('status', 'active')
            ->whereDate('start_date', '<=', $serviceDate)
            ->where(function ($q) use ($serviceDate) {
                $q->whereNull('end_date')->orWhereDate('end_date', '>=', $serviceDate);
            })
            ->get()
            ->filter(fn ($s) => $s->isActiveOn($serviceDate));

        $menuItemsGrouped = $menu->items->groupBy('role');

        foreach ($subs as $sub) {
            // Idempotency check
            $exists = MealSubscriptionOrder::where('subscription_id', $sub->id)
                ->whereDate('service_date', $serviceDate)
                ->where('branch_id', $branchId)
                ->exists();
            if ($exists) {
                $result['skipped_existing_count']++;
                continue;
            }

            $selectedItems = $this->selectItemsForSubscription($sub, $menuItemsGrouped);
            if ($selectedItems->isEmpty()) {
                $result['skipped_no_items_count']++;
                continue;
            }

            if ($dryRun) {
                $result['created_count']++;
                continue;
            }

            try {
                DB::transaction(function () use ($sub, $selectedItems, $serviceDate, $branchId, $userId, &$result) {
                    $orderId = $this->createOrder($sub, $selectedItems, $serviceDate, $branchId, $userId);

                    MealSubscriptionOrder::create([
                        'subscription_id' => $sub->id,
                        'order_id' => $orderId,
                        'service_date' => $serviceDate,
                        'branch_id' => $branchId,
                    ]);

                    $result['created_count']++;
                });
            } catch (\Throwable $e) {
                if (str_contains($e->getMessage(), 'meal_sub_orders_unique')) {
                    $result['skipped_existing_count']++;
                } else {
                    $result['errors'][] = $e->getMessage();
                }
            }
        }

        return $result;
    }

    private function selectItemsForSubscription(MealSubscription $sub, Collection $menuItemsGrouped): Collection
    {
        $includeAll = config('subscriptions.include_all_matching_role_items', true);

        $items = collect();

        $roleItems = $menuItemsGrouped[$sub->preferred_role] ?? collect();
        if ($roleItems->isNotEmpty()) {
            $items = $items->merge($includeAll ? $roleItems : collect([$roleItems->sortBy('sort_order')->first()]));
        }

        if ($sub->include_salad && isset($menuItemsGrouped['salad'])) {
            $items = $items->merge($menuItemsGrouped['salad']);
        }

        if ($sub->include_dessert && isset($menuItemsGrouped['dessert'])) {
            $items = $items->merge($menuItemsGrouped['dessert']);
        }

        if (isset($menuItemsGrouped['addon'])) {
            $items = $items->merge($menuItemsGrouped['addon']->filter(fn ($i) => $i->is_required));
        }

        return $items->unique('menu_item_id');
    }

    private function createOrder(MealSubscription $sub, Collection $items, string $serviceDate, int $branchId, int $userId): int
    {
        $orderNumber = $this->generateOrderNumber();
        $status = config('subscriptions.generated_order_status', 'Confirmed');
        $quantity = (float) config('subscriptions.generated_item_quantity', 1.0);

        $orderId = DB::table('orders')->insertGetId([
            'order_number' => $orderNumber,
            'branch_id' => $branchId,
            'source' => 'Subscription',
            'is_daily_dish' => 1,
            'type' => $sub->default_order_type,
            'status' => $status,
            'customer_id' => $sub->customer_id,
            'customer_name_snapshot' => $sub->customer->name ?? null,
            'customer_phone_snapshot' => $sub->phone_snapshot ?? $sub->customer->phone ?? null,
            'delivery_address_snapshot' => $sub->address_snapshot ?? $sub->customer->delivery_address ?? null,
            'scheduled_date' => $serviceDate,
            'scheduled_time' => $sub->delivery_time,
            'notes' => $sub->notes,
            'total_before_tax' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
            'created_by' => $userId,
            'created_at' => now(),
        ]);

        $lineTotalSum = 0;
        foreach ($items as $idx => $menuItem) {
            $price = (float) ($menuItem->menuItem->selling_price_per_unit ?? 0);
            $lineTotal = round($quantity * $price, 3);
            $lineTotalSum += $lineTotal;

            DB::table('order_items')->insert([
                'order_id' => $orderId,
                'menu_item_id' => $menuItem->menu_item_id,
                'description_snapshot' => trim(($menuItem->menuItem->code ?? '').' '.$menuItem->menuItem->name),
                'quantity' => $quantity,
                'unit_price' => $price,
                'discount_amount' => 0,
                'line_total' => $lineTotal,
                'status' => 'Pending',
                'sort_order' => $menuItem->sort_order ?? $idx,
            ]);
        }

        DB::table('orders')
            ->where('id', $orderId)
            ->update([
                'total_before_tax' => $lineTotalSum,
                'tax_amount' => 0,
                'total_amount' => $lineTotalSum,
                'updated_at' => now(),
            ]);

        return $orderId;
    }

    private function generateOrderNumber(): string
    {
        $year = now()->format('Y');
        $prefix = 'ORD'.$year.'-';

        do {
            $number = $prefix.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
        } while (DB::table('orders')->where('order_number', $number)->exists());

        return $number;
    }
}

