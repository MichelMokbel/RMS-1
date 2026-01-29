<?php

namespace App\Services\Recipes;

use App\Models\Recipe;

class RecipeProductionQueryService
{
    public function findForProduction(int $recipeId): Recipe
    {
        return Recipe::with(['items.inventoryItem'])->findOrFail($recipeId);
    }
}

