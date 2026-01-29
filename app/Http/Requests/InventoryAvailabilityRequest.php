<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class InventoryAvailabilityRequest extends FormRequest
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
            'branch_id' => $branchRule,
        ];
    }
}
