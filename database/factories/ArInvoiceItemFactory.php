<?php

namespace Database\Factories;

use App\Models\ArInvoice;
use App\Models\ArInvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class ArInvoiceItemFactory extends Factory
{
    protected $model = ArInvoiceItem::class;

    public function definition(): array
    {
        return [
            'invoice_id' => ArInvoice::factory(),
            'description' => $this->faker->words(3, true),
            'qty' => '1.000',
            'unit' => null,
            'unit_price_cents' => 1000,
            'discount_cents' => 0,
            'tax_cents' => 0,
            'line_total_cents' => 1000,
            'line_notes' => null,
            'sellable_type' => null,
            'sellable_id' => null,
            'name_snapshot' => null,
            'sku_snapshot' => null,
            'meta' => null,
        ];
    }
}

