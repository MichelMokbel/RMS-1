<?php

namespace Database\Factories;

use App\Models\MenuItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class MenuItemFactory extends Factory
{
    protected $model = MenuItem::class;

    public function definition(): array
    {
        return [
            'code' => $this->faker->unique()->bothify('MI-###'),
            'name' => $this->faker->words(3, true),
            'arabic_name' => $this->faker->words(3, true),
            'category_id' => null,
            'recipe_id' => null,
            'selling_price_per_unit' => $this->faker->randomFloat(3, 0, 100),
            'unit' => $this->faker->randomElement(['each', 'dozen', 'kg']),
            'tax_rate' => $this->faker->randomFloat(2, 0, 15),
            'is_active' => true,
            'display_order' => $this->faker->numberBetween(0, 100),
        ];
    }

    public function inactive(): self
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
