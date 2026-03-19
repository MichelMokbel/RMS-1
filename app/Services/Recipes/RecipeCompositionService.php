<?php

namespace App\Services\Recipes;

use App\Models\InventoryItem;
use App\Models\Recipe;
use App\Models\RecipeItem;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class RecipeCompositionService
{
    public function normalizeItems(array $items): array
    {
        return collect($items)->map(function (array $item) {
            $sourceType = (string) ($item['source_type'] ?? ($item['sub_recipe_id'] ? 'sub_recipe' : 'inventory_item'));

            return [
                'source_type' => $sourceType,
                'inventory_item_id' => $sourceType === 'inventory_item'
                    ? (isset($item['inventory_item_id']) && $item['inventory_item_id'] !== '' ? (int) $item['inventory_item_id'] : null)
                    : null,
                'sub_recipe_id' => $sourceType === 'sub_recipe'
                    ? (isset($item['sub_recipe_id']) && $item['sub_recipe_id'] !== '' ? (int) $item['sub_recipe_id'] : null)
                    : null,
                'quantity' => (float) ($item['quantity'] ?? 0),
                'unit' => (string) ($item['unit'] ?? ''),
                'quantity_type' => (string) ($item['quantity_type'] ?? 'unit'),
                'cost_type' => (string) ($item['cost_type'] ?? 'ingredient'),
            ];
        })->values()->all();
    }

    public function assertValidComposition(?Recipe $recipe, array $items): void
    {
        foreach ($items as $index => $item) {
            $sourceType = $item['source_type'] ?? 'inventory_item';
            $inventoryItemId = $item['inventory_item_id'] ?? null;
            $subRecipeId = $item['sub_recipe_id'] ?? null;

            if ($sourceType === 'inventory_item' && ! $inventoryItemId) {
                throw ValidationException::withMessages([
                    'items.'.$index.'.inventory_item_id' => __('Select an inventory item.'),
                ]);
            }

            if ($sourceType === 'sub_recipe' && ! $subRecipeId) {
                throw ValidationException::withMessages([
                    'items.'.$index.'.sub_recipe_id' => __('Select a sub recipe.'),
                ]);
            }

            if ($sourceType === 'sub_recipe') {
                if ($recipe && (int) $subRecipeId === (int) $recipe->id) {
                    throw ValidationException::withMessages([
                        'items.'.$index.'.sub_recipe_id' => __('A recipe cannot include itself as a sub recipe.'),
                    ]);
                }

                if ($recipe && $this->createsCycle((int) $recipe->id, (int) $subRecipeId)) {
                    throw ValidationException::withMessages([
                        'items.'.$index.'.sub_recipe_id' => __('This sub recipe would create a cycle.'),
                    ]);
                }
            }
        }
    }

    public function explodeIngredients(Recipe $recipe, float $factor = 1.0, array $stack = []): Collection
    {
        $recipe->loadMissing(['items.inventoryItem', 'items.subRecipe']);
        $stack[] = $recipe->id;

        return $recipe->items->flatMap(function (RecipeItem $item) use ($factor, $stack, $recipe) {
            if ($item->sub_recipe_id) {
                $subRecipe = $item->subRecipe;
                if (! $subRecipe) {
                    throw ValidationException::withMessages([
                        'sub_recipe_id' => __('Sub recipe not found.'),
                    ]);
                }

                if (in_array($subRecipe->id, $stack, true)) {
                    throw ValidationException::withMessages([
                        'sub_recipe_id' => __('Recipe nesting cycle detected.'),
                    ]);
                }

                if (! $subRecipe->yieldIsValid()) {
                    throw ValidationException::withMessages([
                        'yield_quantity' => __('Sub recipe :name must have a valid yield quantity.', ['name' => $subRecipe->name]),
                    ]);
                }

                $nestedFactor = $factor * ((float) $item->quantity / (float) $subRecipe->yield_quantity);

                return $this->explodeIngredients($subRecipe, $nestedFactor, $stack)->map(function (array $row) use ($item, $recipe, $subRecipe) {
                    $row['path'] = array_merge($row['path'] ?? [], [[
                        'recipe_id' => $recipe->id,
                        'recipe_name' => $recipe->name,
                        'sub_recipe_id' => $subRecipe->id,
                        'sub_recipe_name' => $subRecipe->name,
                        'cost_type' => $item->cost_type,
                    ]]);

                    return $row;
                });
            }

            $inventoryItem = $item->inventoryItem;
            if (! $inventoryItem instanceof InventoryItem) {
                throw ValidationException::withMessages([
                    'inventory_item_id' => __('Inventory item for ingredient is missing.'),
                ]);
            }

            return [[
                'inventory_item_id' => $inventoryItem->id,
                'inventory_item' => $inventoryItem,
                'scaled_quantity' => round((float) $item->quantity * $factor, 3),
                'unit' => (string) $item->unit,
                'quantity_type' => (string) $item->quantity_type,
                'cost_type' => (string) $item->cost_type,
                'source_recipe_id' => $recipe->id,
                'source_recipe_name' => $recipe->name,
                'path' => [],
            ]];
        })->values();
    }

    private function createsCycle(int $rootRecipeId, int $candidateSubRecipeId): bool
    {
        if ($rootRecipeId <= 0 || $candidateSubRecipeId <= 0) {
            return false;
        }

        $visited = [];
        $stack = [$candidateSubRecipeId];

        while ($stack !== []) {
            $recipeId = array_pop($stack);
            if (! $recipeId || isset($visited[$recipeId])) {
                continue;
            }

            if ($recipeId === $rootRecipeId) {
                return true;
            }

            $visited[$recipeId] = true;

            RecipeItem::query()
                ->where('recipe_id', $recipeId)
                ->whereNotNull('sub_recipe_id')
                ->pluck('sub_recipe_id')
                ->each(function ($subRecipeId) use (&$stack) {
                    $stack[] = (int) $subRecipeId;
                });
        }

        return false;
    }
}
