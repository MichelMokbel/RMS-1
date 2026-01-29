<?php

namespace App\Services\Recipes;

use App\Models\Recipe;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class RecipeIndexQueryService
{
    public function paginate(?string $search, ?int $categoryId, int $perPage = 15): LengthAwarePaginator
    {
        $search = trim((string) $search);

        return Recipe::query()
            ->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.$search.'%'))
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->orderBy('name')
            ->paginate($perPage);
    }
}

