<?php

namespace App\Services\DailyDish;

use App\Models\DailyDishMenu;
use App\Models\Order;
use App\Models\SubscriptionOrderRun;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DailyDishOpsQueryService
{
    public function getMenu(int $branchId, string $serviceDate): ?DailyDishMenu
    {
        return DailyDishMenu::with(['items.menuItem'])
            ->where('branch_id', $branchId)
            ->whereDate('service_date', $serviceDate)
            ->first();
    }

    public function getPublishedMenu(int $branchId, string $serviceDate): ?DailyDishMenu
    {
        return DailyDishMenu::with(['items.menuItem'])
            ->where('branch_id', $branchId)
            ->whereDate('service_date', $serviceDate)
            ->where('status', 'published')
            ->first();
    }

    public function getOrdersForDay(int $branchId, string $serviceDate, array $filters = []): Collection
    {
        $query = Order::query()
            ->where('branch_id', $branchId)
            ->whereDate('scheduled_date', $serviceDate);

        if (array_key_exists('is_daily_dish', $filters)) {
            $query->where('is_daily_dish', (int) (bool) $filters['is_daily_dish']);
        }

        if (! empty($filters['statuses']) && is_array($filters['statuses'])) {
            $query->whereIn('status', $filters['statuses']);
        }

        if (! empty($filters['types']) && is_array($filters['types'])) {
            $query->whereIn('type', $filters['types']);
        }

        if (! empty($filters['include_subscription']) && empty($filters['include_manual'])) {
            $query->where('source', 'Subscription');
        } elseif (empty($filters['include_subscription']) && ! empty($filters['include_manual'])) {
            $query->where('source', '!=', 'Subscription');
        }

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(function ($qq) use ($term) {
                $qq->where('order_number', 'like', $term)
                    ->orWhere('customer_name_snapshot', 'like', $term)
                    ->orWhere('customer_phone_snapshot', 'like', $term);
            });
        }

        return $query
            ->with(['items' => function ($q) {
                $q->orderBy('sort_order')->orderBy('id');
            }])
            ->orderByRaw('CASE WHEN scheduled_time IS NULL THEN 1 ELSE 0 END')
            ->orderBy('scheduled_time')
            ->orderBy('id')
            ->get();
    }

    /**
     * Prep totals across Confirmed + InProduction orders by default.
     *
     * Returns rows: role (nullable), menu_item_id (nullable), description_snapshot, total_quantity
     */
    public function getPrepTotals(int $branchId, string $serviceDate, array $filters = []): Collection
    {
        $statuses = $filters['statuses'] ?? ['Confirmed', 'InProduction'];
        $includeSubscription = $filters['include_subscription'] ?? true;
        $includeManual = $filters['include_manual'] ?? true;

        $query = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.branch_id', $branchId)
            ->whereDate('orders.scheduled_date', $serviceDate)
            ->whereIn('orders.status', $statuses);

        if ($includeSubscription && ! $includeManual) {
            $query->where('orders.source', '=', 'Subscription');
        } elseif (! $includeSubscription && $includeManual) {
            $query->where('orders.source', '!=', 'Subscription');
        }

        if (! empty($filters['department'])) {
            if ($filters['department'] === 'DailyDish') {
                $query->where('orders.is_daily_dish', 1);
            } elseif ($filters['department'] === 'Pastry') {
                $query->where('orders.type', 'Pastry');
            } elseif ($filters['department'] === 'Other') {
                $query->where('orders.is_daily_dish', 0)->where('orders.type', '!=', 'Pastry');
            }
        }

        // Optional role enrichment: only for daily dish days with a menu
        $query->leftJoin('daily_dish_menus', function ($join) use ($branchId, $serviceDate) {
            $join->on('daily_dish_menus.branch_id', '=', 'orders.branch_id')
                ->whereDate('daily_dish_menus.service_date', $serviceDate);
        });
        $query->leftJoin('daily_dish_menu_items', function ($join) {
            $join->on('daily_dish_menu_items.daily_dish_menu_id', '=', 'daily_dish_menus.id')
                ->on('daily_dish_menu_items.menu_item_id', '=', 'order_items.menu_item_id');
        });

        $query->selectRaw('daily_dish_menu_items.role as role')
            ->selectRaw('order_items.menu_item_id as menu_item_id')
            ->selectRaw('order_items.description_snapshot as description_snapshot')
            ->selectRaw('SUM(order_items.quantity) as total_quantity')
            ->groupBy('daily_dish_menu_items.role', 'order_items.menu_item_id', 'order_items.description_snapshot')
            ->orderByRaw('CASE WHEN daily_dish_menu_items.role IS NULL THEN 1 ELSE 0 END')
            ->orderBy('daily_dish_menu_items.role')
            ->orderBy('order_items.description_snapshot');

        return collect($query->get());
    }

    public function getStatusCounts(int $branchId, string $serviceDate, array $filters = []): array
    {
        $rows = Order::query()
            ->selectRaw('status, COUNT(*) as c')
            ->where('branch_id', $branchId)
            ->whereDate('scheduled_date', $serviceDate)
            ->when(array_key_exists('is_daily_dish', $filters), fn ($q) => $q->where('is_daily_dish', (int) (bool) $filters['is_daily_dish']))
            ->groupBy('status')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->status] = (int) $r->c;
        }

        return $out;
    }

    public function getLastSubscriptionRun(int $branchId, string $serviceDate): ?SubscriptionOrderRun
    {
        return SubscriptionOrderRun::query()
            ->where('branch_id', $branchId)
            ->whereDate('service_date', $serviceDate)
            ->orderByDesc('id')
            ->first();
    }

    public function getRecentSubscriptionRuns(int $branchId, string $serviceDate, int $limit = 10): Collection
    {
        return SubscriptionOrderRun::query()
            ->where('branch_id', $branchId)
            ->whereDate('service_date', $serviceDate)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}


