<?php

use App\Services\Quotations\QuotationSchemaValidator;
use App\Services\Quotations\QuotationTemplateService;

uses(Tests\TestCase::class);

it('builds the default metadata and recipient blocks as a half-width row', function () {
    $layout = app(QuotationTemplateService::class)->defaultLayout();
    $blocks = collect($layout['blocks'])->keyBy('type');

    expect(data_get($blocks->get('quotation_metadata'), 'settings.column_span'))->toBe(6)
        ->and(data_get($blocks->get('quotation_metadata'), 'settings.new_row'))->toBeTrue()
        ->and(data_get($blocks->get('recipient_details'), 'settings.column_span'))->toBe(6)
        ->and(data_get($blocks->get('recipient_details'), 'settings.new_row'))->toBeFalse();

    app(QuotationSchemaValidator::class)->validateBlocks($layout['blocks']);
});

it('builds a validated menu proposal only from representative source content', function () {
    $layout = app(QuotationTemplateService::class)->menuProposalLayout();
    $blocks = app(QuotationSchemaValidator::class)->validateBlocks($layout['blocks']);
    $encoded = json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    expect(collect($blocks)->where('type', 'items_table'))->toHaveCount(1)
        ->and(collect($blocks)->where('type', 'totals'))->toHaveCount(1)
        ->and(collect($blocks)->where('type', 'menu_pricing'))->toHaveCount(1)
        ->and($layout['styles']['document_mode'])->toBe('menu_proposal')
        ->and($layout['styles']['menu_content_mode'])->toBe('structured')
        ->and($encoded)->toContain('Buffet Menu Proposal')
        ->and($encoded)->toContain('Cold Appetizers')
        ->and($encoded)->toContain('Assorted Mix Mouajanat')
        ->and($encoded)->toContain('Fresh Arabic Bread')
        ->and($encoded)->toContain('"textAlign":"center"')
        ->and($encoded)->not->toContain('bulletList')
        ->and($encoded)->not->toContain('quotation_metadata')
        ->and($encoded)->not->toContain('recipient_details')
        ->and($encoded)->not->toContain('signature_lines')
        ->and($encoded)->not->toContain('QAR 130')
        ->and($encoded)->not->toContain('QAR 150')
        ->and($encoded)->not->toContain('QAR 170');

    $header = collect($blocks)->firstWhere('type', 'company_header');
    $items = collect($blocks)->firstWhere('type', 'items_table');
    $totals = collect($blocks)->firstWhere('type', 'totals');
    $categories = collect($blocks)->where('type', 'rich_text')->where('id', '!=', 'menu-title');
    expect(data_get($header, 'settings.show_details'))->toBeFalse()
        ->and(data_get($header, 'settings.logo_position'))->toBe('top')
        ->and(data_get($header, 'settings.alignment'))->toBe('center')
        ->and($categories->every(fn (array $block) => data_get($block, 'settings.column_span') === 6))->toBeTrue()
        ->and(data_get($items, 'settings.hidden'))->toBeTrue()
        ->and(data_get($totals, 'settings.hidden'))->toBeTrue();
});

it('converts menu proposals to free form while preserving content and pricing order', function () {
    $service = app(QuotationTemplateService::class);
    $freeForm = $service->convertMenuContentMode($service->menuProposalLayout(), 'free_form');
    $blocks = collect($freeForm['blocks']);
    $body = $blocks->firstWhere('type', 'menu_body');
    $nodes = collect(data_get($body, 'content.content', []));

    expect($freeForm['styles']['menu_content_mode'])->toBe('free_form')
        ->and($blocks->where('type', 'menu_body'))->toHaveCount(1)
        ->and($blocks->where('type', 'rich_text'))->toHaveCount(0)
        ->and($blocks->where('type', 'menu_pricing'))->toHaveCount(0)
        ->and($nodes->where('type', 'quotationPricing'))->toHaveCount(1)
        ->and(json_encode($nodes->all()))->toContain('Buffet Menu Proposal')
        ->and(json_encode($nodes->all()))->toContain('Salads');

    $titleIndex = $nodes->search(fn (array $node) => data_get($node, 'content.0.text') === 'Buffet Menu Proposal');
    $pricingIndex = $nodes->search(fn (array $node) => ($node['type'] ?? null) === 'quotationPricing');
    $saladsIndex = $nodes->search(fn (array $node) => data_get($node, 'content.0.text') === 'Salads');
    expect($titleIndex)->toBeLessThan($pricingIndex)
        ->and($pricingIndex)->toBeLessThan($saladsIndex);
});

it('moves inserted pricing back to its structured position and preserves its removal', function () {
    $service = app(QuotationTemplateService::class);
    $freeForm = $service->convertMenuContentMode($service->menuProposalLayout(), 'free_form');
    $bodyIndex = collect($freeForm['blocks'])->search(fn (array $block) => $block['type'] === 'menu_body');
    $nodes = data_get($freeForm, "blocks.$bodyIndex.content.content");
    $pricing = collect($nodes)->firstWhere('type', 'quotationPricing');
    $nodes = collect($nodes)->reject(fn (array $node) => $node['type'] === 'quotationPricing')->values()->all();
    array_splice($nodes, 1, 0, [$pricing]);
    data_set($freeForm, "blocks.$bodyIndex.content.content", $nodes);

    $structured = $service->convertMenuContentMode($freeForm, 'structured');
    $types = collect($structured['blocks'])->pluck('type')->values();
    $firstRich = $types->search('rich_text');
    $pricingIndex = $types->search('menu_pricing');
    expect($pricingIndex)->toBe($firstRich + 1);

    $freeAgain = $service->convertMenuContentMode($structured, 'free_form');
    $bodyIndex = collect($freeAgain['blocks'])->search(fn (array $block) => $block['type'] === 'menu_body');
    $withoutPricing = collect(data_get($freeAgain, "blocks.$bodyIndex.content.content"))
        ->reject(fn (array $node) => $node['type'] === 'quotationPricing')
        ->values()
        ->all();
    data_set($freeAgain, "blocks.$bodyIndex.content.content", $withoutPricing);

    $structuredWithoutPricing = $service->convertMenuContentMode($freeAgain, 'structured');
    expect(collect($structuredWithoutPricing['blocks'])->where('type', 'menu_pricing'))->toHaveCount(0);
});
