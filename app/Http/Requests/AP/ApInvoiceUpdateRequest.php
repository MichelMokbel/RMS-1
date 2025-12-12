<?php

namespace App\Http\Requests\AP;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class ApInvoiceUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $invoice = $this->route('invoice');
        $supplierId = $this->input('supplier_id', $invoice?->supplier_id);

        return [
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'purchase_order_id' => ['nullable', 'integer', Rule::exists('purchase_orders', 'id')],
            'category_id' => ['nullable', 'integer', Rule::exists('expense_categories', 'id')],
            'is_expense' => ['required', 'boolean'],
            'invoice_number' => [
                'required',
                'string',
                'max:100',
                Rule::unique('ap_invoices', 'invoice_number')
                    ->where(fn ($q) => $q->where('supplier_id', $supplierId))
                    ->ignore($invoice?->id),
            ],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:invoice_date'],
            'tax_amount' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($v) {
            if ($this->boolean('is_expense') && Schema::hasTable('expense_categories') && empty($this->category_id)) {
                $v->errors()->add('category_id', __('Category is required for expenses.'));
            }
        });
    }
}
