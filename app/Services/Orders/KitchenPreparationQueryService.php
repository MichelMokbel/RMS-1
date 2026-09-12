<?php

namespace App\Services\Orders;

use App\Models\Branch;
use App\Models\User;
use App\Services\Security\BranchAccessService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class KitchenPreparationQueryService
{
    public function __construct(
        private readonly BranchAccessService $branchAccess,
    ) {}

    /** @return Collection<int, Branch> */
    public function availableBranches(User $actor): Collection
    {
        $query = Branch::query()
            ->where('is_active', true)
            ->orderBy('name');

        $this->branchAccess->applyBranchScope($query, $actor, 'id');

        return $query->get(['id', 'name']);
    }

    public function assertCanView(User $actor, int $branchId): void
    {
        abort_unless(
            $actor->hasAnyRole(['admin', 'manager']) || $actor->can('kitchen.display'),
            403
        );
        abort_unless($this->branchAccess->canAccessBranch($actor, $branchId), 403);
        abort_unless(
            Branch::query()->whereKey($branchId)->where('is_active', true)->exists(),
            404
        );
    }

    /** @return Collection<int, object> */
    public function totalsForDay(User $actor, int $branchId, string $serviceDate): Collection
    {
        $this->assertCanView($actor, $branchId);

        return collect(DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('daily_dish_menus', function ($join) use ($serviceDate): void {
                $join->on('daily_dish_menus.branch_id', '=', 'orders.branch_id')
                    ->whereDate('daily_dish_menus.service_date', $serviceDate);
            })
            ->leftJoin('daily_dish_menu_items', function ($join): void {
                $join->on('daily_dish_menu_items.daily_dish_menu_id', '=', 'daily_dish_menus.id')
                    ->on('daily_dish_menu_items.menu_item_id', '=', 'order_items.menu_item_id');
            })
            ->where('orders.branch_id', $branchId)
            ->whereDate('orders.scheduled_date', $serviceDate)
            ->where('orders.status', '!=', 'Cancelled')
            ->selectRaw('daily_dish_menu_items.role as role')
            ->selectRaw('order_items.menu_item_id as menu_item_id')
            ->selectRaw('order_items.description_snapshot as description_snapshot')
            ->selectRaw('SUM(order_items.quantity) as total_quantity')
            ->groupBy('daily_dish_menu_items.role', 'order_items.menu_item_id', 'order_items.description_snapshot')
            ->orderByRaw('CASE WHEN daily_dish_menu_items.role IS NULL THEN 1 ELSE 0 END')
            ->orderBy('daily_dish_menu_items.role')
            ->orderBy('order_items.description_snapshot')
            ->get());
    }
}
