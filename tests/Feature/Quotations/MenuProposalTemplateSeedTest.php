<?php

use App\Models\AccountingCompany;
use App\Models\DocumentTemplate;
use App\Services\Quotations\QuotationTemplateService;

it('seeds menu proposal and default layout upgrades idempotently per company', function () {
    $company = AccountingCompany::query()->create([
        'name' => 'Menu Proposal Company',
        'code' => 'MENU-PROPOSAL-CO',
        'base_currency' => 'QAR',
        'is_active' => true,
        'is_default' => false,
    ]);
    $migrationPath = database_path('migrations/2026_07_22_000100_seed_menu_proposal_quotation_templates.php');

    (require $migrationPath)->up();
    (require $migrationPath)->up();

    $menu = DocumentTemplate::query()->with('currentVersion')
        ->where('company_id', $company->id)
        ->where('type', DocumentTemplate::TYPE_QUOTATION)
        ->where('name', QuotationTemplateService::MENU_PROPOSAL_NAME)
        ->sole();
    $default = DocumentTemplate::query()->with('currentVersion')
        ->where('company_id', $company->id)
        ->where('type', DocumentTemplate::TYPE_QUOTATION)
        ->where('is_default', true)
        ->sole();
    $defaultBlocks = collect($default->currentVersion->blocks)->keyBy('type');

    expect(DocumentTemplate::query()
        ->where('company_id', $company->id)
        ->where('name', QuotationTemplateService::MENU_PROPOSAL_NAME)
        ->count())->toBe(1)
        ->and($menu->versions()->count())->toBe(1)
        ->and(data_get($defaultBlocks->get('quotation_metadata'), 'settings.column_span'))->toBe(6)
        ->and(data_get($defaultBlocks->get('quotation_metadata'), 'settings.new_row'))->toBeTrue()
        ->and(data_get($defaultBlocks->get('recipient_details'), 'settings.column_span'))->toBe(6)
        ->and(data_get($defaultBlocks->get('recipient_details'), 'settings.new_row'))->toBeFalse();
});

it('repairs an existing menu proposal whose current version is missing', function () {
    $company = AccountingCompany::query()->create([
        'name' => 'Menu Repair Company',
        'code' => 'MENU-REPAIR-CO',
        'base_currency' => 'QAR',
        'is_active' => true,
        'is_default' => false,
    ]);
    $service = app(QuotationTemplateService::class);
    $menu = $service->ensureMenuProposal($company);
    $menu->update(['current_version_id' => null]);

    $repaired = $service->ensureMenuProposal($company);
    $again = $service->ensureMenuProposal($company);

    expect($repaired->currentVersion)->not->toBeNull()
        ->and($repaired->current_version_id)->toBe($again->current_version_id)
        ->and($repaired->versions()->count())->toBe(2);
});

it('upgrades an existing menu proposal immutably while preserving edited category text', function () {
    $company = AccountingCompany::query()->create([
        'name' => 'Legacy Menu Company',
        'code' => 'LEGACY-MENU-CO',
        'base_currency' => 'QAR',
        'is_active' => true,
        'is_default' => false,
    ]);
    $service = app(QuotationTemplateService::class);
    $legacy = $service->menuProposalLayout();
    unset($legacy['styles']['document_mode']);
    $legacy['blocks'] = collect($legacy['blocks'])
        ->reject(fn (array $block) => $block['type'] === 'menu_pricing')
        ->map(function (array $block): array {
            if ($block['id'] === 'menu-salads') {
                $block['content'] = ['type' => 'doc', 'content' => [[
                    'type' => 'paragraph', 'attrs' => ['textAlign' => 'center'],
                    'content' => [['type' => 'text', 'text' => 'Preserved House Salad']],
                ]]];
            }
            if (in_array($block['type'], ['items_table', 'totals'], true)) {
                $block['settings']['hidden'] = false;
            }

            return $block;
        })->values()->all();
    $legacy['blocks'][] = [
        'id' => 'legacy-recipient', 'type' => 'recipient_details', 'direction' => 'auto',
        'settings' => ['column_span' => 12, 'new_row' => true, 'horizontal_alignment' => 'stretch'],
    ];
    $template = DocumentTemplate::query()->create([
        'company_id' => $company->id,
        'type' => DocumentTemplate::TYPE_QUOTATION,
        'name' => QuotationTemplateService::MENU_PROPOSAL_NAME,
        'is_active' => true,
        'is_default' => false,
    ]);
    $service->createVersion($template, $legacy);
    $migrationPath = database_path('migrations/2026_07_22_000101_upgrade_menu_proposal_template_mode.php');

    (require $migrationPath)->up();
    (require $migrationPath)->up();

    $template->refresh()->load('currentVersion');
    $blocks = collect($template->currentVersion->blocks);
    expect($template->versions()->count())->toBe(2)
        ->and($template->currentVersion->styles['document_mode'])->toBe('menu_proposal')
        ->and(json_encode($blocks->firstWhere('id', 'menu-salads')))->toContain('Preserved House Salad')
        ->and($blocks->where('type', 'menu_pricing'))->toHaveCount(1)
        ->and($blocks->where('type', 'recipient_details'))->toHaveCount(0)
        ->and(data_get($blocks->firstWhere('type', 'items_table'), 'settings.hidden'))->toBeTrue()
        ->and(data_get($blocks->firstWhere('type', 'totals'), 'settings.hidden'))->toBeTrue();
});
