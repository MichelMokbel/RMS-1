<?php

return [
    /*
     * Add immutable published entries here before enabling checkout.
     * Each entry needs version, effective_at in UTC, url, content_path,
     * and the lowercase SHA256 content_hash of that retained file.
     */
    'published' => [
        [
            'version' => '2026-09-05-v1',
            'effective_at' => '2026-09-05T00:00:00+00:00',
            'url' => 'https://layla-kitchen.com/terms-and-conditions',
            'content_path' => 'resources/legal/payment-terms/2026-09-05-v1.md',
            'content_hash' => '5903d1af3e27b31f822f6d38e2be977fd62ae4151352160eef23b1cbb282aa54',
        ],
    ],
];
