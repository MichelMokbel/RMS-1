<?php

namespace Database\Factories;

use App\Models\ArInvoice;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

class ArInvoiceFactory extends Factory
{
    protected $model = ArInvoice::class;

    public function definition(): array
    {
        return [
            'branch_id' => 1,
            'customer_id' => Customer::factory(),
            'source_sale_id' => null,
            'type' => 'invoice',
            'invoice_number' => null,
            'status' => 'draft',
            'payment_type' => 'credit',
            'payment_term_id' => null,
            'payment_term_days' => 0,
            'sales_person_id' => null,
            'lpo_reference' => null,
            'issue_date' => null,
            'due_date' => null,
            'currency' => 'KWD',
            'subtotal_cents' => 0,
            'discount_total_cents' => 0,
            'invoice_discount_type' => 'fixed',
            'invoice_discount_value' => 0,
            'invoice_discount_cents' => 0,
            'tax_total_cents' => 0,
            'total_cents' => 0,
            'paid_total_cents' => 0,
            'balance_cents' => 0,
            'notes' => null,
            'created_by' => null,
            'updated_by' => null,
            'voided_at' => null,
            'voided_by' => null,
            'void_reason' => null,
        ];
    }

    public function issued(): self
    {
        return $this->state(fn () => [
            'status' => 'issued',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'invoice_number' => 'INV'.now()->format('Y').'-000001',
        ]);
    }

    public function creditNote(): self
    {
        return $this->state(fn () => [
            'type' => 'credit_note',
            'status' => 'issued',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'invoice_number' => 'CRN'.now()->format('Y').'-000001',
        ]);
    }
}

