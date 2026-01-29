<?php

namespace App\Services\Inventory;

use App\Models\InventoryStock;
use App\Models\InventoryTransfer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InventoryTransferQueryService
{
    public function branches(): Collection
    {
        if (! Schema::hasTable('branches')) {
            return collect();
        }

        $q = DB::table('branches')->orderBy('name');
        if (Schema::hasColumn('branches', 'is_active')) {
            $q->where('is_active', 1);
        }

        return $q->get();
    }

    public function transfers(string $status = 'all', ?int $branchFilter = null, int $perPage = 15): LengthAwarePaginator
    {
        $query = InventoryTransfer::query()
            ->with('lines')
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($branchFilter, function ($q) use ($branchFilter) {
                $q->where(function ($inner) use ($branchFilter) {
                    $inner->where('from_branch_id', $branchFilter)
                        ->orWhere('to_branch_id', $branchFilter);
                });
            })
            ->orderByDesc('transfer_date')
            ->orderByDesc('id');

        return $query->paginate($perPage);
    }

    /**
     * Map of inventory_item_id => current_stock in the source branch
     */
    public function sourceStocks(?int $fromBranchId, array $lines): Collection
    {
        if (! $fromBranchId || ! Schema::hasTable('inventory_stocks')) {
            return collect();
        }

        $itemIds = collect($lines)->pluck('item_id')->filter()->unique()->values();
        if ($itemIds->isEmpty()) {
            return collect();
        }

        return InventoryStock::query()
            ->where('branch_id', $fromBranchId)
            ->whereIn('inventory_item_id', $itemIds)
            ->pluck('current_stock', 'inventory_item_id');
    }
}

