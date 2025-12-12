<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'parent_id' => $this->input('parent_id') ?: null,
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('categories', 'name')
                    ->where(fn ($query) => $query->where('parent_id', $this->input('parent_id'))->whereNull('deleted_at')),
            ],
            'description' => ['nullable', 'string'],
            'parent_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
        ];
    }
}
