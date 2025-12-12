<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MenuItemUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $menuItem = $this->route('menuItem');
        $id = is_object($menuItem) ? $menuItem->id : $menuItem;

        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('menu_items', 'code')->ignore($id)],
            'name' => ['required', 'string', 'max:255'],
            'arabic_name' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'recipe_id' => ['nullable', 'integer', Rule::exists('recipes', 'id')],
            'selling_price_per_unit' => ['required', 'numeric', 'min:0'],
            'tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['required', 'boolean'],
            'display_order' => ['required', 'integer', 'min:0'],
        ];
    }
}
