<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'customer_sms' => [
        'provider' => env('CUSTOMER_SMS_PROVIDER', 'aws_sns'),
        'region' => env('AWS_SMS_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),
        'sender_id' => env('AWS_SMS_SENDER_ID'),
        'origination_number' => env('AWS_SMS_ORIGINATION_NUMBER'),
        'sms_type' => env('AWS_SMS_TYPE', 'Transactional'),
        'monthly_spend_limit_usd' => env('AWS_SMS_MONTHLY_SPEND_LIMIT_USD'),
        'delivery_status_iam_role_arn' => env('AWS_SMS_DELIVERY_STATUS_IAM_ROLE_ARN'),
        'delivery_status_success_sampling_rate' => env('AWS_SMS_DELIVERY_STATUS_SUCCESS_SAMPLING_RATE'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'recaptcha' => [
        'enabled' => env('RECAPTCHA_ENABLED', false),
        'secret' => env('RECAPTCHA_SECRET'),
        'min_score' => env('RECAPTCHA_MIN_SCORE', 0.5),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
        'base_url' => env('GEMINI_API_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
    ],

    'meta' => [
        'api_version' => env('META_API_VERSION', 'v21.0'),
        'base_url' => env('META_API_BASE_URL', 'https://graph.facebook.com'),
    ],

    'google_ads' => [
        'api_version' => env('GOOGLE_ADS_API_VERSION', 'v23'),
        'transport' => env('GOOGLE_ADS_TRANSPORT', 'rest'),
    ],

];
