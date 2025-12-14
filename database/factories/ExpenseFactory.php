<?php

namespace Database\Factories;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExpenseFactory extends Factory
{
    protected $model = Expense::class;

    public function definition(): array
    {
        $amount = $this->faker->randomFloat(2, 10, 500);
        $tax = $this->faker->randomFloat(2, 0, 50);
        return [
            'category_id' => ExpenseCategory::factory(),
            'supplier_id' => Supplier::factory(),
            'expense_date' => $this->faker->date(),
            'description' => $this->faker->sentence(4),
            'amount' => $amount,
            'tax_amount' => $tax,
            'total_amount' => $amount + $tax,
            'payment_status' => 'unpaid',
            'payment_method' => 'cash',
            'reference' => $this->faker->bothify('REF-####'),
            'notes' => $this->faker->sentence(),
        ];
    }
}
