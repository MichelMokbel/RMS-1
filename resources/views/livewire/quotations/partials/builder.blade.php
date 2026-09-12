@php
    $totals = $this->totals();
    $isMenuProposal = $this->isMenuProposal();
    $menuContentMode = data_get($styles, 'menu_content_mode', 'structured');
    $menuBodyIndex = collect($blocks)->search(fn (array $block) => ($block['type'] ?? null) === 'menu_body');
@endphp
<div class="app-page space-y-5" x-data="{ tab: 'edit' }" x-on:input.debounce.1200ms="$wire.autosave()" x-on:change.debounce.500ms="$wire.autosave()">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-3">
            <flux:button :href="route('quotations.index')" wire:navigate variant="ghost" icon="arrow-left" size="sm" />
            <div><h1 class="text-xl font-semibold text-neutral-900 dark:text-white">{{ $pageTitle }}</h1><p class="text-sm text-neutral-500">{{ $isExisting ? __('Draft changes save automatically.') : __('Choose a customer or enter prospect details.') }}</p></div>
        </div>
        <div class="flex flex-wrap gap-2">
            <flux:button type="button" wire:click="saveDraft" wire:loading.attr="disabled">{{ __('Save draft') }}</flux:button>
            <flux:button type="button" wire:click="finalize" wire:confirm="{{ __('Finalize this quotation? Its PDF and DOCX version will be immutable.') }}" wire:loading.attr="disabled" variant="primary">{{ __('Finalize & generate') }}</flux:button>
        </div>
    </div>

    @if (session('status')) <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div> @endif
    @if ($errors->any()) <div class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800"><p class="font-medium">{{ __('Please correct the quotation before continuing.') }}</p><ul class="mt-1 list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div> @endif

    <div class="flex gap-2 lg:hidden">
        <flux:button type="button" x-on:click="tab='edit'" x-bind:variant="tab === 'edit' ? 'primary' : 'ghost'">{{ __('Editor') }}</flux:button>
        <flux:button type="button" x-on:click="tab='preview'" x-bind:variant="tab === 'preview' ? 'primary' : 'ghost'">{{ __('Preview') }}</flux:button>
    </div>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(380px,0.85fr)]">
        <div class="space-y-5" x-show="tab === 'edit' || window.innerWidth >= 1024">
            <section class="rounded-lg border border-neutral-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <h2 class="mb-4 font-semibold">{{ __('Quotation details') }}</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><label class="mb-1 block text-sm font-medium">{{ __('Branch') }}</label><select wire:model.live="branch_id" @disabled($isExisting) class="w-full rounded-md border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-600 dark:bg-neutral-800">@foreach($branches as $branchOption)<option value="{{ $branchOption->id }}">{{ $branchOption->name }}</option>@endforeach</select></div>
                    <div><label class="mb-1 block text-sm font-medium">{{ __('Template') }}</label><select class="w-full rounded-md border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-600 dark:bg-neutral-800" @if($isExisting) wire:change="reapplyTemplate($event.target.value)" wire:confirm="{{ __('Replace the current layout with this template?') }}" @else wire:model.live="template_version_id" @endif><option value="" @disabled($isExisting)>{{ __('Default layout') }}</option>@foreach($templates as $template)<option value="{{ $template->currentVersion?->id }}" @selected($template_version_id === $template->currentVersion?->id)>{{ $template->name }}</option>@endforeach</select></div>
                    <flux:input wire:model.live="issue_date" type="date" :label="__('Issue date')" />
                    <flux:input wire:model.live="valid_until" type="date" :label="__('Valid until')" />
                </div>
            </section>

            <section class="rounded-lg border border-neutral-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="mb-4 flex items-center justify-between"><h2 class="font-semibold">{{ __('Customer or prospect') }}</h2>@if($customer_id)<flux:button type="button" wire:click="clearCustomer" size="xs" variant="ghost">{{ __('Use prospect instead') }}</flux:button>@endif</div>
                <div class="relative mb-4">
                    <flux:input wire:model.live.debounce.300ms="customer_search" :label="__('Find an existing customer')" placeholder="{{ __('Search by name, code, phone or email') }}" />
                    @if($customerResults->isNotEmpty())<div class="absolute z-20 mt-1 w-full overflow-hidden rounded-md border border-neutral-200 bg-white shadow-lg dark:border-neutral-700 dark:bg-neutral-800">@foreach($customerResults as $customer)<button type="button" wire:click="selectCustomer({{ $customer->id }})" class="block w-full px-3 py-2 text-left text-sm hover:bg-neutral-50 dark:hover:bg-neutral-700"><span class="font-medium">{{ $customer->name }}</span><span class="ml-2 text-neutral-500">{{ $customer->customer_code }} · {{ $customer->phone }}</span></button>@endforeach</div>@endif
                </div>
                <div class="grid gap-4 sm:grid-cols-2"><flux:input wire:model.live="recipient_name" :label="__('Recipient name')" required /><flux:input wire:model.live="recipient_contact_name" :label="__('Contact person')" /><flux:input wire:model.live="recipient_email" type="email" :label="__('Email')" /><flux:input wire:model.live="recipient_phone" :label="__('Phone')" /><div class="sm:col-span-2"><flux:textarea wire:model.live="recipient_address" :label="__('Address')" rows="2" /></div></div>
            </section>

            @if($isMenuProposal)
            <section class="rounded-lg border border-primary-200 bg-primary-50/40 p-5 shadow-sm dark:border-primary-800 dark:bg-primary-950/20">
                <div class="mb-4"><h2 class="font-semibold">{{ __('Menu pricing') }}</h2><p class="mt-1 text-sm text-neutral-500">{{ __('Set the commercial terms shown on this menu proposal.') }}</p></div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div x-data="{ amount: '{{ number_format(((int)data_get($items, '0.unit_price_cents', 0))/100, 2, '.', '') }}' }"><x-number-input x-model="amount" x-on:change="$wire.set('items.0.unit_price_cents', Math.round((parseFloat(amount)||0)*100))" type="number" min="0" :label="__('Price per person (QAR)')" /></div>
                    <x-number-input wire:model.live.debounce.400ms="items.0.quantity" type="number" min="1" :label="__('Minimum order (persons)')" />
                </div>
                <div class="mt-4 flex items-center justify-between border-t border-primary-200 pt-4 text-sm dark:border-primary-800"><span class="text-neutral-500">{{ __('Minimum order value') }}</span><span class="text-lg font-semibold">{{ number_format(((int)($totals['total_cents'] ?? 0))/100, 2) }} QAR</span></div>
            </section>
            @else
            <section class="rounded-lg border border-neutral-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"><div class="relative grow"><flux:input wire:model.live.debounce.300ms="catalog_search" :label="__('Add catalog item')" placeholder="{{ __('Search name or code') }}" />@if($catalogResults->isNotEmpty())<div class="absolute z-20 mt-1 w-full rounded-md border border-neutral-200 bg-white shadow-lg dark:border-neutral-700 dark:bg-neutral-800">@foreach($catalogResults as $catalogItem)<button type="button" wire:click="addCatalogItem({{ $catalogItem->id }})" class="block w-full px-3 py-2 text-left text-sm hover:bg-neutral-50 dark:hover:bg-neutral-700"><span class="font-medium">{{ $catalogItem->name }}</span><span class="float-right">{{ number_format((float)$catalogItem->selling_price_per_unit, 2) }} QAR</span></button>@endforeach</div>@endif</div><flux:button type="button" wire:click="addFreeTextItem" icon="plus">{{ __('Free-text line') }}</flux:button></div>
                <div class="space-y-3">
                    @forelse($items as $index => $item)
                        <div wire:key="quote-item-{{ $index }}" class="grid gap-3 rounded-lg bg-neutral-50 p-3 dark:bg-neutral-800 sm:grid-cols-12">
                            <div class="sm:col-span-4"><flux:input wire:model.live.debounce.500ms="items.{{ $index }}.description" :label="__('Description')" /></div>
                            <div class="sm:col-span-2"><x-number-input wire:model.live.debounce.500ms="items.{{ $index }}.quantity" type="number" min="0.001" :label="__('Quantity')" /></div>
                            <div class="sm:col-span-2"><flux:input wire:model.live.debounce.500ms="items.{{ $index }}.unit" :label="__('Unit')" /></div>
                            <div class="sm:col-span-2" x-data="{ amount: '{{ number_format(((int)($item['unit_price_cents'] ?? 0))/100, 2, '.', '') }}' }"><x-number-input x-model="amount" x-on:change="$wire.set('items.{{ $index }}.unit_price_cents', Math.round((parseFloat(amount)||0)*100))" type="number" min="0" :label="__('Price QAR')" /></div>
                            <div class="sm:col-span-2" x-data="{ amount: '{{ number_format(((int)($item['discount_cents'] ?? 0))/100, 2, '.', '') }}' }"><x-number-input x-model="amount" x-on:change="$wire.set('items.{{ $index }}.discount_cents', Math.round((parseFloat(amount)||0)*100))" type="number" min="0" :label="__('Discount QAR')" /></div>
                            <div class="flex items-center justify-between sm:col-span-12"><span class="text-xs text-neutral-500">{{ $item['catalog_snapshot']['code'] ?? __('Free-text item') }}</span><flux:button type="button" wire:click="removeItem({{ $index }})" size="xs" variant="ghost" icon="trash">{{ __('Remove') }}</flux:button></div>
                        </div>
                    @empty <p class="py-4 text-center text-sm text-neutral-500">{{ __('Add at least one quotation line.') }}</p> @endforelse
                </div>
                <div class="mt-4 grid gap-3 border-t border-neutral-200 pt-4 sm:grid-cols-3 dark:border-neutral-700"><div><label class="mb-1 block text-sm font-medium">{{ __('Document discount') }}</label><select wire:model.live="quotation_discount_type" class="w-full rounded-md border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-600 dark:bg-neutral-800"><option value="">{{ __('None') }}</option><option value="fixed">{{ __('Fixed amount') }}</option><option value="percentage">{{ __('Percentage') }}</option></select></div>@if($quotation_discount_type)<div x-data="{ value: '{{ number_format($quotation_discount_value / 100, 2, '.', '') }}' }"><x-number-input x-model="value" x-on:change="$wire.set('quotation_discount_value', Math.round((parseFloat(value)||0)*100))" type="number" min="0" :label="$quotation_discount_type === 'fixed' ? __('Discount QAR') : __('Discount %')" /></div>@endif<div class="self-end text-right"><p class="text-sm text-neutral-500">{{ __('Quotation total') }}</p><p class="text-xl font-semibold">{{ number_format(((int)($totals['total_cents'] ?? 0))/100, 2) }} QAR</p></div></div>
            </section>
            @endif

            <section class="rounded-lg border border-neutral-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="font-semibold">{{ $isMenuProposal ? __('Menu content') : __('Document blocks') }}</h2>
                        @if($isMenuProposal)<p class="mt-1 text-xs text-neutral-500">{{ $menuContentMode === 'free_form' ? __('Write and format the proposal as one continuous document.') : __('Arrange reusable sections and columns.') }}</p>@endif
                    </div>
                    @if($isMenuProposal)
                        <div class="flex rounded-lg border border-neutral-200 bg-neutral-50 p-1 dark:border-neutral-700 dark:bg-neutral-800">
                            <button type="button" wire:click="changeMenuContentMode('structured')" @if($menuContentMode !== 'structured') wire:confirm="{{ __('Switch to the structured editor? The document will be converted into sections.') }}" @endif class="rounded-md px-3 py-1.5 text-xs font-medium {{ $menuContentMode === 'structured' ? 'bg-white text-neutral-900 shadow-sm dark:bg-neutral-700 dark:text-white' : 'text-neutral-500' }}">{{ __('Structured') }}</button>
                            <button type="button" wire:click="changeMenuContentMode('free_form')" @if($menuContentMode !== 'free_form') wire:confirm="{{ __('Switch to the free-form editor? The current menu sections will be combined into one document.') }}" @endif class="rounded-md px-3 py-1.5 text-xs font-medium {{ $menuContentMode === 'free_form' ? 'bg-white text-neutral-900 shadow-sm dark:bg-neutral-700 dark:text-white' : 'text-neutral-500' }}">{{ __('Free-form') }}</button>
                        </div>
                    @else
                        <div class="flex flex-wrap gap-1">@foreach(['rich_text' => __('Text'), 'terms' => __('Terms'), 'signature_lines' => __('Signatures'), 'image' => __('Image'), 'spacer' => __('Spacer'), 'page_break' => __('Page break')] as $type => $label)<flux:button type="button" wire:click="addBlock('{{ $type }}')" size="xs" variant="ghost">+ {{ $label }}</flux:button>@endforeach</div>
                    @endif
                </div>
                @if($isMenuProposal && $menuContentMode === 'free_form' && $menuBodyIndex !== false)
                    <div wire:key="menu-body-{{ data_get($blocks, "$menuBodyIndex.id") }}" wire:ignore>
                        <div class="mb-2 flex flex-wrap items-center gap-1 rounded-lg border border-neutral-200 bg-neutral-50 p-2 dark:border-neutral-700 dark:bg-neutral-800">
                            @foreach(['bold'=>'B','italic'=>'I','underline'=>'U','heading2'=>'H2','bulletList'=>'• List','orderedList'=>'1. List','alignLeft'=>'←','alignCenter'=>'↔','alignRight'=>'→','link'=>__('Link')] as $action => $label)<button type="button" data-editor-action="{{ $action }}" class="rounded border border-neutral-200 bg-white px-2 py-1 text-xs hover:bg-neutral-100 dark:border-neutral-600 dark:bg-neutral-900 dark:hover:bg-neutral-700">{{ $label }}</button>@endforeach
                            <span class="mx-1 h-5 border-l border-neutral-300 dark:border-neutral-600"></span>
                            <button type="button" data-editor-action="pricing" class="rounded border border-primary-200 bg-primary-50 px-2 py-1 text-xs font-medium text-primary-700 hover:bg-primary-100 dark:border-primary-800 dark:bg-primary-950/30">{{ __('Place pricing here') }}</button>
                            <button type="button" data-editor-action="removePricing" class="rounded border border-neutral-200 bg-white px-2 py-1 text-xs text-neutral-600 hover:bg-neutral-100 dark:border-neutral-600 dark:bg-neutral-900">{{ __('Remove pricing') }}</button>
                            <button type="button" data-editor-action="pageBreak" class="rounded border border-neutral-200 bg-white px-2 py-1 text-xs hover:bg-neutral-100 dark:border-neutral-600 dark:bg-neutral-900">{{ __('Page break') }}</button>
                            <button type="button" data-editor-action="spacer" class="rounded border border-neutral-200 bg-white px-2 py-1 text-xs hover:bg-neutral-100 dark:border-neutral-600 dark:bg-neutral-900">{{ __('Spacer') }}</button>
                            @if($documentAssets->isNotEmpty())
                                <select data-editor-image-picker class="ml-auto max-w-44 rounded border border-neutral-300 bg-white px-2 py-1 text-xs dark:border-neutral-600 dark:bg-neutral-900">
                                    <option value="">{{ __('Choose image') }}</option>
                                    @foreach($documentAssets as $asset)<option value="{{ $asset->id }}">{{ $asset->original_name }}</option>@endforeach
                                </select>
                                <button type="button" data-editor-action="image" class="rounded border border-neutral-200 bg-white px-2 py-1 text-xs hover:bg-neutral-100 dark:border-neutral-600 dark:bg-neutral-900">{{ __('Insert image') }}</button>
                            @endif
                        </div>
                        <div data-quotation-editor data-free-form="true" data-autosave="{{ $isExisting ? 'true' : 'false' }}" data-model="blocks.{{ $menuBodyIndex }}.content" data-direction="{{ data_get($blocks, "$menuBodyIndex.direction", 'auto') }}" data-content="{{ json_encode(data_get($blocks, "$menuBodyIndex.content", ['type' => 'doc', 'content' => [['type' => 'paragraph']]])) }}"></div>
                    </div>
                    <p class="mt-2 text-xs text-neutral-500">{{ __('Pricing is a live field. Move it to the cursor or remove it from the document without changing the saved price and minimum order.') }}</p>
                @else
                @if($isMenuProposal)<div class="mb-4 flex flex-wrap gap-1">@foreach(['rich_text' => __('Menu category'), 'terms' => __('Terms'), 'signature_lines' => __('Signatures'), 'image' => __('Image'), 'spacer' => __('Spacer'), 'page_break' => __('Page break')] as $type => $label)<flux:button type="button" wire:click="addBlock('{{ $type }}')" size="xs" variant="ghost">+ {{ $label }}</flux:button>@endforeach</div>@endif
                <div data-quotation-block-list class="grid grid-cols-12 gap-3">
                    @foreach($blocks as $blockIndex => $block)
                        @continue(data_get($block, 'settings.hidden', false) === true)
                        @php
                            $span = (int) data_get($block, 'settings.column_span', 12);
                            $spanClass = match($span) { 3 => 'lg:col-span-3', 4 => 'lg:col-span-4', 6 => 'lg:col-span-6', 8 => 'lg:col-span-8', 9 => 'lg:col-span-9', default => 'lg:col-span-12' };
                            $spanLabel = [12 => __('Full'), 9 => __('3/4'), 8 => __('2/3'), 6 => __('Half'), 4 => __('1/3'), 3 => __('Quarter')][$span] ?? __('Full');
                            $newRowClass = data_get($block, 'settings.new_row', true) ? 'lg:col-start-1' : '';
                        @endphp
                        <div data-block-id="{{ $block['id'] }}" wire:key="block-{{ $block['id'] }}" class="col-span-12 {{ $spanClass }} {{ $newRowClass }} min-w-0 rounded-lg border border-neutral-200 p-3 dark:border-neutral-700">
                            <div class="mb-3 flex flex-wrap items-center gap-2"><button type="button" data-drag-handle class="cursor-grab text-neutral-400" title="{{ __('Drag to reorder') }}">⋮⋮</button><span class="grow text-sm font-medium">{{ __(str($block['type'])->replace('_', ' ')->title()->toString()) }}</span><span class="rounded-full bg-neutral-100 px-2 py-1 text-[11px] text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">{{ $spanLabel }} · {{ data_get($block, 'settings.horizontal_alignment', 'stretch') }}@if(data_get($block, 'settings.new_row', true)) · {{ __('new row') }}@endif</span>@unless(in_array($block['type'], ['items_table','totals','menu_pricing']))<flux:button type="button" wire:click="removeBlock('{{ $block['id'] }}')" size="xs" variant="ghost" icon="x-mark" />@endunless</div>
                            @include('livewire.quotations.partials.block-layout-controls', ['modelPrefix' => "blocks.$blockIndex", 'block' => $block])
                            @if(in_array($block['type'], ['rich_text','terms']))
                                <div wire:ignore><div class="mb-1 flex flex-wrap gap-1">@foreach(['bold'=>'B','italic'=>'I','underline'=>'U','heading2'=>'H2','bulletList'=>'• List','orderedList'=>'1. List','alignLeft'=>'←','alignCenter'=>'↔','alignRight'=>'→','link'=>'Link'] as $action => $label)<button type="button" data-editor-action="{{ $action }}" class="rounded border border-neutral-200 px-2 py-1 text-xs hover:bg-neutral-100 dark:border-neutral-600 dark:hover:bg-neutral-700">{{ $label }}</button>@endforeach</div><div data-quotation-editor data-autosave="{{ $isExisting ? 'true' : 'false' }}" data-model="blocks.{{ $blockIndex }}.content" data-direction="{{ $block['direction'] }}" data-content="{{ json_encode($block['content'] ?? []) }}"></div></div>
                            @elseif($block['type'] === 'image') <div><label class="mb-1 block text-sm font-medium">{{ __('Registered image') }}</label><select wire:model.live="blocks.{{ $blockIndex }}.settings.asset_id" class="w-full rounded-md border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-600 dark:bg-neutral-800"><option value="">{{ __('Select an image') }}</option>@foreach($documentAssets as $asset)<option value="{{ $asset->id }}">{{ $asset->original_name }}</option>@endforeach</select><p class="mt-1 text-xs text-neutral-500">{{ __('Upload reusable images from Company branding.') }}</p></div>
                            @elseif($block['type'] === 'spacer') <x-number-input wire:model.live="blocks.{{ $blockIndex }}.settings.height_mm" type="number" min="2" max="100" :label="__('Height (mm)')" />
                            @elseif($block['type'] === 'signature_lines') <div class="grid gap-3 sm:grid-cols-2"><flux:input wire:model.live="blocks.{{ $blockIndex }}.settings.labels.0" :label="__('Left label')" /><flux:input wire:model.live="blocks.{{ $blockIndex }}.settings.labels.1" :label="__('Right label')" /></div>
                            @elseif($block['type'] === 'menu_pricing') <p class="text-xs text-neutral-500">{{ __('This controlled block displays the price per person and minimum order entered in Menu pricing.') }}</p>
                            @else <p class="text-xs text-neutral-500">{{ __('This controlled block is populated from quotation data.') }}</p> @endif
                        </div>
                    @endforeach
                </div>
                @endif
            </section>
        </div>

        <aside class="lg:sticky lg:top-4 lg:self-start" x-show="tab === 'preview' || window.innerWidth >= 1024">
            <div class="mb-2 flex items-center justify-between"><h2 class="font-semibold">{{ __('Live preview') }}</h2><span wire:loading class="text-xs text-neutral-500">{{ __('Updating…') }}</span></div>
            <iframe title="{{ __('Quotation preview') }}" sandbox="" class="h-[78vh] w-full rounded-lg border border-neutral-300 bg-white shadow-sm" srcdoc="{{ $this->previewHtml() }}"></iframe>
            <p class="mt-2 text-xs text-neutral-500">{{ __('The final PDF and editable DOCX are generated from the same validated document snapshot.') }}</p>
        </aside>
    </div>
</div>
