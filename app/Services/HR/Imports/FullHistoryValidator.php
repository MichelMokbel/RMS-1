<?php

namespace App\Services\HR\Imports;

use App\Models\Branch;
use App\Models\Department;
use App\Models\HrEmployee;
use App\Models\HrLeaveType;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class FullHistoryValidator
{
    public function __construct(
        protected FullHistoryValueParser $values,
        protected FullHistoryRelationshipValidator $relationships,
    ) {}

    /** @return array{rows:array<int,array<string,mixed>>,stats:array<string,mixed>} */
    public function validate(array $sheets, int $companyId, array $headers = []): array
    {
        $this->assertWorkbookShape($sheets, $headers);
        $employees = $this->employeeReferences($sheets['employees'], $companyId);
        $branches = $this->organizationMap(Branch::query()->where('company_id', $companyId)->where('is_active', true)->get());
        $departments = $this->organizationMap(Department::query()->where('company_id', $companyId)->where('is_active', true)->get());
        $leaveTypes = HrLeaveType::query()->where('company_id', $companyId)->where('is_active', true)->get()
            ->mapWithKeys(fn ($type) => [mb_strtolower((string) $type->code) => (int) $type->id])->all();

        $rows = [];
        $seen = [];
        foreach (array_keys(FullHistorySchema::HEADERS) as $sheet) {
            foreach ($sheets[$sheet] as $offset => $payload) {
                $payload['_sheet'] = $sheet;
                $payload['_sheet_row'] = $offset + 2;
                $entry = ['sheet' => $sheet, 'payload' => $payload, 'errors' => [], 'identifier' => $this->identifier($sheet, $payload)];
                $this->required($entry, FullHistorySchema::REQUIRED[$sheet]);
                if ($entry['identifier'] !== null && isset($seen[$entry['identifier']])) {
                    $entry['errors']['natural_key'] = 'Duplicate natural key in this workbook.';
                }
                if ($entry['identifier'] !== null) {
                    $seen[$entry['identifier']] = true;
                }
                $this->validateRow($entry, $employees, $branches, $departments, $leaveTypes);
                $rows[] = $entry;
            }
        }

        $this->relationships->validate($rows, $sheets);
        $stats = ['rows' => count($rows), 'valid' => 0, 'invalid' => 0, 'errors' => 0, 'sheets' => []];
        foreach (array_keys(FullHistorySchema::HEADERS) as $sheet) {
            $sheetRows = array_values(array_filter($rows, fn ($row) => $row['sheet'] === $sheet));
            $invalid = count(array_filter($sheetRows, fn ($row) => $row['errors'] !== []));
            $stats['sheets'][$sheet] = ['rows' => count($sheetRows), 'valid' => count($sheetRows) - $invalid, 'errors' => $invalid];
            $stats['invalid'] += $invalid;
        }
        $stats['valid'] = $stats['rows'] - $stats['invalid'];
        $stats['errors'] = $stats['invalid'];

        return ['rows' => $rows, 'stats' => $stats];
    }

    private function assertWorkbookShape(array $sheets, array $workbookHeaders): void
    {
        $missing = array_values(array_diff(array_keys(FullHistorySchema::HEADERS), array_keys($sheets)));
        $unexpected = array_values(array_diff(array_keys($sheets), [...array_keys(FullHistorySchema::HEADERS), ...FullHistorySchema::LOOKUP_SHEETS]));
        $errors = [];
        if ($missing !== []) {
            $errors['sheets'] = 'Missing required sheets: '.implode(', ', $missing).'.';
        }
        if ($unexpected !== []) {
            $errors['unexpected_sheets'] = 'Unexpected sheets: '.implode(', ', $unexpected).'.';
        }
        foreach (FullHistorySchema::HEADERS as $sheet => $headers) {
            if (! array_key_exists($sheet, $sheets)) {
                continue;
            }
            $actual = $workbookHeaders[$sheet] ?? ($sheets[$sheet] === []
                ? []
                : array_values(array_filter(array_keys($sheets[$sheet][0]), fn ($key) => ! str_starts_with((string) $key, '_'))));
            $missingHeaders = array_diff($headers, $actual);
            $extraHeaders = array_diff($actual, $headers);
            if ($actual !== $headers) {
                $details = [];
                if ($missingHeaders !== []) {
                    $details[] = 'missing: '.implode(', ', $missingHeaders);
                }
                if ($extraHeaders !== []) {
                    $details[] = 'unexpected: '.implode(', ', $extraHeaders);
                }
                if ($details === []) {
                    $details[] = 'columns are out of order';
                }
                $errors["headers.{$sheet}"] = 'Header mismatch; '.implode('; ', $details).'.';
            }
        }
        if (($sheets['employees'] ?? []) === []) {
            $errors['employees'] = 'Employees must contain at least one data row.';
        }
        if (($sheets['assignments'] ?? []) === []) {
            $errors['assignments'] = 'Assignments must contain at least one data row.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @return array<string, int|null> */
    private function employeeReferences(array $employeeRows, int $companyId): array
    {
        $map = [];
        foreach (HrEmployee::query()->where('company_id', $companyId)->get() as $employee) {
            foreach ([$employee->employee_number, $employee->metadata['legacy_employee_number'] ?? null, $employee->metadata['full_history_employee_ref'] ?? null] as $reference) {
                if (filled($reference)) {
                    $map[mb_strtolower(trim((string) $reference))] = (int) $employee->id;
                }
            }
        }
        foreach ($employeeRows as $row) {
            if (filled($row['employee_ref'] ?? null)) {
                $map[mb_strtolower(trim((string) $row['employee_ref']))] ??= null;
            }
        }

        return $map;
    }

    private function validateRow(array &$entry, array $employees, array $branches, array $departments, array $leaveTypes): void
    {
        $sheet = $entry['sheet'];
        $row = &$entry['payload'];
        foreach ($this->dateFields($sheet) as $field) {
            if (filled($row[$field] ?? null) && ! $this->values->isDate((string) $row[$field])) {
                $entry['errors'][$field] = "{$field} must use YYYY-MM-DD format.";
            }
        }
        foreach ($this->employeeRefFields($sheet) as $field) {
            $ref = mb_strtolower(trim((string) ($row[$field] ?? '')));
            if ($ref !== '' && ! array_key_exists($ref, $employees)) {
                $entry['errors'][$field] = 'Employee reference does not exist in this workbook or company.';
            }
        }

        match ($sheet) {
            'employees' => $this->employeeRow($entry),
            'assignments' => $this->assignmentRow($entry, $branches, $departments),
            'compensation' => $this->compensationRow($entry),
            'compensation_components' => $this->componentRow($entry, 'amount_qar'),
            'leave_balances' => $this->leaveBalanceRow($entry, $leaveTypes),
            'leave_history' => $this->leaveHistoryRow($entry, $leaveTypes),
            'payroll_history' => $this->payrollRow($entry),
            'payroll_components' => $this->componentRow($entry, 'amount_qar'),
        };
    }

    private function employeeRow(array &$entry): void
    {
        $row = &$entry['payload'];
        if (! in_array((string) ($row['employment_status'] ?? ''), ['onboarding', 'active', 'suspended', 'notice', 'exited', 'archived'], true)) {
            $entry['errors']['employment_status'] = 'Invalid employee status.';
        }
        if (! in_array((string) ($row['employment_type'] ?? ''), ['full_time', 'part_time', 'temporary', 'contractor', 'intern'], true)) {
            $entry['errors']['employment_type'] = 'Invalid employment type.';
        }
        $this->orderedDates($entry, 'hire_date', ['probation_end_date', 'notice_date', 'exit_date']);
        if (($row['employment_status'] ?? null) === 'exited' && ! filled($row['exit_date'] ?? null)) {
            $entry['errors']['exit_date'] = 'Exited employees require an exit date.';
        }
    }

    private function assignmentRow(array &$entry, array $branches, array $departments): void
    {
        $row = &$entry['payload'];
        $row['_branch_id'] = $this->resolveOrganization((string) ($row['branch_code'] ?? ''), $branches);
        if (! $row['_branch_id']) {
            $entry['errors']['branch_code'] = 'Branch code must identify an active branch in this company.';
        }
        if (filled($row['department_code'] ?? null)) {
            $row['_department_id'] = $this->resolveOrganization((string) $row['department_code'], $departments);
            if (! $row['_department_id']) {
                $entry['errors']['department_code'] = 'Department code must identify an active department in this company.';
            }
        }
        if (! in_array((string) ($row['employment_type'] ?? ''), ['full_time', 'part_time', 'temporary', 'contractor', 'intern'], true)) {
            $entry['errors']['employment_type'] = 'Invalid employment type.';
        }
        $this->dateRange($entry, 'effective_from', 'effective_to');
        if (filled($row['manager_ref'] ?? null) && mb_strtolower((string) $row['manager_ref']) === mb_strtolower((string) $row['employee_ref'])) {
            $entry['errors']['manager_ref'] = 'An employee cannot manage themselves.';
        }
    }

    private function compensationRow(array &$entry): void
    {
        $row = &$entry['payload'];
        $this->dateRange($entry, 'effective_from', 'effective_to');
        if ($this->values->isDate((string) ($row['effective_from'] ?? '')) && ! Carbon::parse($row['effective_from'])->isStartOfMonth()) {
            $entry['errors']['effective_from'] = 'Compensation must start on the first day of a month.';
        }
        if ($this->values->isDate((string) ($row['effective_to'] ?? '')) && ! Carbon::parse($row['effective_to'])->isEndOfMonth()) {
            $entry['errors']['effective_to'] = 'Compensation must end on the last day of a month.';
        }
        if (strtoupper((string) ($row['currency'] ?? '')) !== 'QAR') {
            $entry['errors']['currency'] = 'Only QAR compensation is supported.';
        }
        if (($row['pay_frequency'] ?? null) !== 'monthly') {
            $entry['errors']['pay_frequency'] = 'Only monthly compensation is supported.';
        }
        if (! is_numeric($row['proration_divisor'] ?? null) || (float) $row['proration_divisor'] <= 0) {
            $entry['errors']['proration_divisor'] = 'Proration divisor must be positive.';
        }
    }

    private function componentRow(array &$entry, string $amountField): void
    {
        $row = &$entry['payload'];
        if (! in_array((string) ($row['category'] ?? ''), ['basic', 'allowance', 'earning', 'deduction'], true)) {
            $entry['errors']['category'] = 'Invalid component category.';
        }
        $minor = $this->values->minorUnits($row[$amountField] ?? null);
        if ($minor === null || $minor < 0) {
            $entry['errors'][$amountField] = 'QAR amount must be non-negative with at most two decimal places.';
        } else {
            $row['amount_minor'] = $minor;
        }
        $boolean = $this->values->boolean($row['is_taxable'] ?? null);
        if ($boolean === null && filled($row['is_taxable'] ?? null)) {
            $entry['errors']['is_taxable'] = 'is_taxable must be yes/no or true/false.';
        } else {
            $row['is_taxable'] = $boolean ?? false;
        }
    }

    private function leaveBalanceRow(array &$entry, array $leaveTypes): void
    {
        $row = &$entry['payload'];
        $row['_leave_type_id'] = $leaveTypes[mb_strtolower((string) ($row['leave_type_code'] ?? ''))] ?? null;
        if (! $row['_leave_type_id']) {
            $entry['errors']['leave_type_code'] = 'Leave type is not active in this company.';
        }
        if (! is_numeric($row['days'] ?? null) || (float) $row['days'] < 0 || round((float) $row['days'], 2) != (float) $row['days']) {
            $entry['errors']['days'] = 'Leave balance must be a non-negative number with at most two decimals.';
        }
    }

    private function leaveHistoryRow(array &$entry, array $leaveTypes): void
    {
        $row = &$entry['payload'];
        $row['_leave_type_id'] = $leaveTypes[mb_strtolower((string) ($row['leave_type_code'] ?? ''))] ?? null;
        if (! $row['_leave_type_id']) {
            $entry['errors']['leave_type_code'] = 'Leave type is not active in this company.';
        }
        $this->dateRange($entry, 'start_date', 'end_date');
        foreach (['start_portion', 'end_portion'] as $field) {
            $row[$field] = filled($row[$field] ?? null) ? strtolower((string) $row[$field]) : 'full';
            if (! in_array($row[$field], ['full', 'half'], true)) {
                $entry['errors'][$field] = 'Leave portion must be full or half.';
            }
        }
        if (! in_array((string) ($row['status'] ?? ''), ['draft', 'pending_manager', 'approved', 'rejected', 'cancelled'], true)) {
            $entry['errors']['status'] = 'Invalid leave status.';
        }
        if (! is_numeric($row['requested_days'] ?? null) || (float) $row['requested_days'] <= 0) {
            $entry['errors']['requested_days'] = 'Requested days must be positive.';
        }
    }

    private function payrollRow(array &$entry): void
    {
        $row = &$entry['payload'];
        $this->dateRange($entry, 'pay_period_start', 'pay_period_end');
        if ($this->values->isDate((string) ($row['pay_period_start'] ?? '')) && ! Carbon::parse($row['pay_period_start'])->isStartOfMonth()) {
            $entry['errors']['pay_period_start'] = 'Payroll periods must start on the first day of a month.';
        }
        if ($this->values->isDate((string) ($row['pay_period_end'] ?? '')) && ! Carbon::parse($row['pay_period_end'])->isEndOfMonth()) {
            $entry['errors']['pay_period_end'] = 'Payroll periods must end on the last day of a month.';
        }
        if (strtoupper((string) ($row['currency'] ?? '')) !== 'QAR') {
            $entry['errors']['currency'] = 'Only QAR payroll history is supported.';
        }
        foreach (['basic_qar', 'gross_qar', 'earnings_qar', 'deductions_qar', 'net_qar'] as $field) {
            $minor = $this->values->minorUnits($row[$field] ?? null);
            if ($minor === null || $minor < 0) {
                $entry['errors'][$field] = "{$field} must have at most two decimal places.";
            } else {
                $row[str_replace('_qar', '_minor', $field)] = $minor;
            }
        }
        if (! $entry['errors'] && (($row['basic_minor'] + $row['earnings_minor']) !== $row['gross_minor'] || ($row['gross_minor'] - $row['deductions_minor']) !== $row['net_minor'])) {
            $entry['errors']['totals'] = 'Payroll totals must satisfy basic + earnings = gross and gross - deductions = net.';
        }
        foreach (['calendar_days', 'worked_days', 'unpaid_leave_days'] as $field) {
            if (! is_numeric($row[$field] ?? 0) || (float) ($row[$field] ?? 0) < 0) {
                $entry['errors'][$field] = "{$field} must be non-negative.";
            }
        }
        if (filled($row['paid_at'] ?? null) && ! $this->values->isDateTime((string) $row['paid_at'])) {
            $entry['errors']['paid_at'] = 'paid_at must be an ISO date or datetime.';
        }
    }

    private function required(array &$entry, array $fields): void
    {
        foreach ($fields as $field) {
            if (! filled($entry['payload'][$field] ?? null)) {
                $entry['errors'][$field] = "{$field} is required.";
            }
        }
    }

    private function dateRange(array &$entry, string $from, string $to): void
    {
        if ($this->values->isDate((string) ($entry['payload'][$from] ?? '')) && $this->values->isDate((string) ($entry['payload'][$to] ?? ''))
            && $entry['payload'][$to] < $entry['payload'][$from]) {
            $entry['errors'][$to] = "{$to} cannot precede {$from}.";
        }
    }

    private function orderedDates(array &$entry, string $start, array $fields): void
    {
        foreach ($fields as $field) {
            if ($this->values->isDate((string) ($entry['payload'][$start] ?? '')) && $this->values->isDate((string) ($entry['payload'][$field] ?? ''))
                && $entry['payload'][$field] < $entry['payload'][$start]) {
                $entry['errors'][$field] = "{$field} cannot precede {$start}.";
            }
        }
    }

    /** @return array<string, int> */
    private function organizationMap($models): array
    {
        $map = [];
        foreach ($models as $model) {
            $map['id:'.(int) $model->id] = (int) $model->id;
            if (filled($model->code)) {
                $map[mb_strtolower(trim((string) $model->code))] = (int) $model->id;
            }
        }

        return $map;
    }

    private function resolveOrganization(string $reference, array $map): ?int
    {
        return $map[mb_strtolower(trim($reference))] ?? null;
    }

    /** @return array<int, string> */
    private function dateFields(string $sheet): array
    {
        return match ($sheet) {
            'employees' => ['date_of_birth', 'hire_date', 'probation_end_date', 'notice_date', 'exit_date'],
            'assignments', 'compensation' => ['effective_from', 'effective_to'],
            'leave_balances' => ['effective_date'],
            'leave_history' => ['start_date', 'end_date'],
            'payroll_history' => ['pay_period_start', 'pay_period_end'],
            default => [],
        };
    }

    /** @return array<int, string> */
    private function employeeRefFields(string $sheet): array
    {
        return $sheet === 'assignments' ? ['employee_ref', 'manager_ref'] : ['employee_ref'];
    }

    private function identifier(string $sheet, array $row): ?string
    {
        $parts = match ($sheet) {
            'employees' => [$row['employee_ref'] ?? null],
            'assignments' => [$row['employee_ref'] ?? null, $row['effective_from'] ?? null],
            'compensation' => [$row['package_ref'] ?? null],
            'compensation_components' => [$row['package_ref'] ?? null, $row['component_code'] ?? null],
            'leave_balances' => [$row['employee_ref'] ?? null, $row['leave_type_code'] ?? null, $row['effective_date'] ?? null],
            'leave_history' => [$row['employee_ref'] ?? null, $row['leave_type_code'] ?? null, $row['start_date'] ?? null, $row['end_date'] ?? null],
            'payroll_history' => [$row['payroll_ref'] ?? null, $row['employee_ref'] ?? null],
            'payroll_components' => [$row['payroll_ref'] ?? null, $row['employee_ref'] ?? null, $row['component_code'] ?? null],
        };
        if (collect($parts)->contains(fn ($part) => ! filled($part))) {
            return null;
        }

        return $sheet.':'.mb_strtolower(implode('|', $parts));
    }
}
