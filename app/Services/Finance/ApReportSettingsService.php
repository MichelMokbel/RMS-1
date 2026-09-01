<?php

namespace App\Services\Finance;

use App\Models\FinanceSetting;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ApReportSettingsService
{
    public function save(array $data, User $actor): void
    {
        abort_unless($actor->isAdmin(), 403);
        $data = Validator::make($data, [
            'ap_report_enabled' => ['required', 'boolean'],
            'ap_report_email' => ['nullable', 'required_if:ap_report_enabled,true', 'email:rfc', 'max:255'],
            'ap_report_company_id' => ['nullable', 'required_if:ap_report_enabled,true', 'integer', Rule::exists('accounting_companies', 'id')->where('is_active', true)],
        ])->validate();

        FinanceSetting::query()->firstOrCreate(['id' => 1])->fill($data + ['updated_by' => $actor->id])->save();
    }
}
