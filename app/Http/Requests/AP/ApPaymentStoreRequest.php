<?php

namespace App\Http\Requests\AP;

use Illuminate\Foundation\Http\FormRequest;

class ApPaymentStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'payment_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['nullable', 'in:cash,bank_transfer,card,cheque,other'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
            'allocations' => ['sometimes', 'array'],
            'allocations.*.invoice_id' => ['required_with:allocations', 'integer', 'exists:ap_invoices,id'],
            'allocations.*.allocated_amount' => ['required_with:allocations', 'numeric', 'min:0.01'],
        ];
    }
}
