<?php

namespace App\Support\Recipes;

class RecipeProduceRules
{
    public function rules(): array
    {
        return [
            'produce_recipe_id' => ['required', 'integer', 'exists:recipes,id'],
            'produce_quantity' => ['required', 'numeric', 'min:0.001'],
            'produce_date' => ['nullable', 'date'],
            'produce_reference' => ['nullable', 'string', 'max:100'],
            'produce_notes' => ['nullable', 'string'],
        ];
    }
}

