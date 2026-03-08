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
            'claim_token' => ['required', 'string', 'max:120'],
            'status' => ['required', 'string', 'in:printed,failed'],
            'error_code' => ['sometimes', 'nullable', 'string', 'max:80', 'required_if:status,failed'],
            'error_message' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'processing_ms' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:2147483647'],
        ];
    }
}
