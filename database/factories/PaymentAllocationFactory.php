<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentAllocationFactory extends Factory
{
    protected $model = PaymentAllocation::class;

    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'allocatable_type' => Sale::class,
            'allocatable_id' => Sale::factory(),
            'amount_cents' => 1000,
            'voided_at' => null,
            'voided_by' => null,
            'void_reason' => null,
        ];
    }
}

