<?php

namespace App\Http\Requests\Api\Pos;

use Illuminate\Foundation\Http\FormRequest;

class SequenceReserveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'business_date' => ['required', 'date_format:Y-m-d'],
            'count' => ['required', 'integer', 'min:1', 'max:5000'],
        ];
    }
}

