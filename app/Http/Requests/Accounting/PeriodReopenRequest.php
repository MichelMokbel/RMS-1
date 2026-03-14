<?php

namespace App\Http\Requests\Accounting;

use Illuminate\Foundation\Http\FormRequest;

class PeriodReopenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reopen_reason' => ['required', 'string'],
            'move_lock_date_back' => ['nullable', 'boolean'],
        ];
    }
}
