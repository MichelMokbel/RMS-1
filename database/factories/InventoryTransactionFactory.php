<?php

namespace Database\Factories;

use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

class InventoryTransactionFactory extends Factory
{
    protected $model = InventoryTransaction::class;

    public function definition(): array
    {
        return [
            'item_id' => InventoryItem::factory(),
            'transaction_type' => 'in',
            'quantity' => 5,
            'reference_type' => 'manual',
            'reference_id' => null,
            'user_id' => null,
            'notes' => $this->faker->sentence(),
            'transaction_date' => now(),
        ];
    }
}
