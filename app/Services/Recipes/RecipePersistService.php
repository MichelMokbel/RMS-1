<?php

namespace App\Services\Recipes;

use App\Models\Recipe;
use App\Models\RecipeItem;
use Illuminate\Support\Facades\DB;

class RecipePersistService
{
    public function create(array $data): Recipe
    {
        return DB::transaction(function () use ($data) {
            $recipe = Recipe::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'yield_quantity' => $data['yield_quantity'],
                'yield_unit' => $data['yield_unit'],
                'overhead_pct' => $data['overhead_pct'],
                'selling_price_per_unit' => $data['selling_price_per_unit'] ?? null,
            ]);

            foreach (($data['items'] ?? []) as $item) {
                RecipeItem::create([
                    'recipe_id' => $recipe->id,
                    'inventory_item_id' => $item['inventory_item_id'],
                    'quantity' => $item['quantity'],
                    'unit' => $item['unit'],
                    'quantity_type' => $item['quantity_type'],
                    'cost_type' => $item['cost_type'],
                ]);
            }

            return $recipe->fresh();
        });
    }

    public function update(Recipe $recipe, array $data): Recipe
    {
        return DB::transaction(function () use ($recipe, $data) {
            $recipe->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'yield_quantity' => $data['yield_quantity'],
                'yield_unit' => $data['yield_unit'],
                'overhead_pct' => $data['overhead_pct'],
                'selling_price_per_unit' => $data['selling_price_per_unit'] ?? null,
            ]);

            // simplest sync: delete and recreate items
            $recipe->items()->delete();

            foreach (($data['items'] ?? []) as $item) {
                RecipeItem::create([
                    'recipe_id' => $recipe->id,
                    'inventory_item_id' => $item['inventory_item_id'],
                    'quantity' => $item['quantity'],
                    'unit' => $item['unit'],
                    'quantity_type' => $item['quantity_type'],
                    'cost_type' => $item['cost_type'],
                ]);
            }

            return $recipe->fresh();
        });
    }
}

