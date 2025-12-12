<?php

namespace Database\Factories;

use App\Models\ApInvoice;
use App\Models\ApPayment;
use App\Models\ApPaymentAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

class ApPaymentAllocationFactory extends Factory
{
    protected $model = ApPaymentAllocation::class;

    public function definition(): array
    {
        return [
            'payment_id' => ApPayment::factory(),
            'invoice_id' => ApInvoice::factory(),
            'allocated_amount' => $this->faker->randomFloat(2, 5, 100),
        ];
    }
}
