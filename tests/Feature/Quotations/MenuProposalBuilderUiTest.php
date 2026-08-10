<?php

use App\Models\AccountingCompany;
use App\Models\Branch;
use App\Models\Quotation;
use App\Models\User;
use App\Services\Quotations\QuotationDraftService;
use App\Services\Quotations\QuotationTemplateService;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->user = User::factory()->create(['status' => 'active']);
    $this->user->assignRole(Role::findOrCreate('admin', 'web'));
    $this->user->givePermissionTo([
        Permission::findOrCreate('quotations.manage', 'web'),
        Permission::findOrCreate('quotation-templates.manage', 'web'),
    ]);
    $this->company = AccountingCompany::query()->create([
        'name' => 'Menu Builder Company', 'code' => 'MENU-BUILDER', 'base_currency' => 'QAR',
        'is_active' => true, 'is_default' => true,
    ]);
    $this->branch = Branch::query()->create([
        'company_id' => $this->company->id, 'name' => 'Menu Branch', 'code' => 'MENU', 'is_active' => true,
    ]);
    $this->actingAs($this->user);
    $this->menuTemplate = app(QuotationTemplateService::class)->ensureMenuProposal($this->company, $this->user);
});

it('switches to menu pricing and persists its normalized internal item', function (): void {
    $component = Volt::test('quotations.create')
        ->set('branch_id', $this->branch->id)
        ->set('template_version_id', $this->menuTemplate->current_version_id)
        ->assertSet('styles.document_mode', 'menu_proposal')
        ->assertSee('Menu pricing')
        ->assertSee('Price per person (QAR)')
        ->assertSee('Minimum order (persons)')
        ->assertDontSee('Add catalog item');

    expect($component->get('items'))->toHaveCount(1)
        ->and($component->get('items.0.description'))->toBe('Menu package')
        ->and($component->get('items.0.unit'))->toBe('person')
        ->and($component->get('items.0.discount_cents'))->toBe(0);

    $component
        ->set('items.0.quantity', '15')
        ->set('items.0.unit_price_cents', 15000)
        ->call('saveDraft')
        ->assertHasNoErrors();

    $quotation = Quotation::query()->latest('id')->with('items')->firstOrFail();
    expect($quotation->styles['document_mode'])->toBe('menu_proposal')
        ->and($quotation->items)->toHaveCount(1)
        ->and($quotation->items->first()->description)->toBe('Menu package')
        ->and($quotation->items->first()->unit)->toBe('person')
        ->and($quotation->items->first()->quantity)->toBe('15.000')
        ->and($quotation->items->first()->unit_price_cents)->toBe(15000)
        ->and($quotation->items->first()->discount_cents)->toBe(0);
});

it('preserves hidden commercial blocks while sorting visible menu sections', function (): void {
    $component = Volt::test('quotations.create')->set('branch_id', $this->branch->id)->set('template_version_id', $this->menuTemplate->current_version_id);
    $original = collect($component->get('blocks'));
    $hiddenIds = $original->filter(fn (array $block) => data_get($block, 'settings.hidden') === true)->pluck('id')->values();
    $visibleIds = $original->reject(fn (array $block) => data_get($block, 'settings.hidden') === true)->pluck('id')->reverse()->values();

    foreach ($hiddenIds as $hiddenId) {
        $component->assertDontSeeHtml('data-block-id="'.$hiddenId.'"');
    }
    $component->call('reorderBlocks', $visibleIds->all());

    $reordered = collect($component->get('blocks'));
    expect($reordered)->toHaveCount($original->count())
        ->and($reordered->filter(fn (array $block) => data_get($block, 'settings.hidden') === true)->pluck('id')->values()->all())->toBe($hiddenIds->all())
        ->and($reordered->reject(fn (array $block) => data_get($block, 'settings.hidden') === true)->pluck('id')->values()->all())->toBe($visibleIds->all());
});

it('normalizes the commercial item immediately when reapplying the menu template', function (): void {
    $draft = app(QuotationDraftService::class)->create($this->user, [
        'branch_id' => $this->branch->id,
        'recipient_name' => 'Internal menu recipient',
        'items' => [[
            'description' => 'Old catalog line', 'unit' => 'each', 'quantity' => '2.000',
            'unit_price_cents' => 2500, 'discount_cents' => 200,
        ]],
    ]);

    Volt::test('quotations.edit', ['quotation' => $draft])
        ->call('reapplyTemplate', $this->menuTemplate->current_version_id)
        ->assertHasNoErrors()
        ->assertRedirect(route('quotations.edit', $draft));

    $item = $draft->fresh('items')->items->sole();
    expect($draft->fresh()->styles['document_mode'])->toBe('menu_proposal')
        ->and($item->description)->toBe('Menu package')
        ->and($item->unit)->toBe('person')
        ->and($item->discount_cents)->toBe(0);
});

