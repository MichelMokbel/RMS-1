<?php

namespace App\Services\HR;

use App\Models\HrCompensationComponent;
use App\Models\HrCompensationPackage;
use App\Models\HrEmployee;
use App\Models\HrEmployeeAssignment;
use App\Models\HrLeaveRequest;
use App\Models\HrLeaveType;
use App\Models\HrPayrollAdjustment;
use App\Models\HrPayrollResult;
use App\Models\HrPayrollResultComponent;
use App\Models\HrPayrollRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayrollCalculationService
{
    /** @return array{employees:int,gross_minor:int,deductions_minor:int,net_minor:int} */
    public function calculate(HrPayrollRun $run): array
    {
        $start = Carbon::parse($run->pay_period_start)->startOfDay();
        $end = Carbon::parse($run->pay_period_end)->startOfDay();
        $status = is_object($run->status) ? $run->status->value : $run->status;
        if ($status !== 'draft') {
            throw ValidationException::withMessages(['status' => __('Only a draft payroll run can be calculated.')]);
        }
        $divisor = (float) ($run->proration_divisor ?: config('hr.proration_divisor', 30));
        if ($divisor <= 0) {
            throw ValidationException::withMessages(['proration_divisor' => __('The payroll divisor must be greater than zero.')]);
        }

        return DB::transaction(function () use ($run, $start, $end, $divisor): array {
            HrPayrollResult::query()->where('payroll_run_id', $run->id)->delete();
            $employees = HrEmployee::query()
                ->where('company_id', $run->company_id)
                ->whereIn('employment_status', ['active', 'notice', 'exited'])
                ->whereDate('hire_date', '<=', $end->toDateString())
                ->where(fn ($query) => $query->whereNull('exit_date')->orWhereDate('exit_date', '>=', $start->toDateString()))
                ->lockForUpdate()->get();

            $totals = ['employees' => 0, 'gross_minor' => 0, 'deductions_minor' => 0, 'net_minor' => 0];
            foreach ($employees as $employee) {
                $result = $this->calculateEmployee($run, $employee, $start, $end, $divisor);
                $totals['employees']++;
                $totals['gross_minor'] += (int) $result->gross_minor;
                $totals['deductions_minor'] += (int) $result->deductions_minor;
                $totals['net_minor'] += (int) $result->net_minor;
            }

            return $totals;
        });
    }

    private function calculateEmployee(HrPayrollRun $run, HrEmployee $employee, Carbon $periodStart, Carbon $periodEnd, float $divisor): HrPayrollResult
    {
        $package = HrCompensationPackage::query()
            ->where('employee_id', $employee->id)->where('company_id', $run->company_id)
            ->where('pay_frequency', 'monthly')->where('is_active', true)
            ->whereDate('effective_from', '<=', $periodEnd->toDateString())
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $periodStart->toDateString()))
            ->orderByDesc('effective_from')->first();
        if (! $package) {
            throw ValidationException::withMessages(["employees.{$employee->id}" => __('No active monthly compensation package exists for :employee.', ['employee' => $employee->display_name])]);
        }
        if ((string) $package->currency !== (string) $run->currency) {
            throw ValidationException::withMessages(["employees.{$employee->id}" => __('Employee compensation currency does not match the payroll run.')]);
        }

        $eligibleStart = collect([$periodStart, Carbon::parse($employee->hire_date), Carbon::parse($package->effective_from)])->sort()->last();
        $eligibleEndCandidates = [$periodEnd];
        if ($employee->exit_date) {
            $eligibleEndCandidates[] = Carbon::parse($employee->exit_date);
        }
        if ($package->effective_to) {
            $eligibleEndCandidates[] = Carbon::parse($package->effective_to);
        }
        /** @var Carbon $eligibleEnd */
        $eligibleEnd = collect($eligibleEndCandidates)->sort()->first();
        $calendarDays = max($eligibleStart->diffInDays($eligibleEnd) + 1, 0);
        $fullPeriod = $eligibleStart->lte($periodStart) && $eligibleEnd->gte($periodEnd);
        $payFactor = $fullPeriod ? 1.0 : min($calendarDays / $divisor, 1.0);
        $unpaidDays = $this->unpaidLeaveDays($employee, $eligibleStart, $eligibleEnd);
        $workedDays = max($calendarDays - $unpaidDays, 0);
        $assignment = $this->assignmentAt((int) $employee->id, $periodEnd);
        $components = HrCompensationComponent::query()->where('compensation_package_id', $package->id)->where('is_active', true)->get();

        $basic = 0;
        $earnings = 0;
        $deductions = 0;
        $monthlyEligibleEarnings = 0;
        $componentRows = [];
        foreach ($components as $component) {
            $amount = $this->roundMinor((int) $component->amount_minor * $payFactor);
            $category = (string) $component->category;
            if ($category === 'basic') {
                $basic += $amount;
                $monthlyEligibleEarnings += (int) $component->amount_minor;
            } elseif ($category === 'deduction') {
                $deductions += $amount;
            } else {
                $earnings += $amount;
                $monthlyEligibleEarnings += (int) $component->amount_minor;
            }
            $componentRows[] = $this->component($component->code, $component->name, $category, $amount, $component, (bool) $component->is_taxable, [
                'monthly_amount_minor' => (int) $component->amount_minor, 'pay_factor' => $payFactor,
            ]);
        }

        $unpaidDeduction = $this->roundMinor($monthlyEligibleEarnings * min($unpaidDays / $divisor, 1.0));
        $deductions += $unpaidDeduction;
        if ($unpaidDeduction > 0) {
            $componentRows[] = $this->component('UNPAID_LEAVE', 'Unpaid leave', 'deduction', $unpaidDeduction, null, false, ['days' => $unpaidDays, 'divisor' => $divisor]);
        }

        $adjustments = HrPayrollAdjustment::query()->where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->get();
        foreach ($adjustments as $adjustment) {
            $amount = abs((int) $adjustment->amount_minor);
            $adjustmentType = is_object($adjustment->type) ? $adjustment->type->value : (string) $adjustment->type;
            if ($adjustmentType === 'earning') {
                $earnings += $amount;
            } else {
                $deductions += $amount;
            }
            $componentRows[] = $this->component($adjustment->code, $adjustment->description, $adjustmentType, $amount, $adjustment, false, ['adjustment_id' => (int) $adjustment->id]);
        }

        $gross = $basic + $earnings;
        $net = $gross - $deductions;
        if ($net < 0) {
            throw ValidationException::withMessages(["employees.{$employee->id}" => __('Payroll deductions cannot exceed gross earnings.')]);
        }

        $result = HrPayrollResult::query()->create([
            'company_id' => $run->company_id, 'payroll_run_id' => $run->id, 'employee_id' => $employee->id,
            'assignment_id' => $assignment?->id, 'compensation_package_id' => $package->id,
            'branch_id' => $assignment?->branch_id ?? $employee->current_branch_id,
            'department_id' => $assignment?->department_id ?? $employee->current_department_id,
            'currency' => $run->currency, 'basic_minor' => $basic, 'gross_minor' => $gross,
            'earnings_minor' => $earnings, 'deductions_minor' => $deductions, 'net_minor' => $net,
            'calendar_days' => $calendarDays, 'worked_days' => $workedDays, 'unpaid_leave_days' => $unpaidDays,
            'proration_divisor' => $divisor, 'is_postable' => true,
            'snapshot' => [
                'calculator_version' => 1, 'package_effective_from' => optional($package->effective_from)->toDateString(),
                'eligible_from' => $eligibleStart->toDateString(), 'eligible_to' => $eligibleEnd->toDateString(),
                'full_period' => $fullPeriod, 'pay_factor' => $payFactor,
            ],
        ]);

        foreach ($componentRows as $row) {
            HrPayrollResultComponent::query()->create([...$row, 'payroll_result_id' => $result->id]);
        }

        return $result;
    }

    private function unpaidLeaveDays(HrEmployee $employee, Carbon $start, Carbon $end): float
    {
        $unpaidTypeIds = HrLeaveType::query()->where('company_id', $employee->company_id)->where('is_paid', false)->pluck('id');

        return round((float) HrLeaveRequest::query()->where('employee_id', $employee->id)->whereIn('leave_type_id', $unpaidTypeIds)
            ->where('status', 'approved')->whereDate('start_date', '<=', $end)->whereDate('end_date', '>=', $start)
            ->get()->sum(function (HrLeaveRequest $leave) use ($start, $end): float {
                $overlapStart = Carbon::parse($leave->start_date)->max($start);
                $overlapEnd = Carbon::parse($leave->end_date)->min($end);
                $days = $overlapStart->diffInDays($overlapEnd) + 1;
                if ($overlapStart->isSameDay($leave->start_date) && $leave->start_portion === 'half') {
                    $days -= .5;
                }
                if (! $overlapStart->equalTo($overlapEnd) && $overlapEnd->isSameDay($leave->end_date) && $leave->end_portion === 'half') {
                    $days -= .5;
                }

                return max((float) $days, 0);
            }), 2);
    }

    private function assignmentAt(int $employeeId, Carbon $date): ?HrEmployeeAssignment
    {
        return HrEmployeeAssignment::query()->where('employee_id', $employeeId)->whereDate('effective_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->orderByDesc('effective_from')->first();
    }

    /** @return array<string, mixed> */
    private function component(mixed $code, mixed $name, string $category, int $amount, mixed $source, bool $taxable, array $snapshot): array
    {
        return ['code' => $code, 'name' => $name, 'category' => $category, 'amount_minor' => $amount,
            'source_type' => $source ? $source::class : HrLeaveRequest::class, 'source_id' => $source?->id,
            'is_taxable' => $taxable, 'snapshot' => $snapshot];
    }

    private function roundMinor(float|int $amount): int
    {
        return (int) round($amount, 0, PHP_ROUND_HALF_UP);
    }
}
