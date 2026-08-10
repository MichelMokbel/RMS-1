<?php

use App\Services\Quotations\Storage\QuotationAssetService;

uses(Tests\TestCase::class);

it('collects registered image ids from structured and free-form documents', function (): void {
    $blocks = [
        ['type' => 'image', 'settings' => ['asset_id' => 11]],
        [
            'type' => 'menu_body',
            'content' => [
                'type' => 'doc',
                'content' => [
                    ['type' => 'paragraph'],
                    ['type' => 'quotationImage', 'attrs' => ['assetId' => 22]],
                    [
                        'type' => 'bulletList',
                        'content' => [
                            ['type' => 'listItem', 'content' => [
                                ['type' => 'quotationImage', 'attrs' => ['assetId' => 33]],
                            ]],
                        ],
                    ],
                    ['type' => 'quotationImage', 'attrs' => ['assetId' => 22]],
                ],
            ],
        ],
    ];

    expect(app(QuotationAssetService::class)->assetIdsFromBlocks($blocks))
        ->toBe([11, 22, 33]);
});