it('adds menu categories as alternating half-width centered rich text blocks in both editors', function (): void {
    $quote = Volt::test('quotations.create')->set('branch_id', $this->branch->id)->set('template_version_id', $this->menuTemplate->current_version_id);
    $quote->call('addBlock', 'rich_text')->call('addBlock', 'rich_text');
    $newQuoteBlocks = collect($quote->get('blocks'))->take(-2)->values();

    expect(data_get($newQuoteBlocks[0], 'settings.column_span'))->toBe(6)
        ->and(data_get($newQuoteBlocks[0], 'settings.new_row'))->toBeTrue()
        ->and(data_get($newQuoteBlocks[0], 'settings.horizontal_alignment'))->toBe('center')
        ->and(data_get($newQuoteBlocks[1], 'settings.column_span'))->toBe(6)
        ->and(data_get($newQuoteBlocks[1], 'settings.new_row'))->toBeFalse();

    $template = Volt::test('quotation-templates.edit', ['template' => $this->menuTemplate]);
    $beforeSort = collect($template->get('blocks'));
    $hiddenBeforeSort = $beforeSort->filter(fn (array $block) => data_get($block, 'settings.hidden') === true)->pluck('id')->values();
    $visibleReverse = $beforeSort->reject(fn (array $block) => data_get($block, 'settings.hidden') === true)->pluck('id')->reverse()->values();
    $template->call('reorderBlocks', $visibleReverse->all());
    $afterSort = collect($template->get('blocks'));
    expect($afterSort->filter(fn (array $block) => data_get($block, 'settings.hidden') === true)->pluck('id')->values()->all())->toBe($hiddenBeforeSort->all())
        ->and($afterSort->reject(fn (array $block) => data_get($block, 'settings.hidden') === true)->pluck('id')->values()->all())->toBe($visibleReverse->all());

    $template->call('addBlock', 'rich_text')->call('addBlock', 'rich_text');
    $newTemplateBlocks = collect($template->get('blocks'))->take(-2)->values();
    $hiddenIds = collect($template->get('blocks'))->filter(fn (array $block) => data_get($block, 'settings.hidden') === true)->pluck('id');

    expect(data_get($newTemplateBlocks[0], 'settings.column_span'))->toBe(6)
        ->and(data_get($newTemplateBlocks[0], 'settings.new_row'))->toBeTrue()
        ->and(data_get($newTemplateBlocks[0], 'settings.horizontal_alignment'))->toBe('center')
        ->and(data_get($newTemplateBlocks[1], 'settings.new_row'))->toBeFalse();
    foreach ($hiddenIds as $hiddenId) {
        $template->assertDontSeeHtml('data-block-id="'.$hiddenId.'"');
    }
});

it('switches a menu quotation to one free-form document and persists the converted draft', function (): void {
    $component = Volt::test('quotations.create')
        ->set('branch_id', $this->branch->id)
        ->set('template_version_id', $this->menuTemplate->current_version_id)
        ->call('changeMenuContentMode', 'free_form')
        ->assertSet('styles.menu_content_mode', 'free_form')
        ->assertSee('Place pricing here')
        ->assertSee('Remove pricing')
        ->assertDontSeeHtml('data-quotation-block-list')
        ->assertHasNoErrors();

    $blocks = collect($component->get('blocks'));
    $menuBody = $blocks->firstWhere('type', 'menu_body');
    expect($blocks->where('type', 'menu_body'))->toHaveCount(1)
        ->and($blocks->where('type', 'rich_text'))->toHaveCount(0)
        ->and($blocks->where('type', 'menu_pricing'))->toHaveCount(0)
        ->and(collect(data_get($menuBody, 'content.content'))->where('type', 'quotationPricing'))->toHaveCount(1);

    $component->call('saveDraft')->assertHasNoErrors();

    $quotation = Quotation::query()->latest('id')->firstOrFail();
    expect(data_get($quotation->styles, 'menu_content_mode'))->toBe('free_form')
        ->and(collect($quotation->blocks)->where('type', 'menu_body'))->toHaveCount(1);
});
