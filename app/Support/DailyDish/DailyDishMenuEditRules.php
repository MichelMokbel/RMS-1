<?php

namespace App\Support\DailyDish;

class DailyDishMenuEditRules
{
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'size:5'],
            'items.*.menu_item_id' => ['nullable', 'integer'],
            'items.*.role' => ['required', 'in:main,salad,dessert'],
            'items.*.sort_order' => ['required', 'integer'],
            'items.*.is_required' => ['boolean'],
        ];
    }
}
