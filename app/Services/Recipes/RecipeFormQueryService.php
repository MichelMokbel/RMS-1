<?php

namespace App\Services\Recipes;

use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\Recipe;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class RecipeFormQueryService
{
    public function categories(): Collection
    {
        if (! Schema::hasTable('categories')) {
            return collect();
        }

        return Category::with('parent.parent.parent')->orderBy('name')->get();
    }

    public function inventoryItems(): Collection
    {
        if (! Schema::hasTable('inventory_items')) {
            return collect();
        }

        return InventoryItem::orderBy('name')->get();
    }

    public function subRecipes(?int $excludeRecipeId = null): Collection
    {
        if (! Schema::hasTable('recipes')) {
            return collect();
        }

        return Recipe::query()
            ->when($excludeRecipeId, fn ($query) => $query->whereKeyNot($excludeRecipeId))
            ->orderBy('name')
            ->get();
    }
}
