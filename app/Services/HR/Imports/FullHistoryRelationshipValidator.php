<?php

namespace App\Services\HR\Imports;

class FullHistoryRelationshipValidator
{
    public function __construct(protected FullHistoryValueParser $values) {}

    public function validate(array &$rows, array $sheets): void
    {
        $this->assignmentsPerEmployee($rows);
        $this->compensationComponents($rows, $sheets['compensation']);
        $this->payrollComponents($rows);
        $this->payrollRunConsistency($rows);
        $this->leaveBalanceChronology($rows);
        $this->intervals($rows, 'assignments', 'employee_ref', 'effective_from', 'effective_to');
        $this->intervals($rows, 'compensation', 'employee_ref', 'effective_from', 'effective_to');
    }

    private function assignmentsPerEmployee(array &$rows): void
    {
        $assigned = [];
        foreach ($rows as $row) {
            if ($row['sheet'] === 'assignments') {
                $assigned[$this->key($row['payload']['employee_ref'] ?? null)] = true;
            }
        }
        foreach ($rows as &$row) {
            if ($row['sheet'] === 'employees' && ! isset($assigned[$this->key($row['payload']['employee_ref'] ?? null)])) {
                $row['errors']['assignments'] = 'Every workbook employee requires at least one assignment.';
            }
        }
    }

    private function compensationComponents(array &$rows, array $packageRows): void
    {
        $packages = [];
        foreach ($packageRows as $row) {
            if (filled($row['package_ref'] ?? null)) {
                $packages[$this->key($row['package_ref'])] = $this->key($row['employee_ref'] ?? null);
            }
        }
        $groups = [];
        foreach ($rows as &$row) {
            if ($row['sheet'] !== 'compensation_components') {
                continue;
            }
            $package = $this->key($row['payload']['package_ref'] ?? null);
            if (! isset($packages[$package])) {
                $row['errors']['package_ref'] = 'Compensation package reference does not exist.';
            } elseif ($packages[$package] !== $this->key($row['payload']['employee_ref'] ?? null)) {
                $row['errors']['employee_ref'] = 'Component employee does not match its compensation package.';
            }
            $groups[$package][] = $row['payload'];
        }
        unset($row);
        foreach ($packages as $package => $employeeRef) {
            $basic = count(array_filter($groups[$package] ?? [], fn ($row) => ($row['category'] ?? null) === 'basic'));
            if ($basic !== 1) {
                foreach ($rows as &$row) {
                    if ($row['sheet'] === 'compensation' && $this->key($row['payload']['package_ref'] ?? null) === $package) {
                        $row['errors']['components'] = 'Each compensation package requires exactly one basic component.';
                    }
                }
                unset($row);
            }
        }
    }

    private function payrollComponents(array &$rows): void
    {
        $payroll = [];
        $components = [];
        foreach ($rows as &$row) {
            $key = $this->payrollKey($row['payload']);
            if ($row['sheet'] === 'payroll_history') {
                $payroll[$key] = $row['payload'];
            } elseif ($row['sheet'] === 'payroll_components') {
                if (! isset($payroll[$key]) && ! $this->payrollRowExists($rows, $key)) {
                    $row['errors']['payroll_ref'] = 'Payroll result reference does not exist.';
                }
                $components[$key][] = $row['payload'];
            }
        }
        unset($row);
        foreach ($payroll as $key => $result) {
            $items = $components[$key] ?? [];
            $totals = ['basic' => 0, 'earnings' => 0, 'deduction' => 0];
            foreach ($items as $component) {
                $category = $component['category'] ?? '';
                $bucket = $category === 'basic' ? 'basic' : ($category === 'deduction' ? 'deduction' : 'earnings');
                $totals[$bucket] += (int) ($component['amount_minor'] ?? 0);
            }
            if ($items !== [] && ($totals['basic'] !== ($result['basic_minor'] ?? null) || $totals['earnings'] !== ($result['earnings_minor'] ?? null) || $totals['deduction'] !== ($result['deductions_minor'] ?? null))) {
                foreach ($rows as &$row) {
                    if ($row['sheet'] === 'payroll_history' && $this->payrollKey($row['payload']) === $key) {
                        $row['errors']['components'] = 'Payroll components must reconcile to basic, earnings, and deductions totals.';
                    }
                }
                unset($row);
            }
        }
    }

