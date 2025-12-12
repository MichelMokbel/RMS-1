<?php

namespace App\Http\Requests\AP;

use Illuminate\Foundation\Http\FormRequest;

class ApInvoicePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
