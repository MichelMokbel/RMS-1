<?php

namespace App\Http\Requests\Api\Pos;

use Illuminate\Foundation\Http\FormRequest;

class PrintJobEnqueueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_job_id' => ['required', 'string', 'max:100'],
            'branch_id' => ['required', 'integer', 'min:1'],
            'target_terminal_code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9._-]+$/'],
            'job_type' => ['sometimes', 'nullable', 'string', 'max:60'],
            'payload' => ['required', 'array'],
            'metadata' => ['sometimes', 'nullable', 'array'],
            'max_attempts' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:20'],
        ];
    }
}
