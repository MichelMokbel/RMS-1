<?php

namespace App\Http\Requests\Accounting;

use Illuminate\Foundation\Http\FormRequest;

class JobTransactionStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'job_phase_id' => ['nullable', 'integer', 'exists:accounting_job_phases,id'],
            'job_cost_code_id' => ['nullable', 'integer', 'exists:accounting_job_cost_codes,id'],
            'transaction_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0'],
            'transaction_type' => ['required', 'in:cost,revenue,adjustment'],
            'memo' => ['nullable', 'string', 'max:255'],
        ];
    }
}
