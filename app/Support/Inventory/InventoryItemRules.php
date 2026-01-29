<?php

namespace App\Support\Inventory;

use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class InventoryItemRules
{
    public function createRules(): array
    {
        return $this->baseRules(ignoreId: null);
    }

    public function updateRules(int $itemId): array
    {
        return $this->baseRules(ignoreId: $itemId);
    }

    private function baseRules(?int $ignoreId): array
    {
        $itemCodeUnique = $ignoreId
            ? Rule::unique('inventory_items', 'item_code')->ignore($ignoreId)
            : Rule::unique('inventory_items', 'item_code');

        $branchRule = ['nullable', 'integer', 'min:1'];
        if (Schema::hasTable('branches')) {
            $exists = Rule::exists('branches', 'id');
            if (Schema::hasColumn('branches', 'is_active')) {
                $exists = $exists->where('is_active', 1);
            }
            $branchRule[] = $exists;
        }

        return [
            'item_code' => ['required', 'string', 'max:50', $itemCodeUnique],
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'branch_id' => $branchRule, // used only for initial stock
            'units_per_package' => ['required', 'numeric', 'min:0.001'],
            'package_label' => ['nullable', 'string', 'max:50'],
            'unit_of_measure' => ['nullable', 'string', 'max:50'],
            'minimum_stock' => ['nullable', 'numeric', 'min:0'],
            'current_stock' => ['nullable', 'numeric', 'min:0'], // create-only field (initial stock)
            'cost_per_unit' => ['nullable', 'numeric', 'min:0'],
            'location' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,discontinued'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('inventory.max_image_kb', 2048)],
        ];
    }
}

