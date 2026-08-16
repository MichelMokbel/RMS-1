<?php

return [
    'apply_reconciliation_to_wallet_balance' => env('PETTY_CASH_APPLY_RECONCILIATION', true),
    'allow_negative_wallet_balance' => env('PETTY_CASH_ALLOW_NEGATIVE_BALANCE', false),
    'max_receipt_kb' => env('PETTY_CASH_MAX_RECEIPT_KB', 4096),
    'imports' => [
        'disk' => env('PETTY_CASH_IMPORT_DISK', env('FILESYSTEM_DISK', 'local')),
        'max_upload_kb' => (int) env('PETTY_CASH_IMPORT_MAX_UPLOAD_KB', 10_240),
        'max_rows' => (int) env('PETTY_CASH_IMPORT_MAX_ROWS', 5000),
        'max_groups' => (int) env('PETTY_CASH_IMPORT_MAX_GROUPS', 500),
    ],
];
