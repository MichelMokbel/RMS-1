<?php

namespace App\Services\HR;

use App\Models\HrCompensationComponent;
use App\Models\HrCompensationPackage;
use App\Models\HrEmployee;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompensationService
{
    public function __construct(protected HrAccessService $access, protected HrAuditLogService $audit) {}

    /**
     * @param  array<string, mixed>  $packageAttributes
     * @param  array<int, array<string, mixed>>  $components
     */
    public function createPackage(HrEmployee $employee, array $packageAttributes, array $components, User $actor): HrCompensationPackage
    {
        $this->access->assertEmployee($actor, $employee, 'hr.payroll.prepare');
        $from = Carbon::parse($packageAttributes['effective_from'] ?? null)->toDateString();
        $to = filled($packageAttributes['effective_to'] ?? null) ? Carbon::parse($packageAttributes['effective_to'])->toDateString() : null;
        if ($to && $to < $from) {
            throw ValidationException::withMessages(['effective_to' => __('The package end date must be after its start date.')]);
        }
        if (! Carbon::parse($from)->isStartOfMonth()) {
            throw ValidationException::withMessages(['effective_from' => __('Monthly compensation packages must start on the first day of a month.')]);
        }
        if ($to && ! Carbon::parse($to)->isEndOfMonth()) {
            throw ValidationException::withMessages(['effective_to' => __('Monthly compensation packages must end on the last day of a month.')]);
        }
        $currency = strtoupper((string) ($packageAttributes['currency'] ?? config('hr.currency', 'QAR')));
        if ($currency !== strtoupper((string) config('hr.currency', 'QAR'))) {
            throw ValidationException::withMessages(['currency' => __('Phase 1 compensation must use the configured HR currency.')]);
        }
        if (($packageAttributes['pay_frequency'] ?? 'monthly') !== 'monthly') {
            throw ValidationException::withMessages(['pay_frequency' => __('Phase 1 supports monthly compensation only.')]);
        }
        $divisor = (float) ($packageAttributes['proration_divisor'] ?? config('hr.proration_divisor', 30));
        if ($divisor <= 0) {
            throw ValidationException::withMessages(['proration_divisor' => __('The payroll divisor must be greater than zero.')]);
        }
        $this->validateComponents($components);

        return DB::transaction(function () use ($employee, $packageAttributes, $components, $actor, $from, $to, $currency, $divisor): HrCompensationPackage {
            $overlaps = HrCompensationPackage::query()->where('employee_id', $employee->id)
                ->whereDate('effective_from', '<=', $to ?: '9999-12-31')
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from))
                ->lockForUpdate()->get();
            foreach ($overlaps as $overlap) {
                if ($to || Carbon::parse($overlap->effective_from)->gte($from)) {
                    throw ValidationException::withMessages(['effective_from' => __('The compensation package overlaps an existing package.')]);
                }
                $overlap->forceFill(['effective_to' => Carbon::parse($from)->subDay()->toDateString()])->save();
            }

            $package = HrCompensationPackage::query()->create([
                ...$packageAttributes, 'company_id' => $employee->company_id, 'employee_id' => $employee->id,
                'effective_from' => $from, 'effective_to' => $to, 'currency' => $currency,
                'pay_frequency' => 'monthly', 'proration_divisor' => $divisor,
                'beneficiary_name' => ($packageAttributes['beneficiary_name'] ?? $employee->display_name)
                    ?: trim($employee->legal_first_name.' '.$employee->legal_last_name),
                'is_active' => true, 'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);
            foreach ($components as $component) {
                HrCompensationComponent::query()->create([
                    ...$component, 'compensation_package_id' => $package->id,
                    'code' => strtoupper(trim((string) $component['code'])),
                    'amount_minor' => (int) $component['amount_minor'], 'is_active' => true,
                ]);
            }
            $this->audit->log('hr.compensation.created', (int) $actor->id, $employee, [
                'package_id' => (int) $package->id, 'component_count' => count($components), 'effective_from' => $from,
            ], (int) $employee->company_id);

            return $package->fresh('components');
        });
    }

    /** @param array<int, array<string, mixed>> $components */
    private function validateComponents(array $components): void
    {
        if ($components === []) {
            throw ValidationException::withMessages(['components' => __('At least one compensation component is required.')]);
        }
        $codes = [];
        $basic = 0;
        foreach ($components as $index => $component) {
            $code = strtoupper(trim((string) ($component['code'] ?? '')));
            $category = (string) ($component['category'] ?? '');
            if ($code === '' || in_array($code, $codes, true)) {
                throw ValidationException::withMessages(["components.{$index}.code" => __('Component codes are required and must be unique.')]);
            }
            if (! in_array($category, ['basic', 'allowance', 'earning', 'deduction'], true)) {
                throw ValidationException::withMessages(["components.{$index}.category" => __('Invalid compensation component category.')]);
            }
            if (! is_numeric($component['amount_minor'] ?? null) || (int) $component['amount_minor'] < 0) {
                throw ValidationException::withMessages(["components.{$index}.amount_minor" => __('Component amount must be a non-negative integer in minor units.')]);
            }
            if ($category === 'basic') {
                $basic++;
            }
            $codes[] = $code;
        }
        if ($basic !== 1) {
            throw ValidationException::withMessages(['components' => __('Exactly one basic salary component is required.')]);
        }
    }
}
