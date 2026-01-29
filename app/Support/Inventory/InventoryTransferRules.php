<?php

namespace App\Support\Inventory;

use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class InventoryTransferRules
{
    public function rules(): array
    {
        $branchRule = ['required', 'integer', 'min:1'];
        if (Schema::hasTable('branches')) {
            $exists = Rule::exists('branches', 'id');
            if (Schema::hasColumn('branches', 'is_active')) {
                $exists = $exists->where('is_active', 1);
            }
            $branchRule[] = $exists;
        }

        return [
            'from_branch_id' => $branchRule,
            'to_branch_id' => $branchRule,
            'transfer_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer', Rule::exists('inventory_items', 'id'), 'distinct'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.001'],
        ];
    }
}

