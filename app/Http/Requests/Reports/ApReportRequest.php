<?php

namespace App\Http\Requests\Reports;

use Illuminate\Foundation\Http\FormRequest;

class ApReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAnyRole(['admin', 'manager', 'staff']) || $this->user()?->can('reports.access');
    }

    public function rules(): array
    {
        return [
            'company_id' => ['nullable', 'integer', 'exists:accounting_companies,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ];
    }
}
