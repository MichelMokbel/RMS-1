<?php

namespace App\Http\Requests\Api\Pos;

use Illuminate\Foundation\Http\FormRequest;

class BootstrapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'since' => ['sometimes', 'nullable', 'date'],
        ];
    }
}

