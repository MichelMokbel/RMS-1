<?php

namespace Database\Factories;

use App\Models\MealSubscription;
use App\Models\MealSubscriptionDay;
use Illuminate\Database\Eloquent\Factories\Factory;

class MealSubscriptionDayFactory extends Factory
{
    protected $model = MealSubscriptionDay::class;

    public function definition(): array
    {
        return [
            'subscription_id' => MealSubscription::factory(),
            'weekday' => $this->faker->numberBetween(1, 7),
        ];
    }
}

