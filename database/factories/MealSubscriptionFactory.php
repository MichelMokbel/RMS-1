<?php

namespace Database\Factories;

use App\Models\MealSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;

class MealSubscriptionFactory extends Factory
{
    protected $model = MealSubscription::class;

    public function definition(): array
    {
        return [
            'subscription_code' => 'SUB-'.$this->faker->unique()->numerify('2025-######'),
            'customer_id' => 1,
            'branch_id' => 1,
            'status' => 'active',
            'start_date' => now()->toDateString(),
            'end_date' => null,
            'default_order_type' => 'Delivery',
            'delivery_time' => null,
            'address_snapshot' => $this->faker->address(),
            'phone_snapshot' => $this->faker->phoneNumber(),
            'preferred_role' => 'main',
            'include_salad' => true,
            'include_dessert' => true,
            'notes' => null,
            'created_by' => null,
        ];
    }
}

