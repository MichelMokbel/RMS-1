<?php

return [
    'enabled' => (bool) env('PAYMENT_CONSISTENCY_ENABLED', false),
    'batch_size' => (int) env('PAYMENT_CONSISTENCY_BATCH_SIZE', 100),
    'rules' => [
        'promotion_usage_v1' => [
            'version' => 1,
            'subject_type' => 'membership_promotion',
        ],
    ],
];
