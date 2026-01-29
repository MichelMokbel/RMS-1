<?php

namespace App\Services\Recipes;

use App\Models\Recipe;

class RecipeShowQueryService
{
    private RecipeCostingService $costing;

    public function __construct(RecipeCostingService $costing)
    {
        $this->costing = $costing;
    }

    public function showData(Recipe $recipe): array
    {
        $recipe->loadMissing(['category', 'items.inventoryItem', 'productions.creator']);

        return [
            'recipe' => $recipe,
            'items' => $recipe->items,
            'productions' => $recipe->productions()->latest('production_date')->limit(20)->get(),
            'costing' => $this->costing->compute($recipe),
        ];
    }
}

