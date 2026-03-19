<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class InventoryManualTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('description') && ! $this->filled('notes')) {
            $this->merge(['notes' => $this->input('description')]);
        }
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
            'item_id' => ['required', 'integer', 'exists:inventory_items,id'],
            'branch_id' => $branchRule,
            'transaction_type' => ['required', Rule::in(['in', 'out', 'adjust', 'adjustment'])],
            'quantity' => ['required', 'numeric'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'transaction_date' => ['required', 'date'],
        ];
    }
}
