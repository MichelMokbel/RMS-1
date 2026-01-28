<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InventoryItemUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $item = $this->route('item');
        $itemId = is_object($item) ? $item->id : $item;

        return [
            'item_code' => ['required', 'string', 'max:50', Rule::unique('inventory_items', 'item_code')->ignore($itemId)],
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')],
            'units_per_package' => ['required', 'numeric', 'min:0.001'],
            'package_label' => ['nullable', 'string', 'max:50'],
            'unit_of_measure' => ['nullable', 'string', 'max:50'],
            'minimum_stock' => ['nullable', 'numeric', 'min:0'],
            'cost_per_unit' => ['nullable', 'numeric', 'min:0'],
            'location' => ['nullable', 'string', 'max:100'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('inventory.max_image_kb', 2048)],
            'status' => ['nullable', Rule::in(['active', 'discontinued'])],
        ];
    }
}
