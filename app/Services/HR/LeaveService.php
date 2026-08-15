<?php

namespace App\Services\HR;

use App\Models\HrEmployee;
use App\Models\HrLeaveLedgerEntry;
use App\Models\HrLeavePolicy;
use App\Models\HrLeaveRequest;
use App\Models\HrLeaveRequestEvent;
use App\Models\HrLeaveType;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LeaveService
{
    public function __construct(
        protected HrAuditLogService $audit,
        protected HrAccessService $access,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function create(HrEmployee $employee, array $attributes, User $actor): HrLeaveRequest
    {
        $this->access->assertEmployee($actor, $employee, 'hr.leave.manage');
        $policy = $this->resolvePolicy($employee, (int) ($attributes['leave_type_id'] ?? 0), $attributes['policy_id'] ?? null, $attributes['start_date'] ?? null);
        $start = Carbon::parse($attributes['start_date'] ?? null)->startOfDay();
        $end = Carbon::parse($attributes['end_date'] ?? null)->startOfDay();
        if ($end->lt($start)) {
            throw ValidationException::withMessages(['end_date' => __('The leave end date must be on or after the start date.')]);
        }
        if ($start->lt(Carbon::parse($employee->hire_date)->startOfDay()) || ($employee->exit_date && $end->gt(Carbon::parse($employee->exit_date)))) {
            throw ValidationException::withMessages(['start_date' => __('Leave dates must fall within the employee employment dates.')]);
        }
        if ((int) $policy->waiting_period_days > 0 && $start->lt(Carbon::parse($employee->hire_date)->addDays((int) $policy->waiting_period_days))) {
            throw ValidationException::withMessages(['start_date' => __('The employee has not completed the leave policy waiting period.')]);
        }
        if ($policy->requires_attachment && empty($attributes['attachment_document_id'])) {
            throw ValidationException::withMessages(['attachment_document_id' => __('This leave policy requires a supporting document.')]);
        }

        $days = $this->calendarDays($start, $end, $attributes['start_portion'] ?? 'full', $attributes['end_portion'] ?? 'full');

        return DB::transaction(function () use ($employee, $attributes, $actor, $policy, $start, $end, $days): HrLeaveRequest {
            $this->assertNoOverlap((int) $employee->id, $start, $end);
            $balance = $this->balance((int) $employee->id, (int) $policy->leave_type_id, true);
            if (! $policy->allow_negative && $balance < $days) {
                throw ValidationException::withMessages(['leave_type_id' => __('The employee does not have enough leave balance.')]);
            }

            $request = HrLeaveRequest::query()->create([
                ...$attributes,
                'company_id' => $employee->company_id,
                'employee_id' => $employee->id,
                'leave_type_id' => $policy->leave_type_id,
                'policy_id' => $policy->id,
                'manager_id' => $employee->manager_id,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'start_portion' => $attributes['start_portion'] ?? 'full',
                'end_portion' => $attributes['end_portion'] ?? 'full',
                'requested_days' => $days,
                'status' => 'pending_manager',
                'submitted_at' => now(),
                'created_by' => $actor->id,
            ]);

            $this->ledger($request, 'reservation', -$days, 'reserve', (int) $actor->id);
            $this->event($request, null, 'pending_manager', 'submitted', (int) $actor->id, null);
            $this->audit->log('hr.leave.submitted', (int) $actor->id, $request, [
                'employee_id' => (int) $employee->id, 'requested_days' => $days,
            ], (int) $employee->company_id);

            return $request->fresh();
        });
    }

    public function approve(HrLeaveRequest $request, User $actor, ?string $reason = null): HrLeaveRequest
    {
        $this->access->assertLeave($actor, $request, 'hr.leave.approve');

        return DB::transaction(function () use ($request, $actor, $reason): HrLeaveRequest {
            $request = HrLeaveRequest::query()->lockForUpdate()->findOrFail($request->id);
            $this->assertStatus($request, 'pending_manager');
            $managerEmployeeId = HrEmployee::query()->where('user_id', $actor->id)->value('id');
            $isManager = $managerEmployeeId && (int) $request->manager_id === (int) $managerEmployeeId;
            $isOverride = ! $isManager;
            if ($isOverride && ! $actor->isAdmin()) {
                throw ValidationException::withMessages(['approval' => __('Only the assigned manager may approve this request.')]);
            }
            if ($isOverride && blank($reason)) {
                throw ValidationException::withMessages(['reason' => __('An admin override reason is required.')]);
            }
            if ((int) $request->created_by === (int) $actor->id && (! $actor->isAdmin() || blank($reason))) {
                throw ValidationException::withMessages(['reason' => __('An admin override reason is required for same-user approval.')]);
            }

            $request->forceFill([
                'status' => 'approved', 'decided_at' => now(), 'decided_by' => $actor->id, 'decision_reason' => $reason,
            ])->save();
            $days = (float) $request->requested_days;
            $this->ledger($request, 'reversal', $days, 'release-reservation', (int) $actor->id);
            $this->ledger($request, 'usage', -$days, 'approved-usage', (int) $actor->id);
            $this->event($request, 'pending_manager', 'approved', $isOverride ? 'admin_override_approved' : 'manager_approved', (int) $actor->id, $reason, [
                'override' => $isOverride,
            ]);
            $this->audit->log('hr.leave.approved', (int) $actor->id, $request, ['admin_override' => $isOverride, 'reason' => $reason], (int) $request->company_id);

            return $request->fresh();
        });
    }

    public function reject(HrLeaveRequest $request, User $actor, string $reason): HrLeaveRequest
    {
        $this->access->assertLeave($actor, $request, 'hr.leave.approve');

        return DB::transaction(function () use ($request, $actor, $reason): HrLeaveRequest {
            $request = HrLeaveRequest::query()->lockForUpdate()->findOrFail($request->id);
            $this->assertStatus($request, 'pending_manager');
            $managerEmployeeId = HrEmployee::query()->where('user_id', $actor->id)->value('id');
            $isManager = $managerEmployeeId && (int) $request->manager_id === (int) $managerEmployeeId;
            if (! $isManager && ! $actor->isAdmin()) {
                throw ValidationException::withMessages(['approval' => __('Only the assigned manager may reject this request.')]);
            }
            $request->forceFill(['status' => 'rejected', 'decided_at' => now(), 'decided_by' => $actor->id, 'decision_reason' => $reason])->save();
            $this->ledger($request, 'reversal', (float) $request->requested_days, 'rejected-release', (int) $actor->id);
            $this->event($request, 'pending_manager', 'rejected', $isManager ? 'manager_rejected' : 'admin_override_rejected', (int) $actor->id, $reason, ['override' => ! $isManager]);
            $this->audit->log('hr.leave.rejected', (int) $actor->id, $request, ['reason' => $reason, 'admin_override' => ! $isManager], (int) $request->company_id);

            return $request->fresh();
        });
    }

    public function cancel(HrLeaveRequest $request, User $actor, string $reason): HrLeaveRequest
    {
        $this->access->assertLeave($actor, $request, 'hr.leave.manage');

        return DB::transaction(function () use ($request, $actor, $reason): HrLeaveRequest {
            $request = HrLeaveRequest::query()->lockForUpdate()->findOrFail($request->id);
            $from = $this->status($request);
            if (! in_array($from, ['pending_manager', 'approved'], true)) {
                throw ValidationException::withMessages(['status' => __('Only pending or approved leave can be cancelled.')]);
            }
            $request->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $actor->id, 'decision_reason' => $reason])->save();
            $this->ledger($request, 'reversal', (float) $request->requested_days, $from === 'approved' ? 'cancelled-usage' : 'cancelled-reservation', (int) $actor->id);
            $this->event($request, $from, 'cancelled', 'cancelled', (int) $actor->id, $reason);
            $this->audit->log('hr.leave.cancelled', (int) $actor->id, $request, ['reason' => $reason], (int) $request->company_id);

            return $request->fresh();
        });
    }

    public function balance(int $employeeId, int $leaveTypeId, bool $lock = false): float
    {
        $query = HrLeaveLedgerEntry::query()->where('employee_id', $employeeId)->where('leave_type_id', $leaveTypeId);
        if ($lock) {
            return round((float) $query->lockForUpdate()->get(['days'])->sum('days'), 2);
        }

        return round((float) $query->sum('days'), 2);
    }

    public function recordOpeningBalance(HrEmployee $employee, HrLeavePolicy $policy, float $days, string $effectiveDate, User $actor, ?string $notes = null): HrLeaveLedgerEntry
    {
        $this->access->assertEmployee($actor, $employee, 'hr.leave.manage');
        if ((int) $policy->company_id !== (int) $employee->company_id || (float) $days < 0) {
            throw ValidationException::withMessages(['days' => __('Opening balance and policy must be valid for this employee company.')]);
        }

        return DB::transaction(function () use ($employee, $policy, $days, $effectiveDate, $actor, $notes): HrLeaveLedgerEntry {
            $key = "leave:opening:{$employee->id}:{$policy->id}:".Carbon::parse($effectiveDate)->toDateString();
            $existing = HrLeaveLedgerEntry::query()->where('idempotency_key', $key)->first();
            if ($existing) {
                return $existing;
            }
            $balance = $this->balance((int) $employee->id, (int) $policy->leave_type_id, true) + $days;
            $entry = HrLeaveLedgerEntry::query()->create(['company_id' => $employee->company_id, 'employee_id' => $employee->id,
                'leave_type_id' => $policy->leave_type_id, 'policy_id' => $policy->id, 'entry_type' => 'opening',
                'days' => $days, 'effective_date' => Carbon::parse($effectiveDate)->toDateString(), 'idempotency_key' => $key,
                'balance_after_days' => $balance, 'notes' => $notes, 'created_by' => $actor->id]);
            $this->audit->log('hr.leave.opening_balance_recorded', (int) $actor->id, $employee, ['leave_type_id' => (int) $policy->leave_type_id], (int) $employee->company_id);

            return $entry;
        });
    }

    public function adjustBalance(HrEmployee $employee, HrLeaveType $leaveType, float $days, string $effectiveDate, string $note, User $actor): HrLeaveLedgerEntry
    {
        $this->access->assertEmployee($actor, $employee, 'hr.leave.manage');
        if ((int) $leaveType->company_id !== (int) $employee->company_id || abs($days) < 0.001) {
            throw ValidationException::withMessages(['days' => __('A non-zero adjustment and leave type from the employee company are required.')]);
        }
        if (blank($note)) {
            throw ValidationException::withMessages(['note' => __('An audit note is required for leave balance adjustments.')]);
        }

        return DB::transaction(function () use ($employee, $leaveType, $days, $effectiveDate, $note, $actor): HrLeaveLedgerEntry {
            $balance = $this->balance((int) $employee->id, (int) $leaveType->id, true) + $days;
            $entry = HrLeaveLedgerEntry::query()->create(['company_id' => $employee->company_id, 'employee_id' => $employee->id,
                'leave_type_id' => $leaveType->id, 'entry_type' => 'adjustment', 'days' => $days,
                'effective_date' => Carbon::parse($effectiveDate)->toDateString(), 'idempotency_key' => (string) Str::uuid(),
                'balance_after_days' => $balance, 'notes' => $note, 'created_by' => $actor->id]);
            $this->audit->log('hr.leave.balance_adjusted', (int) $actor->id, $employee, [
                'leave_type_id' => (int) $leaveType->id, 'direction' => $days > 0 ? 'increase' : 'decrease', 'note' => $note,
            ], (int) $employee->company_id);

            return $entry;
        });
    }

    private function resolvePolicy(HrEmployee $employee, int $leaveTypeId, mixed $policyId, mixed $startDate): HrLeavePolicy
    {
        $date = Carbon::parse($startDate)->toDateString();
        $policy = HrLeavePolicy::query()
            ->where('company_id', $employee->company_id)
            ->where('leave_type_id', $leaveTypeId)
            ->where('is_active', true)
            ->when($policyId, fn ($query) => $query->whereKey($policyId))
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->first();
        if (! $policy) {
            throw ValidationException::withMessages(['policy_id' => __('No active leave policy applies to this request.')]);
        }

        return $policy;
    }

    private function assertNoOverlap(int $employeeId, Carbon $start, Carbon $end): void
    {
        $exists = HrLeaveRequest::query()->where('employee_id', $employeeId)
            ->whereIn('status', ['pending_manager', 'approved'])
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->lockForUpdate()->exists();
        if ($exists) {
            throw ValidationException::withMessages(['start_date' => __('This leave overlaps another pending or approved request.')]);
        }
    }

    private function calendarDays(Carbon $start, Carbon $end, string $startPortion, string $endPortion): float
    {
        $days = (float) ($start->diffInDays($end) + 1);
        if ($start->equalTo($end)) {
            return ($startPortion === 'half' || $endPortion === 'half') ? 0.5 : 1.0;
        }
        if ($startPortion === 'half') {
            $days -= 0.5;
        }
        if ($endPortion === 'half') {
            $days -= 0.5;
        }

        return $days;
    }

    private function ledger(HrLeaveRequest $request, string $type, float $days, string $key, int $actorId): void
    {
        $balance = $this->balance((int) $request->employee_id, (int) $request->leave_type_id, true) + $days;
        HrLeaveLedgerEntry::query()->firstOrCreate(['idempotency_key' => "leave:{$request->id}:{$key}"], [
            'company_id' => $request->company_id,
            'employee_id' => $request->employee_id,
            'leave_type_id' => $request->leave_type_id,
            'policy_id' => $request->policy_id,
            'leave_request_id' => $request->id,
            'entry_type' => $type,
            'days' => $days,
            'effective_date' => now()->toDateString(),
            'source_type' => HrLeaveRequest::class,
            'source_id' => $request->id,
            'balance_after_days' => $balance,
            'created_by' => $actorId,
        ]);
    }

    /** @param array<string, mixed> $metadata */
    private function event(HrLeaveRequest $request, ?string $from, string $to, string $action, int $actorId, ?string $reason, array $metadata = []): void
    {
        HrLeaveRequestEvent::query()->create([
            'company_id' => $request->company_id, 'leave_request_id' => $request->id, 'from_status' => $from,
            'to_status' => $to, 'action' => $action, 'actor_id' => $actorId, 'reason' => $reason, 'metadata' => $metadata,
        ]);
    }

    private function assertStatus(HrLeaveRequest $request, string $expected): void
    {
        if ($this->status($request) !== $expected) {
            throw ValidationException::withMessages(['status' => __('The leave request is not in the expected state.')]);
        }
    }

    private function status(HrLeaveRequest $request): string
    {
        return is_object($request->status) ? (string) $request->status->value : (string) $request->status;
    }
}
