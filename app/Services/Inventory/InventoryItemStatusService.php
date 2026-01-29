<?php

namespace App\Services\Inventory;

use App\Models\InventoryItem;

class InventoryItemStatusService
{
    public function toggle(InventoryItem $item): InventoryItem
    {
        $item->update([
            'status' => $item->status === 'active' ? 'discontinued' : 'active',
        ]);

        return $item->fresh();
    }
}

