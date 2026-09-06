<?php

return [
    'enabled' => (bool) env('PAYMENT_CONSISTENCY_ENABLED', false),
    'batch_size' => (int) env('PAYMENT_CONSISTENCY_BATCH_SIZE', 100),
    'catchup_lookback_minutes' => 20,
    'timezone' => 'Asia/Qatar',
    'rules' => [
        'promotion_usage_v1' => [
            'version' => 1,
            'subject_type' => 'membership_promotion',
        ],
    ],
];
