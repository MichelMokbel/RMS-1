<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Marketing Module
    |--------------------------------------------------------------------------
    |
    | Runtime configuration for the marketing module. Sensitive credentials
    | (tokens, secrets) are stored encrypted in marketing_settings and never
    | placed here. This file holds non-sensitive defaults and tuning knobs.
    |
    */

    'meta' => [
        'api_version' => env('META_API_VERSION', 'v21.0'),
        'base_url' => env('META_API_BASE_URL', 'https://graph.facebook.com'),
        'timeout' => 30,
        'per_page' => 200,
    ],

    'google_ads' => [
        'timeout' => 60,
        'api_version' => env('GOOGLE_ADS_API_VERSION', 'v23'),
        'transport' => env('GOOGLE_ADS_TRANSPORT', 'rest'),
        'oauth_timeout' => 30,
    ],

    'sync' => [
        'campaign_schedule' => env('MARKETING_CAMPAIGN_SYNC_TIME', '06:00'),
        'spend_schedule' => env('MARKETING_SPEND_SYNC_TIME', '07:00'),
        'spend_lookback_days' => (int) env('MARKETING_SPEND_LOOKBACK_DAYS', 3),
    ],

    'assets' => [
        'presign_put_ttl_minutes' => 15,
        'presign_get_ttl_minutes' => 60,
        'max_file_size_mb' => (int) env('MARKETING_ASSET_MAX_SIZE_MB', 100),
        'key_prefix' => 'marketing-assets',
    ],

];
