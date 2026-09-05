<?php

namespace App\Http\Requests\Reports;

use Illuminate\Foundation\Http\FormRequest;

class PrintMealPlanRequestsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor && $actor->isActive()
            && ($actor->hasAnyRole(['admin', 'manager']) || $actor->can('operations.access'));
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'request_ids' => ['required', 'array', 'min:1'],
            'request_ids.*' => ['required', 'integer', 'distinct', 'exists:meal_plan_requests,id'],
        ];
    }
}
