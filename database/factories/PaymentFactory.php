<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'branch_id' => 1,
            'customer_id' => Customer::factory(),
            'source' => 'pos',
            'method' => 'cash',
            'amount_cents' => 1000,
            'currency' => (string) config('pos.currency'),
            'received_at' => now(),
            'reference' => null,
            'notes' => null,
            'created_by' => null,
            'voided_at' => null,
            'voided_by' => null,
            'void_reason' => null,
        ];
    }
}

