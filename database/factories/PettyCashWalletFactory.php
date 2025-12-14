<?php

namespace Database\Factories;

use App\Models\PettyCashWallet;
use Illuminate\Database\Eloquent\Factories\Factory;

class PettyCashWalletFactory extends Factory
{
    protected $model = PettyCashWallet::class;

    public function definition(): array
    {
        return [
            'driver_id' => $this->faker->numberBetween(1000, 9999),
            'driver_name' => $this->faker->name(),
            'target_float' => 0,
            'balance' => 0,
            'active' => true,
            'created_by' => null,
        ];
    }
}
