<?php

namespace App\Http\Requests\Api\Pos;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'device_id' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9._-]+$/'],
        ];
    }
}

