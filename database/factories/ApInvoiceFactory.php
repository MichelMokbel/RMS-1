<?php

namespace Database\Factories;

use App\Models\ApInvoice;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

class ApInvoiceFactory extends Factory
{
    protected $model = ApInvoice::class;

    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'is_expense' => false,
            'invoice_number' => $this->faker->unique()->numerify('INV-#####'),
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'subtotal' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
            'status' => 'draft',
            'notes' => null,
            'created_by' => null,
        ];
    }
}
