<?php

return [
    'apply_reconciliation_to_wallet_balance' => env('PETTY_CASH_APPLY_RECONCILIATION', true),
    'allow_negative_wallet_balance' => env('PETTY_CASH_ALLOW_NEGATIVE_BALANCE', false),
    'max_receipt_kb' => env('PETTY_CASH_MAX_RECEIPT_KB', 4096),
];
