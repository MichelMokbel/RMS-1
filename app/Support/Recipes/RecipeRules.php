<?php

namespace App\Support\Recipes;

class RecipeRules
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'yield_quantity' => ['required', 'numeric', 'min:0.001'],
            'yield_unit' => ['required', 'string', 'max:50'],
            'overhead_pct' => ['required', 'numeric', 'min:0'],
            'selling_price_per_unit' => ['nullable', 'numeric', 'min:0'],
            'items' => ['array', 'min:1'],
            'items.*.inventory_item_id' => ['required', 'integer', 'exists:inventory_items,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit' => ['required', 'string', 'max:50'],
            'items.*.quantity_type' => ['required', 'in:unit,package'],
            'items.*.cost_type' => ['required', 'in:ingredient,packaging,labour,transport,other'],
        ];
    }
}

