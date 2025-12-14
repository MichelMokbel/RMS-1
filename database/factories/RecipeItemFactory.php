<?php

namespace Database\Factories;

use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\InventoryItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class RecipeItemFactory extends Factory
{
    protected $model = RecipeItem::class;

    public function definition(): array
    {
        return [
            'recipe_id' => Recipe::factory(),
            'inventory_item_id' => InventoryItem::factory(),
            'quantity' => $this->faker->randomFloat(3, 0.5, 5),
            'unit' => 'unit',
            'quantity_type' => 'unit',
            'cost_type' => 'ingredient',
        ];
    }
}

