<?php

namespace App\Http\Requests\AP;

use Illuminate\Foundation\Http\FormRequest;

class ApInvoicePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'matching_override' => ['nullable', 'boolean'],
            'matching_override_reason' => ['nullable', 'string', 'max:255', 'required_with:matching_override'],
        ];
    }
}
