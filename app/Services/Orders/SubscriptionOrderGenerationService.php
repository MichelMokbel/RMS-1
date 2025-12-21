<?php

namespace App\Services\Orders;

use App\Models\DailyDishMenu;
use App\Models\DailyDishMenuItem;
use App\Models\MealSubscription;
use App\Models\MealSubscriptionOrder;
use App\Models\MenuItem;
use App\Models\OpsEvent;
use App\Models\SubscriptionOrderRun;
use App\Models\SubscriptionOrderRunError;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubscriptionOrderGenerationService
{
    public function generateForDate(string $serviceDate, int $branchId, int $userId, bool $dryRun = false): array
    {
        $run = SubscriptionOrderRun::create([
            'service_date' => $serviceDate,
            'branch_id' => $branchId,
            'started_at' => now(),
            'finished_at' => null,
            'status' => 'running',
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = [
            'run_id' => $run->id,
            'dry_run' => $dryRun,
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
            SubscriptionOrderRunError::create([
                'run_id' => $run->id,
                'subscription_id' => null,
                'message' => __('No published daily dish menu for date.'),
                'context_json' => ['branch_id' => $branchId, 'service_date' => $serviceDate],
                'created_at' => now(),
            ]);
            $this->finishRun($run, $result, 'failed');
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
            ->filter(fn ($s) => $s->isActiveOn($serviceDate))
            ->filter(function (MealSubscription $s) {
                // Optional quota-based subscriptions (20/26 meals). If set, stop generating once used >= total.
                if ($s->plan_meals_total === null) {
                    return true;
                }
                return (int) ($s->meals_used ?? 0) < (int) $s->plan_meals_total;
            });

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

                    // Increment quota usage if this subscription is quota-bound.
                    if ($sub->plan_meals_total !== null) {
                        $sub->meals_used = (int) ($sub->meals_used ?? 0) + 1;
                        // If quota reached, expire it at this date.
                        if ((int) $sub->meals_used >= (int) $sub->plan_meals_total) {
                            $sub->status = 'expired';
                            $sub->end_date = $serviceDate;
                        }
                        $sub->save();
                    }

                    $result['created_count']++;
                });
            } catch (\Throwable $e) {
                if (str_contains($e->getMessage(), 'meal_sub_orders_unique')) {
                    $result['skipped_existing_count']++;
                } else {
                    $result['errors'][] = $e->getMessage();
                    SubscriptionOrderRunError::create([
                        'run_id' => $run->id,
                        'subscription_id' => $sub->id,
                        'message' => $e->getMessage(),
                        'context_json' => ['subscription_id' => $sub->id],
                        'created_at' => now(),
                    ]);
                }
            }
        }

        $status = 'success';
        if (! empty($result['errors'])) {
            $status = $result['created_count'] > 0 ? 'partial' : 'failed';
        }

        $this->finishRun($run, $result, $status);

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
        $planPrice = $this->subscriptionOrderPrice($sub);

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

        foreach ($items as $idx => $menuItem) {
            DB::table('order_items')->insert([
                'order_id' => $orderId,
                'menu_item_id' => $menuItem->menu_item_id,
                'description_snapshot' => trim(($menuItem->menuItem->code ?? '').' '.$menuItem->menuItem->name),
                'quantity' => $quantity,
                'unit_price' => 0,
                'discount_amount' => 0,
                'line_total' => 0,
                'status' => 'Pending',
                'sort_order' => $menuItem->sort_order ?? $idx,
            ]);
        }

        DB::table('orders')
            ->where('id', $orderId)
            ->update([
                'total_before_tax' => $planPrice,
                'tax_amount' => 0,
                'total_amount' => $planPrice,
                'updated_at' => now(),
            ]);

        return $orderId;
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

    private function generateOrderNumber(): string
    {
        $year = now()->format('Y');
        $prefix = 'ORD'.$year.'-';

        do {
            $number = $prefix.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
        } while (DB::table('orders')->where('order_number', $number)->exists());

        return $number;
    }

    private function finishRun(SubscriptionOrderRun $run, array $result, string $status): void
    {
        $run->status = $status;
        $run->finished_at = now();
        $run->created_count = (int) ($result['created_count'] ?? 0);
        $run->skipped_existing_count = (int) ($result['skipped_existing_count'] ?? 0);
        $run->skipped_no_menu_count = (int) ($result['skipped_no_menu_count'] ?? 0);
        $run->skipped_no_items_count = (int) ($result['skipped_no_items_count'] ?? 0);
        $run->error_summary = ! empty($result['errors']) ? implode("\n", array_slice($result['errors'], 0, 25)) : null;
        $run->updated_at = now();
        $run->save();

        OpsEvent::create([
            'event_type' => 'subscription_generated',
            'branch_id' => $run->branch_id,
            'service_date' => $run->service_date?->format('Y-m-d'),
            'order_id' => null,
            'order_item_id' => null,
            'actor_user_id' => $run->created_by,
            'metadata_json' => [
                'run_id' => $run->id,
                'status' => $status,
                'created_count' => $run->created_count,
                'skipped_existing_count' => $run->skipped_existing_count,
                'skipped_no_menu_count' => $run->skipped_no_menu_count,
                'skipped_no_items_count' => $run->skipped_no_items_count,
                'dry_run' => (bool) (($result['dry_run'] ?? false) ?: false),
            ],
            'created_at' => now(),
        ]);
    }
}


