<?php

namespace Database\Factories;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class PurchaseOrderItemFactory extends Factory
{
    protected $model = PurchaseOrderItem::class;

    public function definition(): array
    {
        $qty = $this->faker->numberBetween(1, 5);
        $unit = $this->faker->randomFloat(2, 1, 50);

        return [
            'purchase_order_id' => PurchaseOrder::factory(),
            'item_id' => null,
            'quantity' => $qty,
            'unit_price' => $unit,
            'total_price' => $qty * $unit,
            'received_quantity' => 0,
        ];
    }
}
