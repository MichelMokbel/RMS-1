<?php

return [
    'approval_exception_threshold' => (float) env('SPEND_APPROVAL_EXCEPTION_THRESHOLD', 1000),
    'high_risk_category_ids' => array_values(array_filter(array_map(
        fn (string $v) => (int) trim($v),
        explode(',', (string) env('SPEND_HIGH_RISK_CATEGORY_IDS', ''))
    ), fn (int $v) => $v > 0)),
    'petty_cash_internal_supplier_id' => (int) env('SPEND_PETTY_CASH_INTERNAL_SUPPLIER_ID', 0),
];
