<?php

namespace App\Services\HR\Imports;

final class FullHistorySchema
{
    /** @var array<string, array<int, string>> */
    public const HEADERS = [
        'employees' => ['employee_ref', 'legacy_employee_number', 'legal_first_name', 'legal_middle_name', 'legal_last_name', 'display_name', 'preferred_name', 'work_email', 'personal_email', 'work_phone', 'personal_phone', 'date_of_birth', 'nationality', 'gender', 'qid_number', 'passport_number', 'address_line_1', 'address_line_2', 'city', 'country', 'emergency_contact_name', 'emergency_contact_relationship', 'emergency_contact_phone', 'hire_date', 'probation_end_date', 'notice_date', 'exit_date', 'exit_reason', 'employment_type', 'employment_status'],
        'assignments' => ['employee_ref', 'branch_code', 'department_code', 'manager_ref', 'job_title', 'employment_type', 'effective_from', 'effective_to', 'notes'],
        'compensation' => ['employee_ref', 'package_ref', 'effective_from', 'effective_to', 'currency', 'pay_frequency', 'proration_divisor', 'bank_name', 'beneficiary_name', 'bank_account_number', 'iban', 'swift_code', 'notes'],
        'compensation_components' => ['employee_ref', 'package_ref', 'component_code', 'component_name', 'category', 'amount_qar', 'is_taxable'],
        'leave_balances' => ['employee_ref', 'leave_type_code', 'effective_date', 'days', 'notes'],
        'leave_history' => ['employee_ref', 'leave_type_code', 'start_date', 'end_date', 'start_portion', 'end_portion', 'requested_days', 'status', 'reason'],
        'payroll_history' => ['payroll_ref', 'employee_ref', 'pay_period_start', 'pay_period_end', 'currency', 'basic_qar', 'gross_qar', 'earnings_qar', 'deductions_qar', 'net_qar', 'calendar_days', 'worked_days', 'unpaid_leave_days', 'paid_at', 'description'],
        'payroll_components' => ['payroll_ref', 'employee_ref', 'component_code', 'component_name', 'category', 'amount_qar', 'is_taxable'],
    ];

    /** @var array<int, string> */
    public const LOOKUP_SHEETS = ['instructions', 'branches', 'departments', 'leave_types'];

    /** @var array<string, array<int, string>> */
    public const REQUIRED = [
        'employees' => ['employee_ref', 'legal_first_name', 'legal_last_name', 'display_name', 'hire_date', 'employment_type', 'employment_status'],
        'assignments' => ['employee_ref', 'branch_code', 'job_title', 'employment_type', 'effective_from'],
        'compensation' => ['employee_ref', 'package_ref', 'effective_from', 'currency', 'pay_frequency', 'proration_divisor'],
        'compensation_components' => ['employee_ref', 'package_ref', 'component_code', 'component_name', 'category', 'amount_qar'],
        'leave_balances' => ['employee_ref', 'leave_type_code', 'effective_date', 'days'],
        'leave_history' => ['employee_ref', 'leave_type_code', 'start_date', 'end_date', 'requested_days', 'status'],
        'payroll_history' => ['payroll_ref', 'employee_ref', 'pay_period_start', 'pay_period_end', 'currency', 'basic_qar', 'gross_qar', 'earnings_qar', 'deductions_qar', 'net_qar'],
        'payroll_components' => ['payroll_ref', 'employee_ref', 'component_code', 'component_name', 'category', 'amount_qar'],
    ];
}
