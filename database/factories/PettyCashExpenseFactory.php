<?php

namespace Database\Factories;

use App\Models\ExpenseCategory;
use App\Models\PettyCashExpense;
use App\Models\PettyCashWallet;
use Illuminate\Database\Eloquent\Factories\Factory;

class PettyCashExpenseFactory extends Factory
{
    protected $model = PettyCashExpense::class;

    public function definition(): array
    {
        $amount = $this->faker->randomFloat(2, 1, 100);
        $tax = $this->faker->randomFloat(2, 0, 10);

        return [
            'wallet_id' => PettyCashWallet::factory(),
            'category_id' => ExpenseCategory::factory(),
            'expense_date' => now()->toDateString(),
            'description' => $this->faker->sentence(3),
            'amount' => $amount,
            'tax_amount' => $tax,
            'total_amount' => round($amount + $tax, 2),
            'status' => 'submitted',
            'receipt_path' => null,
            'submitted_by' => null,
            'approved_by' => null,
            'approved_at' => null,
        ];
    }
}
