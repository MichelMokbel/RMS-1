<?php

namespace App\Services\Purchasing;

use App\Models\InventoryItem;
use App\Models\Supplier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class PurchaseOrderFormQueryService
{
    public function inventoryItemsArray(): array
    {
        if (! Schema::hasTable('inventory_items')) {
            return [];
        }

        return InventoryItem::orderBy('name')
            ->select('id', 'item_code', 'name', 'cost_per_unit')
            ->get()
            ->map(fn ($item) => [
                'id' => $item->id,
                'item_code' => $item->item_code,
                'name' => $item->name,
                'cost_per_unit' => $item->cost_per_unit,
            ])
            ->toArray();
    }

    public function suppliers(): Collection
    {
        if (! Schema::hasTable('suppliers')) {
            return collect();
        }

        return Supplier::orderBy('name')->get();
    }
}

