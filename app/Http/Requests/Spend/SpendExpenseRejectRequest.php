<?php

namespace App\Http\Requests\Spend;

use Illuminate\Foundation\Http\FormRequest;

class SpendExpenseRejectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
