<?php

namespace App\Http\Requests\Spend;

use Illuminate\Foundation\Http\FormRequest;

class SpendExpenseApproveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'stage' => ['required', 'in:manager,finance'],
        ];
    }
}
