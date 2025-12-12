<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PurchaseOrderStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'po_number' => ['required', 'string', 'max:50', 'unique:purchase_orders,po_number'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'order_date' => ['nullable', 'date'],
            'expected_delivery_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'status' => ['required', 'in:draft,pending'],
            'notes' => ['nullable', 'string'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'payment_type' => ['nullable', 'string', 'max:100'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer', 'exists:inventory_items,id'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
        ];
    }
}
