<?php

namespace Database\Factories;

use App\Models\ApInvoice;
use App\Models\ApInvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class ApInvoiceItemFactory extends Factory
{
    protected $model = ApInvoiceItem::class;

    public function definition(): array
    {
        $qty = $this->faker->randomFloat(3, 1, 5);
        $unit = $this->faker->randomFloat(4, 5, 50);

        return [
            'invoice_id' => ApInvoice::factory(),
            'description' => $this->faker->sentence(3),
            'quantity' => $qty,
            'unit_price' => $unit,
            'line_total' => round($qty * $unit, 2),
        ];
    }
}
