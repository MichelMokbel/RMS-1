<?php

namespace App\Services\Inventory;

use App\Models\InventoryItem;
use App\Models\InventoryStock;

class InventoryAvailabilityService
{
    public function addToBranch(InventoryItem $item, int $branchId): InventoryStock
    {
        $branchId = $branchId > 0 ? $branchId : (int) config('inventory.default_branch_id', 1);

        return InventoryStock::firstOrCreate(
            ['inventory_item_id' => $item->id, 'branch_id' => $branchId],
            ['current_stock' => 0]
        );
    }
}
