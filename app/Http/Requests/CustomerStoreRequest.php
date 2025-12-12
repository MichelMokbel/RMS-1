<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->baseRules();
    }

    protected function baseRules(?int $ignoreId = null): array
    {
        return [
            'customer_code' => array_filter([
                'nullable',
                'string',
                'max:50',
                config('customers.enforce_unique_customer_code') ? Rule::unique('customers', 'customer_code')->ignore($ignoreId) : null,
            ]),
            'name' => ['required', 'string', 'max:255'],
            'customer_type' => ['required', Rule::in(['retail', 'corporate', 'subscription'])],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => array_filter([
                'nullable',
                'string',
                'max:50',
                config('customers.enforce_unique_phone') ? Rule::unique('customers', 'phone')->ignore($ignoreId) : null,
            ]),
            'email' => array_filter([
                'nullable',
                'email',
                'max:255',
                config('customers.enforce_unique_email') ? Rule::unique('customers', 'email')->ignore($ignoreId) : null,
            ]),
            'billing_address' => ['nullable', 'string'],
            'delivery_address' => ['nullable', 'string'],
            'country' => ['nullable', 'string', 'max:100'],
            'default_payment_method_id' => ['nullable', 'integer', 'min:1'],
            'credit_limit' => ['required', 'numeric', 'min:0'],
            'credit_terms_days' => ['required', 'integer', 'min:0'],
            'credit_status' => ['nullable', 'string', 'max:100'],
            'is_active' => ['required', 'boolean'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
