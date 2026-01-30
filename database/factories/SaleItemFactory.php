<?php

namespace Database\Factories;

use App\Models\MenuItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Support\Money\MinorUnits;
use Illuminate\Database\Eloquent\Factories\Factory;

class SaleItemFactory extends Factory
{
    protected $model = SaleItem::class;

    public function definition(): array
    {
        $unit = 1000;
        $qtyMilli = 1000;
        $subtotal = MinorUnits::mulQty($unit, $qtyMilli);

        return [
            'sale_id' => Sale::factory(),
            'sellable_type' => MenuItem::class,
            'sellable_id' => MenuItem::factory(),
            'name_snapshot' => $this->faker->words(3, true),
            'sku_snapshot' => $this->faker->bothify('SKU-####'),
            'tax_rate_bps' => 0,
            'qty' => '1.000',
            'unit_price_cents' => $unit,
            'discount_cents' => 0,
            'discount_type' => 'fixed',
            'discount_value' => 0,
            'tax_cents' => 0,
            'line_total_cents' => $subtotal,
            'meta' => null,
        ];
    }
}

