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
            'target_terminal_code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9._-]+$/'],
            'target' => ['required', 'string', 'max:100'],
            'doc_type' => ['required', 'string', 'max:60'],
            'payload_base64' => ['required', 'string'],
            'created_at' => ['required', 'date'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
