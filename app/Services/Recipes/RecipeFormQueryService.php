<?php

namespace App\Services\Recipes;

use App\Models\Category;
use App\Models\InventoryItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class RecipeFormQueryService
{
    public function categories(): Collection
    {
        if (! Schema::hasTable('categories')) {
            return collect();
        }

        return Category::orderBy('name')->get();
    }

    public function inventoryItems(): Collection
    {
        if (! Schema::hasTable('inventory_items')) {
            return collect();
        }

        return InventoryItem::orderBy('name')->get();
    }
}

