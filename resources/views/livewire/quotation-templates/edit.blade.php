<?php

use App\Models\Branch;
use App\Models\DocumentTemplate;
use App\Models\DocumentAsset;
use App\Services\Quotations\QuotationTemplateService;
use App\Services\Security\BranchAccessService;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public DocumentTemplate $template;
    public string $name = '';
    public array $blocks = [];
    public array $page_settings = [];
    public array $styles = [];
    public array $table_columns = [];

    public function mount(DocumentTemplate $template, BranchAccessService $access): void
    {
        abort_unless(auth()->user()?->can('quotation-templates.manage'), 403);
        $companies = Branch::query()->whereIn('id', $access->allowedBranchIds(auth()->user()))->pluck('company_id');
        abort_unless($companies->contains((int) $template->company_id) && $template->type === 'quotation', 403);
        $this->template = $template->load('currentVersion');
        $this->name = $template->name;
        $this->blocks = $this->withBlockLayoutDefaults($template->currentVersion->blocks);
        $this->page_settings = $template->currentVersion->page_settings;
        $this->styles = $template->currentVersion->styles;
        $this->table_columns = $template->currentVersion->table_columns;
    }

    public function addBlock(string $type): void
    {
        abort_unless(in_array($type, ['rich_text', 'terms', 'signature_lines', 'image', 'spacer', 'page_break'], true), 422);
        $this->blocks[] = [
            'id' => (string) Str::uuid(), 'type' => $type, 'direction' => 'auto', 'settings' => $this->layoutDefaults($type),
            'content' => in_array($type, ['rich_text', 'terms'], true) ? ['type' => 'doc', 'content' => [['type' => 'paragraph']]] : null,
        ];
    }

    public function removeBlock(string $id): void
    {
        $this->blocks = array_values(array_filter($this->blocks, fn (array $block) => $block['id'] !== $id || in_array($block['type'], ['items_table', 'totals', 'menu_pricing'], true)));
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
    }

    public function save(QuotationTemplateService $templates): void
    {
        $this->validate(['name' => ['required', 'string', 'max:255']]);
        $this->template->update(['name' => trim($this->name), 'updated_by' => auth()->id()]);
        $version = $templates->createVersion($this->template, [
            'page_settings' => $this->page_settings,
            'styles' => $this->styles,
            'table_columns' => $this->table_columns,
            'blocks' => $this->blocks,
        ], auth()->user());
        $this->template = $this->template->fresh('currentVersion');
        session()->flash('status', __('Template version :version saved.', ['version' => $version->version]));
    }

    public function with(): array
    {
        return ['documentAssets' => DocumentAsset::query()->where('company_id', $this->template->company_id)->where('kind', 'image')->latest()->limit(100)->get()];
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
}; ?>

