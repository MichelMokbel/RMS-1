<?php

namespace Database\Factories;

use App\Models\PettyCashIssue;
use App\Models\PettyCashWallet;
use Illuminate\Database\Eloquent\Factories\Factory;

class PettyCashIssueFactory extends Factory
{
    protected $model = PettyCashIssue::class;

    public function definition(): array
    {
        return [
            'wallet_id' => PettyCashWallet::factory(),
            'issue_date' => now()->toDateString(),
            'amount' => $this->faker->randomFloat(2, 1, 100),
            'method' => 'cash',
            'bank_account_id' => null,
            'reference' => $this->faker->optional()->lexify('REF-????'),
            'issued_by' => null,
        ];
    }
}
