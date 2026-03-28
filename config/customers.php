<?php

return [
    'enforce_unique_phone' => env('CUSTOMERS_ENFORCE_UNIQUE_PHONE', false),
    'enforce_unique_email' => env('CUSTOMERS_ENFORCE_UNIQUE_EMAIL', false),
    'enforce_unique_customer_code' => env('CUSTOMERS_ENFORCE_UNIQUE_CUSTOMER_CODE', false),
    'default_country_code' => env('CUSTOMERS_DEFAULT_COUNTRY_CODE', '+974'),
    'local_phone_length' => env('CUSTOMERS_LOCAL_PHONE_LENGTH', 8),
    'verification_code_length' => env('CUSTOMER_VERIFICATION_CODE_LENGTH', 6),
    'verification_code_ttl_minutes' => env('CUSTOMER_VERIFICATION_CODE_TTL_MINUTES', 10),
    'verification_max_attempts' => env('CUSTOMER_VERIFICATION_MAX_ATTEMPTS', 5),
    'verification_max_sends' => env('CUSTOMER_VERIFICATION_MAX_SENDS', 3),
    'verification_resend_cooldown_seconds' => env('CUSTOMER_VERIFICATION_RESEND_COOLDOWN_SECONDS', 60),
    'registration_token_ttl_minutes' => env('CUSTOMER_REGISTRATION_TOKEN_TTL_MINUTES', 30),
];
