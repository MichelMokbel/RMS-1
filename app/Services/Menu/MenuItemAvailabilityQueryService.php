<?php

namespace App\Services\Menu;

use App\Models\MenuItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MenuItemAvailabilityQueryService
{
    public function ensureDefaultsForBranch(int $branchId): void
    {
        if ($branchId <= 0) {
            return;
        }
        if (! Schema::hasTable('menu_item_branches') || ! Schema::hasTable('menu_items')) {
            return;
        }

        $items = DB::table('menu_items')->pluck('id')->all();
        if (empty($items)) {
            return;
        }

        $now = now();
        $rows = array_map(fn ($id) => [
            'menu_item_id' => $id,
            'branch_id' => $branchId,
            'created_at' => $now,
            'updated_at' => $now,
        ], $items);

        DB::table('menu_item_branches')->insertOrIgnore($rows);
    }

    public function paginateItems(string $search, string $sortDirection, int $perPage): LengthAwarePaginator
    {
        $search = trim((string) $search);
        $dir = $sortDirection === 'desc' ? 'desc' : 'asc';
        $perPage = max(min((int) $perPage, 200), 1);

        return MenuItem::query()
            ->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.$search.'%'))
            ->orderBy('name', $dir)
            ->paginate($perPage, ['id', 'name']);
    }

    /**
     * @return array<int, array<int, bool>> [menu_item_id][branch_id] => true
     */
    public function availabilityMap(array $menuItemIds, array $branchIds): array
    {
        if (! Schema::hasTable('menu_item_branches')) {
            return [];
        }

        $menuItemIds = array_values(array_filter(array_map('intval', $menuItemIds)));
        $branchIds = array_values(array_filter(array_map('intval', $branchIds)));
        if (empty($menuItemIds) || empty($branchIds)) {
            return [];
        }

        $rows = DB::table('menu_item_branches')
            ->whereIn('branch_id', $branchIds)
            ->whereIn('menu_item_id', $menuItemIds)
            ->get(['menu_item_id', 'branch_id']);

        $availability = [];
        foreach ($rows as $row) {
            $availability[$row->menu_item_id][$row->branch_id] = true;
        }

        return $availability;
    }

    /**
     * @return array<int, string>
     */
    public function branchLabels(array $branchIds): array
    {
        $branchIds = array_values(array_filter(array_map('intval', $branchIds)));
        if (empty($branchIds) || ! Schema::hasTable('branches')) {
            return [];
        }

        $branches = DB::table('branches')
            ->whereIn('id', $branchIds)
            ->orderBy('id')
            ->get();

        $labels = [];
        foreach ($branchIds as $id) {
            $labels[$id] = $branches->firstWhere('id', $id)?->name ?? __('Branch :id', ['id' => $id]);
        }

        return $labels;
    }
}

