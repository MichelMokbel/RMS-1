<?php

return [
    'currency' => env('HR_CURRENCY', 'QAR'),
    'pay_frequency' => 'monthly',
    'proration_divisor' => (float) env('HR_PRORATION_DIVISOR', 30),

    'payroll' => [
        'currency' => env('HR_CURRENCY', 'QAR'),
        'proration_divisor' => (float) env('HR_PRORATION_DIVISOR', 30),
    ],

    'documents' => [
        'disk' => env('HR_DOCUMENT_DISK', 's3'),
        'max_size_kb' => (int) env('HR_DOCUMENT_MAX_SIZE_KB', 10_240),
        'allowed_mime_types' => [
            'application/pdf',
            'image/jpeg',
            'image/png',
        ],
        'expiry_warning_days' => [90, 60, 30],
        // Local/test accepts a no-op scanner; production fails closed unless ClamAV is configured.
        'scanner' => env('HR_DOCUMENT_SCANNER'),
        'clamav_binary' => env('HR_CLAMAV_BINARY', 'clamscan'),
        'scan_timeout' => (int) env('HR_DOCUMENT_SCAN_TIMEOUT', 30),
    ],

    'imports' => [
        'max_archive_files' => (int) env('HR_IMPORT_MAX_ARCHIVE_FILES', 2_000),
        'max_uncompressed_bytes' => (int) env('HR_IMPORT_MAX_UNCOMPRESSED_BYTES', 500 * 1024 * 1024),
    ],

    'account_mappings' => [
        'salary_expense' => 'payroll.salary_expense',
        'allowance_expense' => 'payroll.allowance_expense',
        'payroll_payable' => 'payroll.payable',
        'deduction_liability' => 'payroll.deduction_liability',
        'employee_advance' => 'payroll.employee_advance',
        'bank_clearing' => 'payroll.bank_clearing',
    ],

    // Presets are inactive templates. An administrator must confirm legal policy before activation.
    'qatar_leave_presets' => [
        ['code' => 'annual', 'name' => 'Annual Leave', 'is_paid' => true],
        ['code' => 'sick', 'name' => 'Sick Leave', 'is_paid' => true],
        ['code' => 'unpaid', 'name' => 'Unpaid Leave', 'is_paid' => false],
        ['code' => 'emergency', 'name' => 'Emergency Leave', 'is_paid' => true],
        ['code' => 'hajj', 'name' => 'Hajj Leave', 'is_paid' => false],
        ['code' => 'maternity', 'name' => 'Maternity Leave', 'is_paid' => true],
        ['code' => 'other', 'name' => 'Other Leave', 'is_paid' => false],
    ],

    'document_type_presets' => [
        ['code' => 'qid_front', 'name' => 'QID Front', 'requires_expiry_date' => true, 'is_required' => true],
        ['code' => 'qid_back', 'name' => 'QID Back', 'requires_expiry_date' => true, 'is_required' => true],
        ['code' => 'passport', 'name' => 'Passport', 'requires_expiry_date' => true],
        ['code' => 'residence_permit', 'name' => 'Residence / Work Permit', 'requires_expiry_date' => true],
        ['code' => 'employment_contract', 'name' => 'Employment Contract', 'requires_expiry_date' => false, 'is_required' => true],
        ['code' => 'health_certificate', 'name' => 'Health / Food-handler Certificate', 'requires_expiry_date' => true, 'is_required' => true, 'required_for' => ['chef', 'cook', 'kitchen', 'food handler']],
        ['code' => 'insurance_card', 'name' => 'Insurance Card', 'requires_expiry_date' => true],
        ['code' => 'licence', 'name' => 'Licence', 'requires_expiry_date' => true],
        ['code' => 'qualification', 'name' => 'Qualification', 'requires_expiry_date' => false],
    ],
];
