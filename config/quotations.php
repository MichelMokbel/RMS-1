<?php

return [
    'storage' => [
        'disk' => env(
            'QUOTATIONS_DISK',
            env('APP_ENV') === 'production' ? 's3' : env('FILESYSTEM_DISK', 'local'),
        ),
        'temporary_url_minutes' => (int) env('QUOTATIONS_DOWNLOAD_TTL_MINUTES', 15),
    ],
    'default_validity_days' => (int) env('QUOTATIONS_DEFAULT_VALIDITY_DAYS', 30),
    'expiry_time' => env('QUOTATIONS_EXPIRY_TIME', '00:30'),
    'max_asset_kb' => (int) env('QUOTATIONS_MAX_ASSET_KB', 5120),
    'allowed_asset_mimes' => [
        'image/jpeg',
        'image/png',
        'image/webp',
    ],
    'currency' => 'QAR',
    'paper' => [
        'size' => 'A4',
        'orientation' => 'portrait',
        'margins_mm' => [
            'top' => 15,
            'right' => 15,
            'bottom' => 15,
            'left' => 15,
        ],
    ],
];
