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
            'content_hash' => '40b3b3d77ecc84a9d9a984185c77a96b1fce6a4ca5064cb7faff15bf230ee219',
        ],
        [
            'version' => '2026-09-09-v3',
            'effective_at' => '2026-09-09T00:00:01+00:00',
            'url' => env('PAYMENT_TERMS_URL', 'https://layla-kitchen.com/terms-and-conditions'),
            'content_path' => 'resources/legal/payment-terms/2026-09-09-v3.md',
            'content_hash' => '1e68c9f496406d1ac26ecec6f74e6ef68968e3e8f5058c35761a2294d4e82fd4',
        ],
        [
            'version' => '2026-09-12-v4',
            'effective_at' => '2026-09-12T00:00:00+00:00',
            'url' => env('PAYMENT_TERMS_URL', 'https://layla-kitchen.com/terms-and-conditions'),
            'content_path' => 'resources/legal/payment-terms/2026-09-12-v4.md',
            'content_hash' => 'a4733d5ef4f30e22f775f398637c9011df96e15f9cac03d163a7a74772c9d074',
        ],
    ],
];