    private function payrollRunConsistency(array &$rows): void
    {
        $runs = [];
        $employeePeriods = [];
        foreach ($rows as &$row) {
            if ($row['sheet'] !== 'payroll_history') {
                continue;
            }
            $payload = $row['payload'];
            $ref = $this->key($payload['payroll_ref'] ?? null);
            $signature = implode('|', [$payload['pay_period_start'] ?? '', $payload['pay_period_end'] ?? '', strtoupper((string) ($payload['currency'] ?? '')), $payload['paid_at'] ?? '', $payload['description'] ?? '']);
            if (isset($runs[$ref]) && $runs[$ref] !== $signature) {
                $row['errors']['payroll_ref'] = 'Rows sharing payroll_ref must have identical period, currency, paid_at, and description.';
            }
            $runs[$ref] ??= $signature;
            $natural = $this->key($payload['employee_ref'] ?? null).'|'.($payload['pay_period_start'] ?? '');
            if (isset($employeePeriods[$natural])) {
                $row['errors']['pay_period_start'] = 'An employee has duplicate payroll history for this period.';
            }
            $employeePeriods[$natural] = true;
        }
    }

    private function leaveBalanceChronology(array &$rows): void
    {
        $firstHistory = [];
        foreach ($rows as $row) {
            if ($row['sheet'] === 'leave_history') {
                $key = $this->key($row['payload']['employee_ref'] ?? null).'|'.$this->key($row['payload']['leave_type_code'] ?? null);
                $date = (string) ($row['payload']['start_date'] ?? '');
                if ($date !== '' && (! isset($firstHistory[$key]) || $date < $firstHistory[$key])) {
                    $firstHistory[$key] = $date;
                }
            }
        }
        foreach ($rows as &$row) {
            if ($row['sheet'] !== 'leave_balances') {
                continue;
            }
            $key = $this->key($row['payload']['employee_ref'] ?? null).'|'.$this->key($row['payload']['leave_type_code'] ?? null);
            if (isset($firstHistory[$key]) && ($row['payload']['effective_date'] ?? '') > $firstHistory[$key]) {
                $row['errors']['effective_date'] = 'Opening balance must be effective on or before imported leave history.';
            }
        }
    }

    private function intervals(array &$rows, string $sheet, string $groupField, string $fromField, string $toField): void
    {
        $groups = [];
        foreach ($rows as $index => $row) {
            if ($row['sheet'] === $sheet && $this->values->isDate((string) ($row['payload'][$fromField] ?? ''))) {
                $groups[$this->key($row['payload'][$groupField] ?? null)][] = $index;
            }
        }
        foreach ($groups as $indexes) {
            usort($indexes, fn ($a, $b) => strcmp((string) $rows[$a]['payload'][$fromField], (string) $rows[$b]['payload'][$fromField]));
            $priorEnd = null;
            $open = false;
            foreach ($indexes as $index) {
                $from = (string) $rows[$index]['payload'][$fromField];
                if ($open || ($priorEnd !== null && $from <= $priorEnd)) {
                    $rows[$index]['errors'][$fromField] = 'Effective-dated rows overlap.';
                }
                $priorEnd = filled($rows[$index]['payload'][$toField] ?? null) ? (string) $rows[$index]['payload'][$toField] : null;
                $open = $priorEnd === null;
            }
        }
    }

    private function payrollRowExists(array $rows, string $key): bool
    {
        return collect($rows)->contains(fn ($row) => $row['sheet'] === 'payroll_history' && $this->payrollKey($row['payload']) === $key);
    }

    private function payrollKey(array $payload): string
    {
        return $this->key($payload['payroll_ref'] ?? null).'|'.$this->key($payload['employee_ref'] ?? null);
    }

    private function key(mixed $value): string
    {
        return mb_strtolower(trim((string) $value));
    }
}
