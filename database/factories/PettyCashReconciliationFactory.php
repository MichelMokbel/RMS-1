<?php

namespace Database\Factories;

use App\Models\PettyCashReconciliation;
use App\Models\PettyCashWallet;
use Illuminate\Database\Eloquent\Factories\Factory;

class PettyCashReconciliationFactory extends Factory
{
    protected $model = PettyCashReconciliation::class;

    public function definition(): array
    {
        $expected = $this->faker->randomFloat(2, 0, 200);
        $counted = $expected + $this->faker->randomFloat(2, -10, 10);

        return [
            'wallet_id' => PettyCashWallet::factory(),
            'period_start' => now()->subDays(7)->toDateString(),
            'period_end' => now()->toDateString(),
            'expected_balance' => $expected,
            'counted_balance' => $counted,
            'variance' => round($counted - $expected, 2),
            'note' => $this->faker->optional()->sentence(),
            'reconciled_by' => null,
            'reconciled_at' => now(),
        ];
    }
}