<div class="app-page space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div class="flex items-center gap-3"><flux:button :href="route('quotation-templates.index')" wire:navigate variant="ghost" icon="arrow-left" size="sm" /><div><h1 class="text-xl font-semibold">{{ __('Edit template') }}</h1><p class="text-sm text-neutral-500">{{ __('Saving creates an immutable version; existing quotations never change.') }}</p></div></div><flux:button wire:click="save" variant="primary">{{ __('Save new version') }}</flux:button></div>
    @if(session('status'))<div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800"><ul class="list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

    <div class="grid gap-6 lg:grid-cols-[300px_minmax(0,1fr)]">
        <aside class="space-y-5">
            <section class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"><flux:input wire:model="name" :label="__('Template name')" /><p class="mt-2 text-xs text-neutral-500">{{ __('Current version: :version', ['version' => $template->currentVersion->version]) }}</p></section>
            <section class="space-y-3 rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"><h2 class="font-semibold">{{ __('Page and type') }}</h2><div class="grid grid-cols-2 gap-2">@foreach(['top','right','bottom','left'] as $side)<x-number-input wire:model="page_settings.margins_mm.{{ $side }}" type="number" min="0" max="50" :label="__(ucfirst($side).' margin')" />@endforeach</div><div><label class="mb-1 block text-sm font-medium">{{ __('Font family') }}</label><select wire:model="styles.font_family" class="w-full rounded-md border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-600 dark:bg-neutral-800">@foreach(['DejaVu Sans','Arial','Helvetica','Times New Roman'] as $font)<option value="{{ $font }}">{{ $font }}</option>@endforeach</select></div><x-number-input wire:model="styles.font_size" type="number" min="8" max="14" :label="__('Base font size')" /><flux:input wire:model="styles.accent_color" type="color" :label="__('Accent color')" /><div><label class="mb-1 block text-sm font-medium">{{ __('Default direction') }}</label><select wire:model="styles.default_direction" class="w-full rounded-md border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-600 dark:bg-neutral-800"><option value="auto">{{ __('Automatic') }}</option><option value="ltr">LTR</option><option value="rtl">RTL</option></select></div></section>
            <section class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"><h2 class="mb-3 font-semibold">{{ __('Items table columns') }}</h2><div class="space-y-2">@foreach($table_columns as $column => $visible)<flux:checkbox wire:model="table_columns.{{ $column }}" :label="__(str($column)->replace('_',' ')->title()->toString())" />@endforeach</div></section>
        </aside>

        @php
            $menuContentMode = data_get($styles, 'menu_content_mode', 'structured');
            $menuBodyIndex = collect($blocks)->search(fn (array $block) => ($block['type'] ?? null) === 'menu_body');
        @endphp
        <main class="rounded-lg border border-neutral-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div><h2 class="font-semibold">{{ $this->isMenuProposal() ? __('Menu content') : __('Layout blocks') }}</h2><p class="text-xs text-neutral-500">{{ $this->isMenuProposal() && $menuContentMode === 'free_form' ? __('Edit the proposal as one continuous document.') : __('Drag blocks to set their order.') }}</p></div>
                @if($this->isMenuProposal())
                    <div class="flex rounded-lg border border-neutral-200 bg-neutral-50 p-1 dark:border-neutral-700 dark:bg-neutral-800">
                        <button type="button" wire:click="changeMenuContentMode('structured')" @if($menuContentMode !== 'structured') wire:confirm="{{ __('Switch to the structured editor? The document will be converted into sections.') }}" @endif class="rounded-md px-3 py-1.5 text-xs font-medium {{ $menuContentMode === 'structured' ? 'bg-white text-neutral-900 shadow-sm dark:bg-neutral-700 dark:text-white' : 'text-neutral-500' }}">{{ __('Structured') }}</button>
                        <button type="button" wire:click="changeMenuContentMode('free_form')" @if($menuContentMode !== 'free_form') wire:confirm="{{ __('Switch to the free-form editor? The current menu sections will be combined into one document.') }}" @endif class="rounded-md px-3 py-1.5 text-xs font-medium {{ $menuContentMode === 'free_form' ? 'bg-white text-neutral-900 shadow-sm dark:bg-neutral-700 dark:text-white' : 'text-neutral-500' }}">{{ __('Free-form') }}</button>
                    </div>
                @else
                    <div class="flex flex-wrap gap-1">@foreach(['rich_text'=>__('Text'),'terms'=>__('Terms'),'signature_lines'=>__('Signatures'),'image'=>__('Image'),'spacer'=>__('Spacer'),'page_break'=>__('Page break')] as $type=>$label)<flux:button wire:click="addBlock('{{ $type }}')" size="xs" variant="ghost">+ {{ $label }}</flux:button>@endforeach</div>
                @endif
            </div>
            @if($this->isMenuProposal() && $menuContentMode === 'free_form' && $menuBodyIndex !== false)
                <div wire:key="template-menu-body-{{ data_get($blocks, "$menuBodyIndex.id") }}" wire:ignore>
                    <div class="mb-2 flex flex-wrap items-center gap-1 rounded-lg border border-neutral-200 bg-neutral-50 p-2 dark:border-neutral-700 dark:bg-neutral-800">
                        @foreach(['bold'=>'B','italic'=>'I','underline'=>'U','heading2'=>'H2','bulletList'=>'• List','orderedList'=>'1. List','alignLeft'=>'←','alignCenter'=>'↔','alignRight'=>'→','link'=>__('Link')] as $action=>$label)<button type="button" data-editor-action="{{ $action }}" class="rounded border border-neutral-200 bg-white px-2 py-1 text-xs hover:bg-neutral-100 dark:border-neutral-600 dark:bg-neutral-900">{{ $label }}</button>@endforeach
                        <span class="mx-1 h-5 border-l border-neutral-300 dark:border-neutral-600"></span>
                        <button type="button" data-editor-action="pricing" class="rounded border border-primary-200 bg-primary-50 px-2 py-1 text-xs font-medium text-primary-700">{{ __('Place pricing here') }}</button>
                        <button type="button" data-editor-action="removePricing" class="rounded border border-neutral-200 bg-white px-2 py-1 text-xs text-neutral-600 dark:border-neutral-600 dark:bg-neutral-900">{{ __('Remove pricing') }}</button>
                        <button type="button" data-editor-action="pageBreak" class="rounded border border-neutral-200 bg-white px-2 py-1 text-xs dark:border-neutral-600 dark:bg-neutral-900">{{ __('Page break') }}</button>
                        <button type="button" data-editor-action="spacer" class="rounded border border-neutral-200 bg-white px-2 py-1 text-xs dark:border-neutral-600 dark:bg-neutral-900">{{ __('Spacer') }}</button>
                        @if($documentAssets->isNotEmpty())
                            <select data-editor-image-picker class="ml-auto max-w-44 rounded border border-neutral-300 bg-white px-2 py-1 text-xs dark:border-neutral-600 dark:bg-neutral-900"><option value="">{{ __('Choose image') }}</option>@foreach($documentAssets as $asset)<option value="{{ $asset->id }}">{{ $asset->original_name }}</option>@endforeach</select>
                            <button type="button" data-editor-action="image" class="rounded border border-neutral-200 bg-white px-2 py-1 text-xs dark:border-neutral-600 dark:bg-neutral-900">{{ __('Insert image') }}</button>
                        @endif
                    </div>
                    <div data-quotation-editor data-free-form="true" data-autosave="false" data-model="blocks.{{ $menuBodyIndex }}.content" data-direction="{{ data_get($blocks, "$menuBodyIndex.direction", 'auto') }}" data-content="{{ json_encode(data_get($blocks, "$menuBodyIndex.content", ['type' => 'doc', 'content' => [['type' => 'paragraph']]])) }}"></div>
                </div>
                <p class="mt-2 text-xs text-neutral-500">{{ __('Pricing stays connected to each quotation. Place its field anywhere in the proposal or leave it out.') }}</p>
            @else
            @if($this->isMenuProposal())<div class="mb-4 flex flex-wrap gap-1">@foreach(['rich_text'=>__('Menu category'),'terms'=>__('Terms'),'signature_lines'=>__('Signatures'),'image'=>__('Image'),'spacer'=>__('Spacer'),'page_break'=>__('Page break')] as $type=>$label)<flux:button wire:click="addBlock('{{ $type }}')" size="xs" variant="ghost">+ {{ $label }}</flux:button>@endforeach</div>@endif
            <div data-quotation-block-list class="grid grid-cols-12 gap-3">
                @foreach($blocks as $index => $block)
                    @continue(data_get($block, 'settings.hidden', false) === true)
                    @php
                        $span = (int) data_get($block, 'settings.column_span', 12);
                        $spanClass = match($span) { 3 => 'lg:col-span-3', 4 => 'lg:col-span-4', 6 => 'lg:col-span-6', 8 => 'lg:col-span-8', 9 => 'lg:col-span-9', default => 'lg:col-span-12' };
                        $spanLabel = [12 => __('Full'), 9 => __('3/4'), 8 => __('2/3'), 6 => __('Half'), 4 => __('1/3'), 3 => __('Quarter')][$span] ?? __('Full');
                        $newRowClass = data_get($block, 'settings.new_row', true) ? 'lg:col-start-1' : '';
                    @endphp
                    <div data-block-id="{{ $block['id'] }}" wire:key="template-block-{{ $block['id'] }}" class="col-span-12 {{ $spanClass }} {{ $newRowClass }} min-w-0 rounded-lg border border-neutral-200 p-3 dark:border-neutral-700"><div class="mb-3 flex flex-wrap items-center gap-2"><button type="button" data-drag-handle class="cursor-grab text-neutral-400">⋮⋮</button><span class="grow text-sm font-medium">{{ __(str($block['type'])->replace('_',' ')->title()->toString()) }}</span><span class="rounded-full bg-neutral-100 px-2 py-1 text-[11px] text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">{{ $spanLabel }} · {{ data_get($block, 'settings.horizontal_alignment', 'stretch') }}@if(data_get($block, 'settings.new_row', true)) · {{ __('new row') }}@endif</span>@unless(in_array($block['type'], ['items_table','totals','menu_pricing']))<flux:button wire:click="removeBlock('{{ $block['id'] }}')" size="xs" variant="ghost" icon="x-mark" />@endunless</div>
                        @include('livewire.quotations.partials.block-layout-controls', ['modelPrefix' => "blocks.$index", 'block' => $block])
                        @if(in_array($block['type'], ['rich_text','terms']))<div wire:ignore><div class="mb-1 flex gap-1">@foreach(['bold'=>'B','italic'=>'I','underline'=>'U','heading2'=>'H2','bulletList'=>'• List','orderedList'=>'1. List','link'=>'Link'] as $action=>$label)<button type="button" data-editor-action="{{ $action }}" class="rounded border border-neutral-200 px-2 py-1 text-xs dark:border-neutral-600">{{ $label }}</button>@endforeach</div><div data-quotation-editor data-autosave="false" data-model="blocks.{{ $index }}.content" data-direction="{{ $block['direction'] }}" data-content="{{ json_encode($block['content'] ?? []) }}"></div></div>
                        @elseif($block['type']==='image')<div><label class="mb-1 block text-sm font-medium">{{ __('Registered image') }}</label><select wire:model="blocks.{{ $index }}.settings.asset_id" class="w-full rounded-md border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-600 dark:bg-neutral-800"><option value="">{{ __('Select an image') }}</option>@foreach($documentAssets as $asset)<option value="{{ $asset->id }}">{{ $asset->original_name }}</option>@endforeach</select></div>
                        @elseif($block['type']==='spacer')<x-number-input wire:model="blocks.{{ $index }}.settings.height_mm" type="number" min="2" max="100" :label="__('Height (mm)')" />
                        @elseif($block['type']==='signature_lines')<flux:input wire:model="blocks.{{ $index }}.settings.labels.0" :label="__('First signature label')" /><flux:input wire:model="blocks.{{ $index }}.settings.labels.1" :label="__('Second signature label')" />
                        @elseif($block['type']==='menu_pricing')<p class="text-xs text-neutral-500">{{ __('This controlled block displays price per person and minimum order values from each menu quotation.') }}</p>
                        @else<p class="text-xs text-neutral-500">{{ __('Content is populated automatically when rendering a quotation.') }}</p>@endif
                    </div>
                @endforeach
            </div>
            @endif
        </main>
    </div>
</div>
