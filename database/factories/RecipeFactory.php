<?php

namespace Database\Factories;

use App\Models\Recipe;
use Illuminate\Database\Eloquent\Factories\Factory;

class RecipeFactory extends Factory
{
    protected $model = Recipe::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->words(3, true),
            'description' => $this->faker->sentence(),
            'category_id' => null,
            'yield_quantity' => $this->faker->randomFloat(3, 1, 10),
            'yield_unit' => 'unit',
            'overhead_pct' => $this->faker->randomFloat(4, 0, 0.2),
            'selling_price_per_unit' => $this->faker->randomFloat(2, 5, 20),
        ];
    }
}

