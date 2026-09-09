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
            'url' => env('PAYMENT_TERMS_URL', 'https://layla-kitchen.com/terms-and-conditions'),
            'content_path' => 'resources/legal/payment-terms/2026-09-05-v1.md',
            'content_hash' => '5903d1af3e27b31f822f6d38e2be977fd62ae4151352160eef23b1cbb282aa54',
        ],
        [
            'version' => '2026-09-09-v2',
            'effective_at' => '2026-09-09T00:00:00+00:00',
            'url' => env('PAYMENT_TERMS_URL', 'https://layla-kitchen.com/terms-and-conditions'),
            'content_path' => 'resources/legal/payment-terms/2026-09-09-v2.md',
            'content_hash' => 'fa503d47a495706b6aa05eab34cae032fde7a0d6b39e1307a2d1a66f964c4444',
        ],
        [
            'version' => '2026-09-09-v3',
            'effective_at' => '2026-09-09T00:00:01+00:00',
            'url' => env('PAYMENT_TERMS_URL', 'https://layla-kitchen.com/terms-and-conditions'),
            'content_path' => 'resources/legal/payment-terms/2026-09-09-v3.md',
            'content_hash' => 'a546a256e29bc03b42207d2d73f38558bbab74324388d7e82a911aa44c7ea306',
        ],
    ],
];
