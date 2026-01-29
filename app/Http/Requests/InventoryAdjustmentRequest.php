<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class InventoryAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $branchRule = Schema::hasTable('branches')
            ? ['required', 'integer', 'min:1']
            : ['nullable', 'integer', 'min:1'];

        if (Schema::hasTable('branches')) {
            $exists = Rule::exists('branches', 'id');
            if (Schema::hasColumn('branches', 'is_active')) {
                $exists = $exists->where('is_active', 1);
            }
            $branchRule[] = $exists;
        }

        return [
            'direction' => ['required', 'in:increase,decrease'],
            'quantity' => ['required', 'numeric', 'min:0.001'],
            'notes' => ['nullable', 'string'],
            'branch_id' => $branchRule,
        ];
    }
}
