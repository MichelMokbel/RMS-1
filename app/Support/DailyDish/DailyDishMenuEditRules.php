<?php

namespace App\Support\DailyDish;

class DailyDishMenuEditRules
{
    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.menu_item_id' => ['required', 'integer'],
            'items.*.role' => ['required', 'in:main,diet,vegetarian,salad,dessert,addon,appetizer,water'],
            'items.*.sort_order' => ['nullable', 'integer'],
            'items.*.is_required' => ['boolean'],
        ];
    }
}

