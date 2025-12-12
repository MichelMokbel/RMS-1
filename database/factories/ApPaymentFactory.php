<?php

namespace Database\Factories;

use App\Models\ApPayment;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

class ApPaymentFactory extends Factory
{
    protected $model = ApPayment::class;

    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'payment_date' => now()->toDateString(),
            'amount' => $this->faker->randomFloat(2, 10, 200),
            'payment_method' => 'bank_transfer',
            'reference' => $this->faker->optional()->bothify('REF-###'),
            'notes' => null,
            'created_by' => null,
        ];
    }
}
