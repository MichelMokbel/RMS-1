<?php

namespace Database\Factories;

use App\Models\Recipe;
use App\Models\RecipeProduction;
use Illuminate\Database\Eloquent\Factories\Factory;

class RecipeProductionFactory extends Factory
{
    protected $model = RecipeProduction::class;

    public function definition(): array
    {
        return [
            'recipe_id' => Recipe::factory(),
            'produced_quantity' => $this->faker->randomFloat(3, 1, 5),
            'production_date' => $this->faker->dateTime(),
            'reference' => $this->faker->optional()->lexify('REF-????'),
            'notes' => $this->faker->optional()->sentence(),
            'created_by' => null,
        ];
    }
}

