<?php

use App\Services\Quotations\QuotationSchemaValidator;
use Illuminate\Validation\ValidationException;

uses(Tests\TestCase::class);

function validQuotationBlocks(): array
{
    return [
        ['id' => 'intro', 'type' => 'rich_text', 'direction' => 'rtl', 'settings' => [], 'content' => [
            'type' => 'doc',
            'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'عرض سعر']]]],
        ]],
        ['id' => 'items', 'type' => 'items_table', 'direction' => 'auto', 'settings' => []],
        ['id' => 'totals', 'type' => 'totals', 'direction' => 'ltr', 'settings' => []],
    ];
}

it('accepts the controlled block and Tiptap allowlists', function () {
    $blocks = (new QuotationSchemaValidator)->validateBlocks(validQuotationBlocks());

    expect($blocks)->toHaveCount(3)
        ->and($blocks[0]['direction'])->toBe('rtl');
});

it('requires exactly one items table and totals block', function () {
    $blocks = validQuotationBlocks();
    $blocks[] = ['id' => 'other-totals', 'type' => 'totals', 'direction' => 'auto', 'settings' => []];

    expect(fn () => (new QuotationSchemaValidator)->validateBlocks($blocks))
        ->toThrow(ValidationException::class);
});

it('rejects raw html, remote images, and unsupported block settings', function (array $mutated) {
    $blocks = validQuotationBlocks();
    $blocks[0] = array_replace_recursive($blocks[0], $mutated);

    expect(fn () => (new QuotationSchemaValidator)->validateBlocks($blocks))
        ->toThrow(ValidationException::class);
})->with([
    'raw html' => [['content' => '<script>alert(1)</script>']],
    'unsupported setting' => [['settings' => ['css' => 'display:none']]],
]);

it('only accepts registered asset ids for image blocks', function () {
    $blocks = validQuotationBlocks();
    array_unshift($blocks, [
        'id' => 'remote-image', 'type' => 'image', 'direction' => 'auto',
        'settings' => ['src' => 'https://example.com/image.png'],
    ]);

    expect(fn () => (new QuotationSchemaValidator)->validateBlocks($blocks))
        ->toThrow(ValidationException::class);
});

it('accepts only canonical controlled block layout and company header settings', function () {
    $blocks = validQuotationBlocks();
    array_unshift($blocks, [
        'id' => 'header',
        'type' => 'company_header',
        'direction' => 'auto',
        'settings' => [
            'column_span' => '6',
            'new_row' => true,
            'horizontal_alignment' => 'center',
            'show_logo' => true,
            'show_details' => false,
            'logo_position' => 'end',
            'alignment' => 'start',
        ],
    ]);
    $blocks[2]['settings'] = ['column_span' => 12, 'new_row' => true, 'horizontal_alignment' => 'stretch'];

    $validated = (new QuotationSchemaValidator)->validateBlocks($blocks);

    expect(data_get($validated, '0.settings.column_span'))->toBe(6)
        ->and(data_get($validated, '0.settings.logo_position'))->toBe('end')
        ->and(data_get($validated, '2.settings.horizontal_alignment'))->toBe('stretch');
});

it('accepts controlled menu pricing, visibility, and document mode metadata', function () {
    $blocks = validQuotationBlocks();
    array_unshift($blocks, [
        'id' => 'pricing', 'type' => 'menu_pricing', 'direction' => 'auto',
        'settings' => ['column_span' => '12', 'new_row' => true, 'horizontal_alignment' => 'center', 'hidden' => false],
    ]);
    $validator = new QuotationSchemaValidator;

    $validated = $validator->validateBlocks($blocks);
    $styles = $validator->validateStyles(['document_mode' => 'menu_proposal']);

    expect(data_get($validated, '0.settings.column_span'))->toBe(12)
        ->and(data_get($validated, '0.settings.hidden'))->toBeFalse()
        ->and($styles['document_mode'])->toBe('menu_proposal');
});

it('rejects unsafe or non-canonical block layout metadata', function (array $settings) {
    $blocks = validQuotationBlocks();
    $blocks[0]['settings'] = $settings;

    expect(fn () => (new QuotationSchemaValidator)->validateBlocks($blocks))
        ->toThrow(ValidationException::class);
})->with([
    'nested legacy layout' => [['layout' => ['column_span' => 6]]],
    'unsupported span' => [['column_span' => 5]],
    'non boolean row flag' => [['new_row' => 1]],
    'unsafe alignment' => [['horizontal_alignment' => 'javascript']],
    'non boolean hidden flag' => [['hidden' => 'false']],
]);

it('rejects invalid company header placement values', function (string $key, mixed $value) {
    $blocks = validQuotationBlocks();
    array_unshift($blocks, [
        'id' => 'header', 'type' => 'company_header', 'direction' => 'auto',
        'settings' => [$key => $value],
    ]);

    expect(fn () => (new QuotationSchemaValidator)->validateBlocks($blocks))
        ->toThrow(ValidationException::class);
})->with([
    'logo position' => ['logo_position', 'floating'],
    'header alignment' => ['alignment', 'left'],
    'company details flag' => ['show_details', 0],
]);

