<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurchaseOrderUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $poId = $this->route('purchaseOrder')?->id ?? null;

        return [
            'po_number' => [
                'required',
                'string',
                'max:50',
                Rule::unique('purchase_orders', 'po_number')->ignore($poId),
            ],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'order_date' => ['nullable', 'date'],
            'expected_delivery_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'status' => ['required', 'in:draft,pending,approved,cancelled,received'],
            'notes' => ['nullable', 'string'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'payment_type' => ['nullable', 'string', 'max:100'],
            'lines' => ['sometimes', 'array', 'min:1'],
            'lines.*.item_id' => ['required_with:lines', 'integer', 'exists:inventory_items,id'],
            'lines.*.quantity' => ['required_with:lines', 'integer', 'min:1'],
            'lines.*.unit_price' => ['required_with:lines', 'numeric', 'min:0'],
        ];
    }
}
