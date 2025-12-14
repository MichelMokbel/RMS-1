<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\MenuItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    public function definition(): array
    {
        $qty = $this->faker->randomFloat(3, 1, 3);
        $price = $this->faker->randomFloat(3, 5, 50);

        return [
            'order_id' => Order::factory(),
            'menu_item_id' => MenuItem::factory(),
            'description_snapshot' => $this->faker->words(3, true),
            'quantity' => $qty,
            'unit_price' => $price,
            'discount_amount' => 0,
            'line_total' => round($qty * $price, 3),
            'status' => 'Pending',
            'sort_order' => 0,
        ];
    }
}

