<?php

namespace App\Http\Requests\Accounting;

use Illuminate\Foundation\Http\FormRequest;

class BudgetVersionStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id' => ['nullable', 'integer', 'exists:accounting_companies,id'],
            'fiscal_year_id' => ['required', 'integer', 'exists:fiscal_years,id'],
            'name' => ['required', 'string', 'max:100'],
            'status' => ['nullable', 'in:draft,active,archived'],
            'is_active' => ['nullable', 'boolean'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.account_id' => ['required', 'integer', 'exists:ledger_accounts,id'],
            'lines.*.department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'lines.*.job_id' => ['nullable', 'integer', 'exists:accounting_jobs,id'],
            'lines.*.branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'lines.*.annual_amount' => ['nullable', 'numeric'],
            'lines.*.period_amounts' => ['nullable', 'array'],
            'lines.*.period_amounts.*' => ['nullable', 'numeric'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            foreach ((array) $this->input('lines', []) as $index => $line) {
                $hasAnnual = array_key_exists('annual_amount', $line) && $line['annual_amount'] !== null && $line['annual_amount'] !== '';
                $hasPeriods = ! empty(array_filter((array) ($line['period_amounts'] ?? []), static fn ($value) => $value !== null && $value !== ''));

                if (! $hasAnnual && ! $hasPeriods) {
                    $validator->errors()->add("lines.{$index}.annual_amount", __('Provide an annual amount or period amounts.'));
                }
            }
        });
    }
}
