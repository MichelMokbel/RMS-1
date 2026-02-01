<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Factories\Factory;

class SaleFactory extends Factory
{
    protected $model = Sale::class;

    public function definition(): array
    {
        return [
            'branch_id' => 1,
            'pos_shift_id' => null,
            'customer_id' => Customer::factory(),
            'sale_number' => null,
            'status' => 'open',
            'order_type' => 'takeaway',
            'currency' => (string) config('pos.currency'),
            'subtotal_cents' => 0,
            'discount_total_cents' => 0,
            'global_discount_cents' => 0,
            'global_discount_type' => 'fixed',
            'global_discount_value' => 0,
            'is_credit' => false,
            'credit_invoice_id' => null,
            'pos_date' => now()->toDateString(),
            'tax_total_cents' => 0,
            'total_cents' => 0,
            'paid_total_cents' => 0,
            'due_total_cents' => 0,
            'notes' => null,
            'created_by' => null,
            'updated_by' => null,
            'closed_at' => null,
            'closed_by' => null,
            'voided_at' => null,
            'voided_by' => null,
            'void_reason' => null,
        ];
    }

    public function walkIn(): self
    {
        return $this->state(fn () => ['customer_id' => null]);
    }
}

