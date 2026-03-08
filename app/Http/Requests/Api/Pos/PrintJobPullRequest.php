<?php

namespace App\Http\Requests\Api\Pos;

use Illuminate\Foundation\Http\FormRequest;

class PrintJobPullRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'wait_seconds' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:60'],
            'limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
