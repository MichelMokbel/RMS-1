<?php

namespace App\Services\Inventory;

use App\Models\InventoryItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InventoryItemIndexQueryService
{
    public function paginate(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->query($filters)->paginate($perPage);
    }

    /**
     * @return Builder
     */
    public function query(array $filters): Builder
    {
        $status = (string) ($filters['status'] ?? 'active');
        $categoryId = isset($filters['category_id']) ? (int) $filters['category_id'] : null;
        $supplierId = isset($filters['supplier_id']) ? (int) $filters['supplier_id'] : null;
        $branchId = isset($filters['branch_id']) ? (int) $filters['branch_id'] : null;
        $search = trim((string) ($filters['search'] ?? ''));
        $lowStockOnly = (bool) ($filters['low_stock_only'] ?? false);

        $query = InventoryItem::query()
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($inner) use ($search) {
                    $inner->where('item_code', 'like', '%'.$search.'%')
                        ->orWhere('name', 'like', '%'.$search.'%')
                        ->orWhere('location', 'like', '%'.$search.'%');
                });
            })
            ->orderBy('name');

        // Prefer branch stock when branch filter is set, else show global stock (sum across branches)
        if (Schema::hasTable('inventory_stocks') && $branchId) {
            $query->join('inventory_stocks as inv_stock', function ($join) use ($branchId) {
                $join->on('inventory_items.id', '=', 'inv_stock.inventory_item_id')
                    ->where('inv_stock.branch_id', '=', $branchId);
            })->select('inventory_items.*', DB::raw('COALESCE(inv_stock.current_stock, 0) as current_stock'));
        } elseif (Schema::hasTable('inventory_stocks')) {
            $totals = DB::table('inventory_stocks')
                ->select('inventory_item_id', DB::raw('SUM(current_stock) as total_stock'))
                ->groupBy('inventory_item_id');

            $query->leftJoinSub($totals, 'inv_total', function ($join) {
                $join->on('inventory_items.id', '=', 'inv_total.inventory_item_id');
            })->select('inventory_items.*', DB::raw('COALESCE(inv_total.total_stock, 0) as current_stock'));
        }

        if ($lowStockOnly) {
            if ($branchId) {
                $query->whereRaw('COALESCE(inv_stock.current_stock, 0) <= inventory_items.minimum_stock');
            } elseif (Schema::hasTable('inventory_stocks')) {
                $query->whereRaw('COALESCE(inv_total.total_stock, 0) <= inventory_items.minimum_stock');
            }
        }

        return $query;
    }
}

