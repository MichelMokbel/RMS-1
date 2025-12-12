<?php

namespace Database\Factories;

use App\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PurchaseOrderFactory extends Factory
{
    protected $model = PurchaseOrder::class;

    public function definition(): array
    {
        return [
            'po_number' => 'PO-'.$this->faker->unique()->numerify('######'),
            'supplier_id' => null,
            'order_date' => $this->faker->date(),
            'expected_delivery_date' => $this->faker->date(),
            'status' => PurchaseOrder::STATUS_DRAFT,
            'total_amount' => 0,
            'notes' => $this->faker->sentence(),
            'created_by' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => PurchaseOrder::STATUS_PENDING]);
    }

    public function approved(): static
    {
        return $this->state(fn () => ['status' => PurchaseOrder::STATUS_APPROVED]);
    }
}
