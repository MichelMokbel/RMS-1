<?php

namespace Database\Factories;

use App\Models\PosShift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PosShiftFactory extends Factory
{
    protected $model = PosShift::class;

    public function definition(): array
    {
        return [
            'branch_id' => 1,
            'user_id' => User::factory(),
            'active' => true,
            'status' => 'open',
            'opening_cash_cents' => 0,
            'closing_cash_cents' => null,
            'expected_cash_cents' => null,
            'variance_cents' => null,
            'opened_at' => now(),
            'closed_at' => null,
            'notes' => null,
            'created_by' => null,
            'closed_by' => null,
        ];
    }
}

