<?php

namespace Database\Factories;

use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'order_number' => 'ORD'.now()->format('Y').'-'.$this->faker->unique()->numerify('######'),
            'branch_id' => 1,
            'source' => 'Backoffice',
            'is_daily_dish' => false,
            'type' => 'Delivery',
            'status' => 'Draft',
            'customer_id' => null,
            'customer_name_snapshot' => $this->faker->name(),
            'customer_phone_snapshot' => $this->faker->phoneNumber(),
            'delivery_address_snapshot' => $this->faker->address(),
            'scheduled_date' => now()->toDateString(),
            'scheduled_time' => now()->format('H:i:s'),
            'notes' => null,
            'total_before_tax' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
            'created_by' => 1,
            'created_at' => now(),
        ];
    }

    public function dailyDish(): self
    {
        return $this->state(fn () => ['is_daily_dish' => true]);
    }

    public function subscription(): self
    {
        return $this->state(fn () => ['source' => 'Subscription', 'is_daily_dish' => true]);
    }
}

