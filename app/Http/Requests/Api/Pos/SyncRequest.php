<?php

namespace App\Http\Requests\Api\Pos;

use Illuminate\Foundation\Http\FormRequest;

class SyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'device_id' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9._-]+$/'],
            'terminal_code' => ['required', 'string', 'regex:/^T\\d{2}$/'],
            'branch_id' => ['required', 'integer', 'min:1'],
            'last_pulled_at' => ['sometimes', 'nullable', 'date'],
            'events' => ['required', 'array'],
            'events.*.event_id' => ['required', 'string', 'max:100'],
            'events.*.type' => ['required', 'string', 'max:60'],
            'events.*.client_uuid' => ['required', 'uuid'],
            'events.*.payload' => ['required', 'array'],
        ];
    }
}

