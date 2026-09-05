<?php

$payUrlHosts = array_values(array_filter(array_map(
    static fn (string $host): string => strtolower(trim($host)),
    explode(',', (string) env('SKIPCASH_PAY_URL_HOSTS', ''))
)));

return [
    'public_order_branch_id' => 1,
    'money_scale' => 100,
    'system_user_id' => env('SYSTEM_USER_ID'),
    'customer_direct_order_enabled' => (bool) env('CUSTOMER_DIRECT_ORDER_ENABLED', true),

    'membership' => [
        'checkout_enabled' => (bool) env('MEMBERSHIP_CHECKOUT_ENABLED', false),
        'queue_enabled' => (bool) env('MEMBERSHIP_QUEUE_ENABLED', false),
    ],

    'defaults' => [
        'checkout_duration_minutes' => 15,
        'booking_cutoff_time' => '23:00:00',
        'timezone' => 'Asia/Qatar',
        'order_support_phone' => env('PAYMENT_ORDER_SUPPORT_PHONE'),
    ],

    'skipcash' => [
        'driver' => env('SKIPCASH_DRIVER', 'http'),
        'enabled' => (bool) env('SKIPCASH_ENABLED', false),
        'environment' => env('SKIPCASH_ENVIRONMENT', 'sandbox'),
        'base_url' => env('SKIPCASH_BASE_URL'),
        'client_id' => env('SKIPCASH_CLIENT_ID'),
        'key_id' => env('SKIPCASH_KEY_ID'),
        'secret_key' => env('SKIPCASH_SECRET_KEY'),
        'webhook_secret' => env('SKIPCASH_WEBHOOK_SECRET'),
        'return_url' => env('SKIPCASH_RETURN_URL'),
        'webhook_url' => env('SKIPCASH_WEBHOOK_URL'),
        'currency' => env('SKIPCASH_CURRENCY', 'QAR'),
        'timezone' => env('SKIPCASH_TIMEZONE', 'Asia/Qatar'),
        'raw_event_retention_days' => (int) env('SKIPCASH_RAW_EVENT_RETENTION_DAYS', 90),
        'clearing_account_id' => env('SKIPCASH_CLEARING_ACCOUNT_ID'),
        'pay_url_hosts' => $payUrlHosts,
        'connect_timeout_seconds' => 2,
        'create_timeout_seconds' => 8,
        'detail_timeout_seconds' => 5,
        'worker_timeout_seconds' => 30,
        'stale_claim_seconds' => 60,
        'recovery_batch_size' => (int) env('SKIPCASH_RECOVERY_BATCH_SIZE', 100),
        'recovery_max_attempts' => (int) env('SKIPCASH_RECOVERY_MAX_ATTEMPTS', 5),
        'recovery_retry_base_minutes' => (int) env('SKIPCASH_RECOVERY_RETRY_BASE_MINUTES', 1),
        'detail_recheck_minutes' => (int) env('SKIPCASH_DETAIL_RECHECK_MINUTES', 5),
        'notification_max_attempts' => (int) env('SKIPCASH_NOTIFICATION_MAX_ATTEMPTS', 5),
        'notification_retry_base_minutes' => (int) env('SKIPCASH_NOTIFICATION_RETRY_BASE_MINUTES', 1),
    ],
];
