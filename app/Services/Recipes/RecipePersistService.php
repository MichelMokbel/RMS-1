<?php

namespace App\Services\Recipes;

use App\Models\Recipe;
use App\Models\RecipeItem;
use Illuminate\Support\Facades\DB;

class RecipePersistService
{
    public function __construct(
        private readonly RecipeCompositionService $composition,
    ) {
    }

    public function create(array $data): Recipe
    {
        return DB::transaction(function () use ($data) {
            $items = $this->composition->normalizeItems($data['items'] ?? []);
            $this->composition->assertValidComposition(null, $items);

            $recipe = Recipe::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'yield_quantity' => $data['yield_quantity'],
                'yield_unit' => $data['yield_unit'],
                'overhead_pct' => $data['overhead_pct'],
                'selling_price_per_unit' => $data['selling_price_per_unit'] ?? null,
                'status' => $data['status'] ?? 'published',
            ]);

            $this->composition->assertValidComposition($recipe, $items);

            foreach ($items as $item) {
                RecipeItem::create([
                    'recipe_id' => $recipe->id,
                    'inventory_item_id' => $item['inventory_item_id'],
                    'sub_recipe_id' => $item['sub_recipe_id'],
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
            $items = $this->composition->normalizeItems($data['items'] ?? []);
            $this->composition->assertValidComposition($recipe, $items);

            $recipe->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'yield_quantity' => $data['yield_quantity'],
                'yield_unit' => $data['yield_unit'],
                'overhead_pct' => $data['overhead_pct'],
                'selling_price_per_unit' => $data['selling_price_per_unit'] ?? null,
                'status' => $data['status'] ?? ((string) ($recipe->status ?? 'published')),
            ]);

            // simplest sync: delete and recreate items
            $recipe->items()->delete();

            foreach ($items as $item) {
                RecipeItem::create([
                    'recipe_id' => $recipe->id,
                    'inventory_item_id' => $item['inventory_item_id'],
                    'sub_recipe_id' => $item['sub_recipe_id'],
                    'quantity' => $item['quantity'],
                    'unit' => $item['unit'],
                    'quantity_type' => $item['quantity_type'],
                    'cost_type' => $item['cost_type'],
                ]);
            }

            return $recipe->fresh(['items.inventoryItem', 'items.subRecipe']);
        });
    }
}
