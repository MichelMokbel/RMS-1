<?php

namespace App\Support\Purchasing;

use Illuminate\Validation\Rule;

class PurchaseOrderRules
{
    public function createRules(): array
    {
        return $this->rulesBase(ignoreId: null);
    }

    public function updateRules(int $purchaseOrderId): array
    {
        return $this->rulesBase(ignoreId: $purchaseOrderId);
    }

    private function rulesBase(?int $ignoreId): array
    {
        $poUnique = $ignoreId
            ? Rule::unique('purchase_orders', 'po_number')->ignore($ignoreId)
            : Rule::unique('purchase_orders', 'po_number');

        return [
            'po_number' => ['required', 'string', 'max:50', $poUnique],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'order_date' => ['nullable', 'date'],
            'expected_delivery_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'notes' => ['nullable', 'string'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'payment_type' => ['nullable', 'string', 'max:100'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer', 'exists:inventory_items,id'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
        ];
    }
}

