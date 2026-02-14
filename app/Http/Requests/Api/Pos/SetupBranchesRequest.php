<?php

namespace App\Http\Requests\Api\Pos;

use Illuminate\Foundation\Http\FormRequest;

class SetupBranchesRequest extends FormRequest
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
        ];
    }
}
