<?php

namespace Database\Factories;

use App\Models\MealSubscription;
use App\Models\MealSubscriptionPause;
use Illuminate\Database\Eloquent\Factories\Factory;

class MealSubscriptionPauseFactory extends Factory
{
    protected $model = MealSubscriptionPause::class;

    public function definition(): array
    {
        $start = now()->addDays(1)->toDateString();
        $end = now()->addDays(3)->toDateString();

        return [
            'subscription_id' => MealSubscription::factory(),
            'pause_start' => $start,
            'pause_end' => $end,
            'reason' => $this->faker->optional()->sentence(),
            'created_by' => null,
        ];
    }
}

