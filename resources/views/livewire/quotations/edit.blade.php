<?php

use App\Models\Branch;
use App\Models\CompanyDocumentProfile;
use App\Models\Customer;
use App\Models\DocumentTemplate;
use App\Models\DocumentAsset;
use App\Models\MenuItem;
use App\Models\Quotation;
use App\Services\Quotations\QuotationDraftService;
use App\Services\Quotations\QuotationLifecycleService;
use App\Services\Quotations\QuotationTemplateService;
use App\Services\Quotations\QuotationTotalsCalculator;
use App\Services\Quotations\Rendering\QuotationHtmlRenderer;
use App\Services\Quotations\Storage\QuotationAssetService;
use App\Services\Security\BranchAccessService;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public Quotation $quotation;
    public int $branch_id = 0;
    public ?int $customer_id = null;
    public ?int $template_version_id = null;
    public string $recipient_name = '';
    public string $recipient_contact_name = '';
    public string $recipient_email = '';
    public string $recipient_phone = '';
    public string $recipient_address = '';
    public string $issue_date = '';
    public string $valid_until = '';
    public ?string $quotation_discount_type = null;
    public int $quotation_discount_value = 0;
    public string $customer_search = '';
    public string $catalog_search = '';
    public array $items = [];
    public array $blocks = [];
    public array $page_settings = [];
    public array $styles = [];
    public array $table_columns = [];

    public function mount(Quotation $quotation, BranchAccessService $access): void
    {
        abort_unless(auth()->user()?->can('quotations.manage'), 403);
        abort_unless($access->canAccessBranch(auth()->user(), (int) $quotation->branch_id), 403);
        abort_unless($quotation->status === 'draft', 409, __('Only draft quotations can be edited.'));
        $this->quotation = $quotation->load('items');
        $this->branch_id = (int) $quotation->branch_id;
        $this->customer_id = $quotation->customer_id;
        $this->template_version_id = $quotation->template_version_id;
        foreach (['recipient_name', 'recipient_contact_name', 'recipient_email', 'recipient_phone', 'recipient_address'] as $field) $this->{$field} = (string) $quotation->{$field};
        $this->issue_date = optional($quotation->issue_date)->toDateString() ?: now()->toDateString();
        $this->valid_until = optional($quotation->valid_until)->toDateString() ?: now()->addDays(30)->toDateString();
        $this->quotation_discount_type = $quotation->quotation_discount_type;
        $this->quotation_discount_value = (int) $quotation->quotation_discount_value;
        $this->blocks = $this->withBlockLayoutDefaults($quotation->blocks ?: []);
        $this->page_settings = $quotation->page_settings ?: [];
        $this->styles = $quotation->styles ?: [];
        $this->table_columns = $quotation->table_columns ?: [];
        $this->items = $quotation->items->sortBy('sort_order')->map(fn ($item) => [
            'menu_item_id' => $item->menu_item_id,
            'description' => $item->description,
            'unit' => $item->unit,
            'quantity' => (string) $item->quantity,
            'unit_price_cents' => (int) $item->unit_price_cents,
            'discount_cents' => (int) $item->discount_cents,
            'catalog_snapshot' => $item->catalog_snapshot,
        ])->values()->all();
        if ($this->isMenuProposal()) $this->ensureMenuPricingItem();
    }

    public function selectCustomer(int $id): void
    {
        $customer = Customer::query()->active()->findOrFail($id);
        $this->customer_id = $customer->id;
        $this->recipient_name = $customer->name;
        $this->recipient_contact_name = (string) $customer->contact_name;
        $this->recipient_email = (string) $customer->email;
        $this->recipient_phone = (string) $customer->phone;
        $this->recipient_address = (string) $customer->billing_address;
        $this->customer_search = '';
        $this->autosave();
    }

    public function clearCustomer(): void
    {
        $this->customer_id = null;
        foreach (['recipient_name', 'recipient_contact_name', 'recipient_email', 'recipient_phone', 'recipient_address'] as $field) $this->{$field} = '';
    }

    public function addCatalogItem(int $id): void
    {
        $menuItem = MenuItem::query()->active()->availableInBranch($this->branch_id)->findOrFail($id);
        $this->items[] = ['menu_item_id' => $menuItem->id, 'description' => $menuItem->name, 'unit' => $menuItem->unit ?: 'each', 'quantity' => '1.000', 'unit_price_cents' => (int) round(((float) $menuItem->selling_price_per_unit) * 100), 'discount_cents' => 0, 'catalog_snapshot' => ['code' => $menuItem->code, 'name' => $menuItem->name, 'arabic_name' => $menuItem->arabic_name]];
        $this->catalog_search = '';
        $this->autosave();
    }

    public function addFreeTextItem(): void
    {
        $this->items[] = ['menu_item_id' => null, 'description' => '', 'unit' => 'each', 'quantity' => '1.000', 'unit_price_cents' => 0, 'discount_cents' => 0, 'catalog_snapshot' => null];
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
        $this->autosave();
    }

    public function addBlock(string $type): void
    {
        abort_unless(in_array($type, ['rich_text', 'terms', 'signature_lines', 'image', 'spacer', 'page_break'], true), 422);
        $content = in_array($type, ['rich_text', 'terms'], true) ? ['type' => 'doc', 'content' => [['type' => 'paragraph']]] : null;
        $this->blocks[] = ['id' => (string) Str::uuid(), 'type' => $type, 'direction' => 'auto', 'settings' => $this->layoutDefaults($type), 'content' => $content];
    }

    public function removeBlock(string $id): void
    {
        $this->blocks = array_values(array_filter($this->blocks, fn (array $block) => $block['id'] !== $id || in_array($block['type'], ['items_table', 'totals', 'menu_pricing'], true)));
        $this->autosave();
    }

    public function reorderBlocks(array $ids): void
    {
        $byId = collect($this->blocks)->keyBy('id');
        $visible = collect($ids)->map(fn ($id) => $byId->get($id))->filter()->values();
        $index = 0;
        $this->blocks = collect($this->blocks)->map(function (array $block) use ($visible, &$index): array {
            if (data_get($block, 'settings.hidden', false) === true) return $block;
            return $visible->get($index++, $block);
        })->values()->all();
        $this->autosave();
    }

    public function reapplyTemplate(int $versionId, QuotationDraftService $drafts): void
    {
        $version = \App\Models\DocumentTemplateVersion::query()->whereHas('template', fn ($q) => $q->where('company_id', $this->quotation->company_id)->where('type', 'quotation')->where('is_active', true))->findOrFail($versionId);
        $this->quotation = $drafts->reapplyTemplate(auth()->user(), $this->quotation, $version)->load('items');
        $this->template_version_id = $this->quotation->template_version_id;
        $this->page_settings = $this->quotation->page_settings ?: [];
        $this->styles = $this->quotation->styles ?: [];
        $this->table_columns = $this->quotation->table_columns ?: [];
        $this->blocks = $this->withBlockLayoutDefaults($this->quotation->blocks ?: []);
        if ($this->isMenuProposal()) {
            $this->ensureMenuPricingItem();
            $this->quotation = $drafts->update(auth()->user(), $this->quotation, $this->payload());
        }
        $this->redirect(route('quotations.edit', $this->quotation), navigate: true);
    }

    public function autosave(): void
    {
        if ($this->isMenuProposal()) $this->ensureMenuPricingItem();
        app(QuotationDraftService::class)->update(auth()->user(), $this->quotation, $this->payload());
    }

    public function saveDraft(QuotationDraftService $drafts): void
    {
        if ($this->isMenuProposal()) $this->ensureMenuPricingItem();
        $drafts->update(auth()->user(), $this->quotation, $this->payload());
        session()->flash('status', __('Draft saved.'));
    }

    public function finalize(QuotationDraftService $drafts, QuotationLifecycleService $lifecycle): void
    {
        if ($this->isMenuProposal()) $this->ensureMenuPricingItem();
        $drafts->update(auth()->user(), $this->quotation, $this->payload());
        $lifecycle->finalize(auth()->user(), $this->quotation->refresh());
        session()->flash('status', __('Quotation finalized and its PDF and DOCX are ready.'));
        $this->redirect(route('quotations.show', $this->quotation), navigate: true);
    }

    public function totals(): array
    {
        return app(QuotationTotalsCalculator::class)->calculate($this->items, $this->quotation_discount_type, $this->quotation_discount_value);
    }

    public function isMenuProposal(): bool
    {
        return data_get($this->styles, 'document_mode') === 'menu_proposal';
    }

    public function changeMenuContentMode(string $mode): void
    {
        abort_unless($this->isMenuProposal() && in_array($mode, ['structured', 'free_form'], true), 422);
        $layout = app(QuotationTemplateService::class)->convertMenuContentMode([
            'page_settings' => $this->page_settings,
            'styles' => $this->styles,
            'table_columns' => $this->table_columns,
            'blocks' => $this->blocks,
        ], $mode);
        $this->page_settings = $layout['page_settings'];
        $this->styles = $layout['styles'];
        $this->table_columns = $layout['table_columns'];
        $this->blocks = $this->withBlockLayoutDefaults($layout['blocks']);
        $this->autosave();
    }

    public function previewHtml(): string
    {
        $calculation = $this->totals();
        $items = collect($calculation['items'])->map(fn (array $item) => [...$item, 'unit_price_minor' => (int) ($item['unit_price_cents'] ?? 0), 'line_discount_minor' => (int) ($item['discount_cents'] ?? 0), 'line_total_minor' => (int) ($item['line_total_cents'] ?? 0)])->all();
        $profile = CompanyDocumentProfile::query()->where('company_id', $this->quotation->company_id)->first();
        $assetIds = collect(app(QuotationAssetService::class)->assetIdsFromBlocks($this->blocks))->push($profile?->logo_asset_id)->filter()->unique();
        return app(QuotationHtmlRenderer::class)->render([
            'schema_version' => 1,
            'quotation' => ['number' => $this->quotation->number, 'issue_date' => $this->issue_date, 'valid_until' => $this->valid_until, 'currency' => 'QAR'],
            'company_profile' => $profile?->toArray() ?? [],
            'recipient' => ['name' => $this->recipient_name, 'contact_name' => $this->recipient_contact_name, 'email' => $this->recipient_email, 'phone' => $this->recipient_phone, 'address' => $this->recipient_address],
            'customer' => $this->customer_id ? Customer::query()->find($this->customer_id)?->toArray() : null,
            'template' => ['page_settings' => $this->page_settings, 'styles' => $this->styles, 'table_columns' => $this->table_columns, 'blocks' => $this->blocks],
            'items' => $items,
            'totals' => [...$calculation, 'line_discount_cents' => $calculation['line_discount_total_cents']],
            'assets' => app(QuotationAssetService::class)->snapshotMap(DocumentAsset::query()->where('company_id', $this->quotation->company_id)->whereIn('id', $assetIds)->get()),
        ]);
    }

    public function with(BranchAccessService $access): array
    {
        return [
            'branches' => Branch::query()->whereIn('id', $access->allowedBranchIds(auth()->user()))->whereKey($this->branch_id)->get(),
            'templates' => DocumentTemplate::query()->with('currentVersion')->where('company_id', $this->quotation->company_id)->where('type', 'quotation')->where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get(),
            'customerResults' => strlen(trim($this->customer_search)) >= 2 ? Customer::query()->active()->search($this->customer_search)->limit(8)->get() : collect(),
            'catalogResults' => strlen(trim($this->catalog_search)) >= 2 ? MenuItem::query()->active()->availableInBranch($this->branch_id)->search($this->catalog_search)->limit(10)->get() : collect(),
            'documentAssets' => DocumentAsset::query()->where('company_id', $this->quotation->company_id)->where('kind', 'image')->latest()->limit(100)->get(),
        ];
    }

    private function payload(): array
    {
        $items = collect($this->items)->values()->map(fn ($item, $i) => [...$item, 'sort_order' => $i])->all();
        return collect(get_object_vars($this))->only(['branch_id', 'customer_id', 'template_version_id', 'recipient_name', 'recipient_contact_name', 'recipient_email', 'recipient_phone', 'recipient_address', 'issue_date', 'valid_until', 'quotation_discount_type', 'quotation_discount_value', 'blocks', 'page_settings', 'styles', 'table_columns'])->merge(['items' => $items])->all();
    }

    private function withBlockLayoutDefaults(array $blocks): array
    {
        return collect($blocks)->map(function (array $block): array {
            $block['settings'] = array_replace($this->layoutDefaults((string) ($block['type'] ?? '')), (array) ($block['settings'] ?? []));
            return $block;
        })->values()->all();
    }

    private function layoutDefaults(string $type): array
    {
        if ($type === 'rich_text' && $this->isMenuProposal()) {
            $last = collect($this->blocks)->reject(fn (array $block) => data_get($block, 'settings.hidden', false) === true)->last();
            $continuesRow = is_array($last)
                && ($last['type'] ?? null) === 'rich_text'
                && (int) data_get($last, 'settings.column_span', 12) === 6
                && data_get($last, 'settings.new_row', true) === true;
            return ['column_span' => 6, 'new_row' => ! $continuesRow, 'horizontal_alignment' => 'center'];
        }
        $span = in_array($type, ['quotation_metadata', 'recipient_details', 'totals'], true) ? 6 : 12;
        return [
            'column_span' => $span,
            'new_row' => $type !== 'recipient_details',
            'horizontal_alignment' => $type === 'totals' ? 'end' : 'stretch',
            ...($type === 'company_header' ? ['show_logo' => true, 'logo_position' => 'start', 'alignment' => 'start'] : []),
        ];
    }

    private function ensureMenuPricingItem(): void
    {
        $current = $this->items[0] ?? [];
        $quantity = (float) ($current['quantity'] ?? 0) > 0 ? (string) $current['quantity'] : '1.000';
        $this->items = [[
            'menu_item_id' => null,
            'description' => 'Menu package',
            'unit' => 'person',
            'quantity' => $quantity,
            'unit_price_cents' => max(0, (int) ($current['unit_price_cents'] ?? $current['unit_price_minor'] ?? 0)),
            'discount_cents' => 0,
            'catalog_snapshot' => null,
        ]];
    }
}; ?>

@include('livewire.quotations.partials.builder', ['pageTitle' => __('Edit quotation'), 'isExisting' => true])
