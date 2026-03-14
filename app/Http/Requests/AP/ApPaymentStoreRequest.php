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
            'company_id' => ['nullable', 'integer', 'exists:accounting_companies,id'],
            'bank_account_id' => ['nullable', 'integer', 'exists:bank_accounts,id', 'required_if:payment_method,bank_transfer'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'job_id' => ['nullable', 'integer', 'exists:accounting_jobs,id'],
            'payment_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['nullable', 'in:cash,bank_transfer,card,cheque,other,petty_cash'],
            'currency_code' => ['nullable', 'string', 'max:10'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
            'allocations' => ['sometimes', 'array'],
            'allocations.*.invoice_id' => ['required_with:allocations', 'integer', 'exists:ap_invoices,id'],
            'allocations.*.allocated_amount' => ['required_with:allocations', 'numeric', 'min:0.01'],
        ];
    }
}
