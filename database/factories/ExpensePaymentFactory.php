<?php

namespace Database\Factories;

use App\Models\Expense;
use App\Models\ExpensePayment;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExpensePaymentFactory extends Factory
{
    protected $model = ExpensePayment::class;

    public function definition(): array
    {
        return [
            'expense_id' => Expense::factory(),
            'payment_date' => $this->faker->date(),
            'amount' => $this->faker->randomFloat(2, 5, 300),
            'payment_method' => 'cash',
            'reference' => $this->faker->bothify('PAY-####'),
            'notes' => $this->faker->sentence(),
        ];
    }
}
