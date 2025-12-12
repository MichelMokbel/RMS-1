<?php

namespace Database\Factories;

use App\Models\InventoryItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class InventoryItemFactory extends Factory
{
    protected $model = InventoryItem::class;

    public function definition(): array
    {
        return [
            'item_code' => $this->faker->unique()->bothify('ITEM-###'),
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->sentence(),
            'category_id' => null,
            'supplier_id' => null,
            'units_per_package' => 1,
            'package_label' => 'pkg',
            'unit_of_measure' => 'unit',
            'minimum_stock' => 0,
            'current_stock' => 0,
            'cost_per_unit' => 10.0000,
            'last_cost_update' => now(),
            'location' => 'A1',
            'image_path' => null,
            'status' => 'active',
        ];
    }

    public function discontinued(): self
    {
        return $this->state(fn () => ['status' => 'discontinued']);
    }
}
