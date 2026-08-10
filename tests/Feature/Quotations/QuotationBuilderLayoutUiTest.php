<?php

use App\Models\AccountingCompany;
use App\Models\Branch;
use App\Models\User;
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
        'name' => 'Layout Company',
        'code' => 'LAYOUT-CO',
        'base_currency' => 'QAR',
        'is_active' => true,
        'is_default' => true,
    ]);
    Branch::query()->create([
        'company_id' => $this->company->id,
        'name' => 'Layout Branch',
        'code' => 'LAYOUT',
        'is_active' => true,
    ]);
    $this->actingAs($this->user);
});

it('starts quotation metadata and recipient blocks side by side', function (): void {
    $component = Volt::test('quotations.create');
    $orderedBlocks = collect($component->get('blocks'));
    $blocks = $orderedBlocks->keyBy('type');

    expect(data_get($blocks->get('quotation_metadata'), 'settings.column_span'))->toBe(6)
        ->and(data_get($blocks->get('quotation_metadata'), 'settings.new_row'))->toBeTrue()
        ->and(data_get($blocks->get('recipient_details'), 'settings.column_span'))->toBe(6)
        ->and(data_get($blocks->get('recipient_details'), 'settings.new_row'))->toBeFalse();

    $metadataIndex = $orderedBlocks->search(fn (array $block) => $block['type'] === 'quotation_metadata');
    $component
        ->set("blocks.$metadataIndex.settings.column_span", 9)
        ->set("blocks.$metadataIndex.settings.horizontal_alignment", 'end')
        ->assertSet("blocks.$metadataIndex.settings.column_span", 9)
        ->assertSet("blocks.$metadataIndex.settings.horizontal_alignment", 'end')
        ->assertSee('Start on a new row');

    expect($component->instance()->previewHtml())
        ->toContain('data-column-span="9"')
        ->toContain('text-align:right');
});

it('exposes controlled placement and company header controls in the template editor', function (): void {
    $template = app(QuotationTemplateService::class)->ensureDefault($this->company, $this->user);

    $component = Volt::test('quotation-templates.edit', ['template' => $template])
        ->assertSee('Horizontal alignment')
        ->assertSee('Start on a new row')
        ->assertSee('Logo position')
        ->assertSee('Header content alignment');

    $headerIndex = collect($component->get('blocks'))->search(fn (array $block) => $block['type'] === 'company_header');
    $metadataIndex = collect($component->get('blocks'))->search(fn (array $block) => $block['type'] === 'quotation_metadata');
    $component
        ->set("blocks.$metadataIndex.settings.column_span", 9)
        ->set("blocks.$headerIndex.settings.logo_position", 'top')
        ->set("blocks.$headerIndex.settings.alignment", 'center')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet("blocks.$headerIndex.settings.logo_position", 'top')
        ->assertSet("blocks.$headerIndex.settings.alignment", 'center');

    $saved = $template->fresh('currentVersion')->currentVersion;
    $savedMetadata = collect($saved->blocks)->firstWhere('type', 'quotation_metadata');
    expect($saved->version)->toBe(2)
        ->and(data_get($savedMetadata, 'settings.column_span'))->toBe(9)
        ->and(data_get($savedMetadata, 'settings.column_span'))->toBeInt();
});
