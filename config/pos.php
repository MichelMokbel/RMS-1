<?php

return [
    'currency' => env('POS_CURRENCY', 'QAR'),
    'money_scale' => (int) env('POS_MONEY_SCALE', 100),
];
