<?php

return [
    // Order generation
    'generated_order_status' => env('SUBSCRIPTIONS_ORDER_STATUS', 'Confirmed'),
    'generated_item_quantity' => (float) env('SUBSCRIPTIONS_ITEM_QUANTITY', 1.0),
    'include_all_matching_role_items' => (bool) env('SUBSCRIPTIONS_INCLUDE_ALL_ROLE_ITEMS', true),
    'generation_time' => env('SUBSCRIPTIONS_GENERATION_TIME', '06:00'),
];

