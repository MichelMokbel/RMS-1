<?php

namespace App\Services\Inventory;

use App\Models\InventoryStock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class InventoryItemStockQueryService
{
    public function branchStock(int $inventoryItemId, int $branchId): float
    {
        if (! Schema::hasTable('inventory_stocks')) {
            return 0.0;
        }

        $val = InventoryStock::where('inventory_item_id', $inventoryItemId)
            ->where('branch_id', $branchId)
            ->value('current_stock');

        return (float) ($val ?? 0);
    }

    public function globalStock(int $inventoryItemId): float
    {
        if (! Schema::hasTable('inventory_stocks')) {
            return 0.0;
        }

        return (float) InventoryStock::where('inventory_item_id', $inventoryItemId)->sum('current_stock');
    }

    /**
     * @return Collection<int, int> branch_id list
     */
    public function availabilityBranchIds(int $inventoryItemId): Collection
    {
        if (! Schema::hasTable('inventory_stocks')) {
            return collect();
        }

        return InventoryStock::where('inventory_item_id', $inventoryItemId)->pluck('branch_id');
    }
}

