<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class InventoryTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

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
            'item_id' => ['required_without:lines', 'integer', Rule::exists('inventory_items', 'id')],
            'quantity' => ['required_without:lines', 'numeric', 'min:0.001'],
            'lines' => ['nullable', 'array'],
            'lines.*.item_id' => ['required_with:lines', 'integer', Rule::exists('inventory_items', 'id'), 'distinct'],
            'lines.*.quantity' => ['required_with:lines', 'numeric', 'min:0.001'],
            'notes' => ['nullable', 'string'],
            'transfer_date' => ['nullable', 'date'],
        ];
    }
}
