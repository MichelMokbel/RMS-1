<?php

namespace App\Support\Inventory;

use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class InventoryItemShowRules
{
    public function adjustRules(): array
    {
        return [
            'branch_id' => $this->requiredBranchRule(),
            'direction' => ['required', 'in:increase,decrease'],
            'quantity' => ['required', 'numeric', 'min:0.001'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function transferRules(): array
    {
        return [
            'transfer_from_branch_id' => $this->requiredBranchRule(),
            'transfer_to_branch_id' => $this->requiredBranchRule(),
            'transfer_quantity' => ['required', 'numeric', 'min:0.001'],
            'transfer_notes' => ['nullable', 'string'],
        ];
    }

    private function requiredBranchRule(): array
    {
        $branchRule = ['required', 'integer', 'min:1'];
        if (Schema::hasTable('branches')) {
            $exists = Rule::exists('branches', 'id');
            if (Schema::hasColumn('branches', 'is_active')) {
                $exists = $exists->where('is_active', 1);
            }
            $branchRule[] = $exists;
        }

        return $branchRule;
    }
}

