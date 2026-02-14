<?php

namespace App\Http\Requests\Api\Pos;

use Illuminate\Foundation\Http\FormRequest;

class RegisterTerminalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required_without:username', 'nullable', 'string', 'max:255'],
            'username' => ['required_without:email', 'nullable', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'branch_id' => ['required', 'integer', 'min:1', 'exists:branches,id'],
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9._-]+$/'],
            'name' => ['required', 'string', 'max:80'],
            'device_id' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9._-]+$/'],
        ];
    }
}
