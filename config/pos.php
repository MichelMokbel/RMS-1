<?php

$parseReceiptLines = static function ($value): array {
    if ($value === null) {
        return [];
    }

    $lines = is_array($value) ? $value : explode('|', (string) $value);

    return array_values(array_filter(
        array_map(static fn ($line) => trim((string) $line), $lines),
        static fn (string $line) => $line !== ''
    ));
};

return [
    'currency' => env('POS_CURRENCY', 'QAR'),
    'money_scale' => (int) env('POS_MONEY_SCALE', 100),
    'print_jobs' => [
        'pull_wait_seconds' => (int) env('POS_PRINT_PULL_WAIT_SECONDS', 0),
        'pull_idle_sleep_ms' => (int) env('POS_PRINT_PULL_IDLE_SLEEP_MS', 250),
        'claim_ttl_seconds' => (int) env('POS_PRINT_CLAIM_TTL_SECONDS', 45),
        'retry_base_seconds' => (int) env('POS_PRINT_RETRY_BASE_SECONDS', 2),
        'retry_max_seconds' => (int) env('POS_PRINT_RETRY_MAX_SECONDS', 60),
        'max_attempts' => (int) env('POS_PRINT_MAX_ATTEMPTS', 5),
        'online_window_seconds' => (int) env('POS_PRINT_ONLINE_WINDOW_SECONDS', 45),
        'fallback_poll_seconds' => (int) env('POS_PRINT_FALLBACK_POLL_SECONDS', 60),
        'stream_heartbeat_seconds' => (int) env('POS_PRINT_STREAM_HEARTBEAT_SECONDS', 15),
        'stream_idle_sleep_ms' => (int) env('POS_PRINT_STREAM_IDLE_SLEEP_MS', 250),
        'stream_max_seconds' => (int) env('POS_PRINT_STREAM_MAX_SECONDS', 55),
        'stream_event_batch_size' => (int) env('POS_PRINT_STREAM_EVENT_BATCH_SIZE', 50),
        'stream_dispatch_batch_size' => (int) env('POS_PRINT_STREAM_DISPATCH_BATCH_SIZE', 20),
        'stream_active_window_seconds' => (int) env('POS_PRINT_STREAM_ACTIVE_WINDOW_SECONDS', 20),
        'stream_event_retention_hours' => (int) env('POS_PRINT_STREAM_EVENT_RETENTION_HOURS', 24),
    ],
    'receipt_profile' => [
        'brand_name_en' => (string) env('POS_RECEIPT_BRAND_NAME_EN', env('APP_NAME', 'Laravel')),
        'brand_name_ar' => (string) env('POS_RECEIPT_BRAND_NAME_AR', ''),
        'legal_name_en' => (string) env('POS_RECEIPT_LEGAL_NAME_EN', ''),
        'legal_name_ar' => (string) env('POS_RECEIPT_LEGAL_NAME_AR', ''),
        'branch_name_en' => (string) env('POS_RECEIPT_BRANCH_NAME_EN', ''),
        'branch_name_ar' => (string) env('POS_RECEIPT_BRANCH_NAME_AR', ''),
        'address_lines_en' => $parseReceiptLines(env('POS_RECEIPT_ADDRESS_LINES_EN', '')),
        'address_lines_ar' => $parseReceiptLines(env('POS_RECEIPT_ADDRESS_LINES_AR', '')),
        'phone' => (string) env('POS_RECEIPT_PHONE', ''),
        'logo_url' => (string) env('POS_RECEIPT_LOGO_URL', ''),
        'footer_note_en' => (string) env('POS_RECEIPT_FOOTER_NOTE_EN', ''),
        'footer_note_ar' => (string) env('POS_RECEIPT_FOOTER_NOTE_AR', ''),
        'timezone' => (string) env('POS_RECEIPT_TIMEZONE', ''),
    ],
];