it('rejects unsupported document modes', function () {
    expect(fn () => (new QuotationSchemaValidator)->validateStyles(['document_mode' => 'html']))
        ->toThrow(ValidationException::class);
});

it('defaults legacy menu content to structured mode', function () {
    $styles = (new QuotationSchemaValidator)->validateStyles([
        'document_mode' => 'menu_proposal',
    ]);

    expect($styles['menu_content_mode'])->toBe('structured');
});

it('accepts a controlled free-form menu body with optional pricing', function () {
    $blocks = [
        [
            'id' => 'body',
            'type' => 'menu_body',
            'direction' => 'auto',
            'settings' => [],
            'content' => [
                'type' => 'doc',
                'content' => [
                    ['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => 'Menu']]],
                    ['type' => 'quotationPricing'],
                    ['type' => 'quotationImage', 'attrs' => [
                        'assetId' => 42,
                        'widthMm' => 80,
                        'alignment' => 'center',
                        'alt' => 'Buffet',
                        'direction' => 'ltr',
                    ]],
                    ['type' => 'documentSpacer', 'attrs' => ['heightMm' => 8]],
                    ['type' => 'pageBreak'],
                ],
            ],
        ],
        ['id' => 'items', 'type' => 'items_table', 'direction' => 'auto', 'settings' => ['hidden' => true]],
        ['id' => 'totals', 'type' => 'totals', 'direction' => 'auto', 'settings' => ['hidden' => true]],
    ];

    $validated = (new QuotationSchemaValidator)->validateBlocks($blocks, 'free_form');

    expect($validated)->toHaveCount(3)
        ->and(data_get($validated, '0.content.content.1.type'))->toBe('quotationPricing')
        ->and(data_get($validated, '0.content.content.2.attrs.assetId'))->toBe(42);

    data_set($blocks, '0.content.content', [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Pricing can be omitted']]],
    ]);
    expect((new QuotationSchemaValidator)->validateBlocks($blocks, 'free_form'))->toHaveCount(3);
});

it('rejects duplicate pricing and unsafe controlled free-form nodes', function (array $content) {
    $blocks = [
        [
            'id' => 'body',
            'type' => 'menu_body',
            'direction' => 'auto',
            'settings' => [],
            'content' => ['type' => 'doc', 'content' => $content],
        ],
        ['id' => 'items', 'type' => 'items_table', 'direction' => 'auto', 'settings' => []],
        ['id' => 'totals', 'type' => 'totals', 'direction' => 'auto', 'settings' => []],
    ];

    expect(fn () => (new QuotationSchemaValidator)->validateBlocks($blocks, 'free_form'))
        ->toThrow(ValidationException::class);
})->with([
    'duplicate pricing' => [[['type' => 'quotationPricing'], ['type' => 'quotationPricing']]],
    'remote image' => [[['type' => 'quotationImage', 'attrs' => ['assetId' => 1, 'src' => 'https://example.com/a.png']]]],
    'missing asset' => [[['type' => 'quotationImage', 'attrs' => ['alt' => 'No registered image']]]],
    'invalid spacer' => [[['type' => 'documentSpacer', 'attrs' => ['heightMm' => 500]]]],
    'editable pricing text' => [[['type' => 'quotationPricing', 'content' => [['type' => 'text', 'text' => 'Fake price']]]]],
]);

it('requires menu body only in free-form mode', function () {
    $blocks = [
        ['id' => 'body', 'type' => 'menu_body', 'direction' => 'auto', 'settings' => [], 'content' => [
            'type' => 'doc', 'content' => [['type' => 'paragraph']],
        ]],
        ['id' => 'items', 'type' => 'items_table', 'direction' => 'auto', 'settings' => []],
        ['id' => 'totals', 'type' => 'totals', 'direction' => 'auto', 'settings' => []],
    ];

    expect(fn () => (new QuotationSchemaValidator)->validateBlocks($blocks))
        ->toThrow(ValidationException::class);

    $blocks[0] = ['id' => 'legacy', 'type' => 'rich_text', 'direction' => 'auto', 'settings' => [], 'content' => [
        'type' => 'doc', 'content' => [['type' => 'paragraph']],
    ]];
    expect(fn () => (new QuotationSchemaValidator)->validateBlocks($blocks, 'free_form'))
        ->toThrow(ValidationException::class);
});

it('only permits free-form content on menu proposals', function () {
    expect(fn () => (new QuotationSchemaValidator)->validateStyles([
        'document_mode' => 'standard',
        'menu_content_mode' => 'free_form',
    ]))->toThrow(ValidationException::class);
});
