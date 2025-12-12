<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InventoryItemStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_code' => ['required', 'string', 'max:50', 'unique:inventory_items,item_code'],
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')],
            'units_per_package' => ['required', 'integer', 'min:1'],
            'package_label' => ['nullable', 'string', 'max:50'],
            'unit_of_measure' => ['nullable', 'string', 'max:50'],
            'minimum_stock' => ['nullable', 'integer', 'min:0'],
            'initial_stock' => ['nullable', 'integer', 'min:0'],
            'cost_per_unit' => ['nullable', 'numeric', 'min:0'],
            'location' => ['nullable', 'string', 'max:100'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('inventory.max_image_kb', 2048)],
            'status' => ['nullable', Rule::in(['active', 'discontinued'])],
        ];
    }
}
