<?php

return [
    'allow_negative_stock' => env('INVENTORY_ALLOW_NEGATIVE_STOCK', false),
    'max_image_kb' => env('INVENTORY_MAX_IMAGE_KB', 2048),
    'default_branch_id' => env('INVENTORY_DEFAULT_BRANCH_ID', 1),
];
