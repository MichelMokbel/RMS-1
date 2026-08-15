<?php

namespace App\Services\HR;

use App\Models\HrLeavePolicy;
use App\Models\HrLeaveType;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HrSettingsService
{
    public function __construct(protected HrAccessService $access, protected HrAuditLogService $audit) {}

    /** @param array<string, mixed> $attributes */
    public function createLeavePolicy(HrLeaveType $type, array $attributes, User $actor): HrLeavePolicy
    {
        $this->access->assertCompany($actor, (int) $type->company_id, 'hr.settings.manage');
        foreach (['entitlement_days', 'accrual_rate_days', 'carryover_limit_days', 'max_balance_days', 'waiting_period_days'] as $field) {
            if (isset($attributes[$field]) && (float) $attributes[$field] < 0) {
                throw ValidationException::withMessages([$field => __('Policy values cannot be negative.')]);
            }
        }
        $from = Carbon::parse($attributes['effective_from'] ?? null)->toDateString();
        $to = filled($attributes['effective_to'] ?? null) ? Carbon::parse($attributes['effective_to'])->toDateString() : null;
        if ($to && $to < $from) {
            throw ValidationException::withMessages(['effective_to' => __('The policy end date must be after its start date.')]);
        }

        $policy = HrLeavePolicy::query()->create([...$attributes, 'company_id' => $type->company_id,
            'leave_type_id' => $type->id, 'effective_from' => $from, 'effective_to' => $to,
            'counts_calendar_days' => true, 'is_active' => false,
            'rules' => [...($attributes['rules'] ?? []), 'requires_legal_confirmation' => true]]);
        $this->audit->log('hr.leave_policy.created', (int) $actor->id, $policy, ['leave_type_id' => (int) $type->id], (int) $type->company_id);

        return $policy;
    }

    public function activateLeavePolicy(HrLeavePolicy $policy, User $actor, bool $legalConfirmed = false): HrLeavePolicy
    {
        $this->access->assertCompany($actor, (int) $policy->company_id, 'hr.settings.manage');
        if (! $legalConfirmed) {
            throw ValidationException::withMessages(['legal_confirmation' => __('Confirm legal/payroll review before activating this policy.')]);
        }

        return DB::transaction(function () use ($policy, $actor): HrLeavePolicy {
            $policy = HrLeavePolicy::query()->lockForUpdate()->findOrFail($policy->id);
            $overlap = HrLeavePolicy::query()->where('company_id', $policy->company_id)->where('leave_type_id', $policy->leave_type_id)
                ->where('is_active', true)->whereKeyNot($policy->id)
                ->whereDate('effective_from', '<=', $policy->effective_to ?: '9999-12-31')
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $policy->effective_from))
                ->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['policy' => __('An active policy already covers part of this effective period.')]);
            }
            $rules = $policy->rules ?: [];
            $rules['requires_legal_confirmation'] = false;
            $rules['legally_confirmed_at'] = now()->toIso8601String();
            $rules['legally_confirmed_by'] = (int) $actor->id;
            $policy->forceFill(['is_active' => true, 'rules' => $rules])->save();
            $this->audit->log('hr.leave_policy.activated', (int) $actor->id, $policy, [], (int) $policy->company_id);

            return $policy->fresh();
        });
    }
}
