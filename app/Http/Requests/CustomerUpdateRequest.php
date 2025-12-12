<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerUpdateRequest extends CustomerStoreRequest
{
    public function rules(): array
    {
        $customerId = $this->route('customer')?->id ?? null;

        return $this->baseRules($customerId);
    }
}
