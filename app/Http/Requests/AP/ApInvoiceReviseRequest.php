<?php

namespace App\Http\Requests\AP;

use Illuminate\Foundation\Http\FormRequest;

class ApInvoiceReviseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAnyRole(['admin', 'manager']) ?? false;
    }

    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
