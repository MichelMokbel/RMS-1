<?php

namespace App\Http\Requests\Api\Pos;

use Illuminate\Foundation\Http\FormRequest;

class PrintJobAckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ok' => ['required', 'boolean'],
            'error_code' => ['sometimes', 'nullable', 'string', 'max:80'],
            'error_message' => ['sometimes', 'nullable', 'string', 'max:4000'],
        ];
    }
}
