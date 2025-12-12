<?php

return [
    'enforce_unique_phone' => env('CUSTOMERS_ENFORCE_UNIQUE_PHONE', false),
    'enforce_unique_email' => env('CUSTOMERS_ENFORCE_UNIQUE_EMAIL', false),
    'enforce_unique_customer_code' => env('CUSTOMERS_ENFORCE_UNIQUE_CUSTOMER_CODE', false),
];
