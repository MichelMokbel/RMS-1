<?php

use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Support\Facades\Schema;
use App\Services\Purchasing\PurchaseOrderFormQueryService;
use App\Services\Purchasing\PurchaseOrderPersistService;
use App\Support\Purchasing\PurchaseOrderRules;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public PurchaseOrder $purchaseOrder;
    #[Locked]
    public string $po_number = '';
    public ?int $supplier_id = null;
    public string $supplier_search = '';
    public ?string $order_date = null;
    public ?string $expected_delivery_date = null;
    public ?string $notes = null;
    public ?string $payment_terms = null;
    public ?string $payment_type = null;
    public array $payment_term_options = ['Due on receipt', 'Credit', 'Net 15', 'Net 30', 'Net 60'];
    public array $payment_type_options = ['Cash', 'Card', 'Bank Transfer', 'Cheque', 'Credit'];
    public ?string $new_payment_term = null;
    public ?string $new_payment_type = null;
    public array $lines = [];
    public bool $readOnly = false;
    public array $items = [];
    public array $lineSearch = [];

    public function mount(PurchaseOrder $purchaseOrder, PurchaseOrderFormQueryService $queryService): void
    {
        $this->items = $queryService->inventoryItemsArray();
        $this->purchaseOrder = $purchaseOrder->load('items');
        $this->readOnly = ! $this->purchaseOrder->canEditLines();

        if ($this->readOnly) {
            session()->flash('status', __('Purchase order is not editable in the current status.'));
            $this->redirectRoute('purchase-orders.show', $this->purchaseOrder, navigate: true);
            return;
        }

        $this->po_number = $purchaseOrder->po_number;
        $this->supplier_id = $purchaseOrder->supplier_id;
        $this->order_date = optional($purchaseOrder->order_date)?->format('Y-m-d');
        $this->expected_delivery_date = optional($purchaseOrder->expected_delivery_date)?->format('Y-m-d');
        $this->notes = $purchaseOrder->notes;
        $this->payment_terms = $purchaseOrder->payment_terms;
        $this->payment_type = $purchaseOrder->payment_type;
        $this->lines = $purchaseOrder->items->map(function ($item) {
            return [
                'id' => $item->id,
                'item_id' => $item->item_id,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
            ];
        })->toArray();

        if (empty($this->lines)) {
            $this->lines = [['item_id' => null, 'quantity' => 1.0, 'unit_price' => 0]];
        }

        $this->lineSearch = collect($this->lines)
            ->map(fn ($line) => $this->resolveItemLabel((int) ($line['item_id'] ?? 0)))
            ->toArray();
        $this->supplier_search = $this->resolveSupplierLabel((int) ($this->supplier_id ?? 0));
    }

    public function addLine(): void
    {
        $this->lines[] = ['item_id' => null, 'quantity' => 1.0, 'unit_price' => 0];
        $this->lineSearch[] = '';
    }

    public function selectSupplier(int $supplierId, string $label = ''): void
    {
        $this->supplier_id = $supplierId;
        $this->supplier_search = trim($label) !== '' ? $label : $this->resolveSupplierLabel($supplierId);
    }

    public function clearSupplier(): void
    {
        $this->supplier_id = null;
        $this->supplier_search = '';
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        unset($this->lineSearch[$index]);
        $this->lines = array_values($this->lines);
        $this->lineSearch = array_values($this->lineSearch);
    }

    public function updated($name, $value): void
    {
        $this->maybeSyncUnitPrice($name, $value);
    }

    public function updatedLines($value, $name): void
    {
        $this->maybeSyncUnitPrice("lines.$name", $value);
    }

    private function maybeSyncUnitPrice(string $name, $value): void
    {
        if (str_contains($name, '.item_id')) {
            $parts = explode('.', $name);
            $idx = $parts[1] ?? null;
            if ($idx !== null) {
                $this->syncUnitPriceFromItem((int) $value, (int) $idx);
            }
        }
    }

    private function syncUnitPriceFromItem(int $itemId, int $index): void
    {
        $item = collect($this->items)->firstWhere('id', $itemId);
        $cost = $item['cost_per_unit'] ?? 0;
        if (isset($this->lines[$index])) {
            $this->lines[$index]['unit_price'] = round((float) $cost, 2);
        }
    }

    public function syncPrice(int $index): void
    {
        $itemId = $this->lines[$index]['item_id'] ?? null;
        if ($itemId) {
            $this->syncUnitPriceFromItem((int) $itemId, $index);
            $this->lineSearch[$index] = $this->resolveItemLabel((int) $itemId);
            $this->maybeAppendNewLine($index);
        }
    }

    public function selectLineItem(int $index, int $itemId, string $label = ''): void
    {
        if (! isset($this->lines[$index])) {
            return;
        }

        $this->lines[$index]['item_id'] = $itemId;
        $this->lineSearch[$index] = trim($label) !== '' ? $label : $this->resolveItemLabel($itemId);
        $this->syncUnitPriceFromItem($itemId, $index);
        $this->maybeAppendNewLine($index);
    }

    public function clearLineItem(int $index): void
    {
        if (! isset($this->lines[$index])) {
            return;
        }

        $this->lines[$index]['item_id'] = null;
        $this->lineSearch[$index] = '';
    }

    private function maybeAppendNewLine(int $index): void
    {
        if ($index === array_key_last($this->lines)) {
            $this->lines[] = ['item_id' => null, 'quantity' => 1.0, 'unit_price' => 0];
            $this->lineSearch[] = '';
        }
    }

    private function resolveItemLabel(int $itemId): string
    {
        $item = collect($this->items)->firstWhere('id', $itemId);
        if (! is_array($item)) {
            return '';
        }

        return trim('['.($item['item_code'] ?? '').'] '.($item['name'] ?? ''));
    }

    private function resolveSupplierLabel(int $supplierId): string
    {
        $supplier = Supplier::query()->select(['id', 'name', 'email'])->find($supplierId);
        if (! $supplier) {
            return '';
        }

        $label = trim((string) $supplier->name);
        if (! empty($supplier->email)) {
            $label .= ' ('.$supplier->email.')';
        }

        return $label;
    }

    public function saveDraft(PurchaseOrderPersistService $persist, PurchaseOrderRules $rules): void
    {
        $this->persist(PurchaseOrder::STATUS_DRAFT, $persist, $rules);
    }

    public function submitPending(PurchaseOrderPersistService $persist, PurchaseOrderRules $rules): void
    {
        $this->persist(PurchaseOrder::STATUS_PENDING, $persist, $rules);
    }

    public function addPaymentTerm(): void
    {
        $value = trim((string) $this->new_payment_term);
        if ($value !== '' && ! in_array($value, $this->payment_term_options, true)) {
            $this->payment_term_options[] = $value;
            $this->payment_terms = $value;
        }
        $this->new_payment_term = null;
    }

    public function addPaymentType(): void
    {
        $value = trim((string) $this->new_payment_type);
        if ($value !== '' && ! in_array($value, $this->payment_type_options, true)) {
            $this->payment_type_options[] = $value;
            $this->payment_type = $value;
        }
        $this->new_payment_type = null;
    }

    private function persist(string $status, PurchaseOrderPersistService $persist, PurchaseOrderRules $rules): void
    {
        if ($this->readOnly) {
            return;
        }

        $filtered = $this->filteredLines();
        if (count($filtered) === 0) {
            $this->addError('lines', __('Add at least one line with item, quantity, and price.'));
            return;
        }
        $this->lines = $filtered;

        $data = $this->validate($rules->updateRules($this->purchaseOrder->id));

        if ($status === PurchaseOrder::STATUS_PENDING && ! $data['supplier_id']) {
            $this->addError('supplier_id', __('Supplier is required to submit.'));
            return;
        }

        try {
            $this->purchaseOrder = $persist->update($this->purchaseOrder, $data, $status);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                foreach ($messages as $m) {
                    $this->addError($key, $m);
                }
            }
            return;
        }

        session()->flash('status', __('Purchase order updated.'));
        $this->redirectRoute('purchase-orders.show', $this->purchaseOrder, navigate: true);
    }

    private function filteredLines(): array
    {
        return collect($this->lines)
            ->filter(function ($line) {
                return ! empty($line['item_id'])
                    && isset($line['quantity']) && (float) $line['quantity'] > 0.0005
                    && isset($line['unit_price']) && $line['unit_price'] !== '';
            })
            ->values()
            ->toArray();
    }

}; ?>

<div class="w-full max-w-5xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
            {{ __('Edit Purchase Order') }}
        </h1>
        <flux:button :href="route('purchase-orders.show', $purchaseOrder)" wire:navigate variant="ghost">
            {{ __('Back') }}
        </flux:button>
    </div>

    <form wire:submit="saveDraft" class="space-y-6">
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <flux:input wire:model="po_number" :label="__('PO Number')" required maxlength="50" readonly />
                <div>
                    <label class="block text-sm font-medium text-neutral-800 dark:text-neutral-200 mb-1">{{ __('Supplier') }}</label>
                    <div
                        class="relative"
                        wire:ignore
                        x-data="poSupplierLookup({
                            initial: @js($supplier_search),
                            selectedId: @js($supplier_id),
                            searchUrl: '{{ route('purchase-orders.suppliers.search') }}'
                        })"
                        x-on:keydown.escape.stop="close()"
                        x-on:click.outside="close()"
                    >
                        <input
                            type="text"
                            class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50"
                            x-model="query"
                            x-on:input.debounce.200ms="onInput()"
                            x-on:focus="onInput(true)"
                            placeholder="{{ __('Search supplier by name, email, phone') }}"
                        />
                        <template x-if="open">
                            <div
                                x-ref="panel"
                                x-bind:style="panelStyle"
                                class="mb-1 overflow-hidden rounded-md border border-neutral-200 bg-white shadow-lg dark:border-neutral-700 dark:bg-neutral-900"
                            >
                                <div class="max-h-60 overflow-auto">
                                    <template x-for="supplier in results" :key="supplier.id">
                                        <button
                                            type="button"
                                            class="w-full px-3 py-2 text-left text-sm text-neutral-800 hover:bg-neutral-50 dark:text-neutral-100 dark:hover:bg-neutral-800/80"
                                            x-on:click="choose(supplier)"
                                        >
                                            <div class="font-medium" x-text="supplier.name"></div>
                                            <div class="text-xs text-neutral-500 dark:text-neutral-400" x-text="supplier.email || supplier.phone || ''"></div>
                                        </button>
                                    </template>
                                    <div x-show="loading" class="px-3 py-2 text-sm text-neutral-500 dark:text-neutral-400">
                                        {{ __('Searching...') }}
                                    </div>
                                    <div x-show="!loading && hasSearched && results.length === 0" class="px-3 py-2 text-sm text-neutral-500 dark:text-neutral-400">
                                        {{ __('No suppliers found.') }}
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                    @error('supplier_id') <p class="text-sm text-rose-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <flux:input wire:model="order_date" type="date" :label="__('Order Date')" />
                <flux:input wire:model="expected_delivery_date" type="date" :label="__('Expected Delivery')" />
            </div>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div class="space-y-2">
                    <label class="block text-sm font-medium text-neutral-800 dark:text-neutral-200 mb-1">{{ __('Payment Terms') }}</label>
                    <select wire:model="payment_terms" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="">{{ __('Select terms') }}</option>
                        @foreach ($payment_term_options as $term)
                            <option value="{{ $term }}">{{ $term }}</option>
                        @endforeach
                    </select>
                    <div class="flex items-center gap-2">
                        <flux:input wire:model="new_payment_term" placeholder="{{ __('Add new term') }}" class="flex-1" />
                        <flux:button type="button" wire:click="addPaymentTerm" size="sm">{{ __('Add') }}</flux:button>
                    </div>
                </div>
                <div class="space-y-2">
                    <label class="block text-sm font-medium text-neutral-800 dark:text-neutral-200 mb-1">{{ __('Payment Type') }}</label>
                    <select wire:model="payment_type" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="">{{ __('Select type') }}</option>
                        @foreach ($payment_type_options as $type)
                            <option value="{{ $type }}">{{ $type }}</option>
                        @endforeach
                    </select>
                    <div class="flex items-center gap-2">
                        <flux:input wire:model="new_payment_type" placeholder="{{ __('Add new type') }}" class="flex-1" />
                        <flux:button type="button" wire:click="addPaymentType" size="sm">{{ __('Add') }}</flux:button>
                    </div>
                </div>
            </div>
            <flux:textarea wire:model="notes" :label="__('Notes')" rows="2" />
        </div>

        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Line Items') }}</h2>
                <flux:button type="button" wire:click="addLine">{{ __('Add line') }}</flux:button>
            </div>

            <div class="space-y-3">
                @foreach ($lines as $index => $line)
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-12 items-end rounded-lg border border-neutral-200 p-3 dark:border-neutral-700" wire:key="po-edit-line-{{ $index }}">
                        <div class="md:col-span-5">
                            <div class="flex items-center justify-between">
                                <label class="block text-sm font-medium text-neutral-800 dark:text-neutral-200 mb-1">{{ __('Item') }}</label>
                                <span class="text-xs text-neutral-500 dark:text-neutral-400">{{ __('S.I No') }}: {{ $loop->iteration }}</span>
                            </div>
                            <div
                                class="relative"
                                wire:ignore
                                x-data="poItemLookup({
                                    index: {{ $index }},
                                    initial: @js($lineSearch[$index] ?? ''),
                                    selectedId: @js($line['item_id'] ?? null),
                                    searchUrl: '{{ route('purchase-orders.items.search') }}'
                                })"
                                x-on:keydown.escape.stop="close()"
                                x-on:click.outside="close()"
                            >
                                <input
                                    type="text"
                                    class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50"
                                    x-model="query"
                                    x-on:input.debounce.200ms="onInput()"
                                    x-on:focus="onInput(true)"
                                    placeholder="{{ __('Search item by code or name') }}"
                                />
                                <template x-if="open">
                                    <div
                                        x-ref="panel"
                                        x-bind:style="panelStyle"
                                        class="mb-1 overflow-hidden rounded-md border border-neutral-200 bg-white shadow-lg dark:border-neutral-700 dark:bg-neutral-900"
                                    >
                                        <div class="max-h-60 overflow-auto">
                                            <template x-for="item in results" :key="item.id">
                                                <button
                                                    type="button"
                                                    class="w-full px-3 py-2 text-left text-sm text-neutral-800 hover:bg-neutral-50 dark:text-neutral-100 dark:hover:bg-neutral-800/80"
                                                    x-on:click="choose(item)"
                                                >
                                                    <div class="flex items-center justify-between gap-2">
                                                        <span class="font-medium" x-text="item.name"></span>
                                                        <span class="text-xs text-neutral-500 dark:text-neutral-400" x-show="item.code" x-text="item.code"></span>
                                                    </div>
                                                </button>
                                            </template>
                                            <div x-show="loading" class="px-3 py-2 text-sm text-neutral-500 dark:text-neutral-400">
                                                {{ __('Searching...') }}
                                            </div>
                                            <div x-show="!loading && hasSearched && results.length === 0" class="px-3 py-2 text-sm text-neutral-500 dark:text-neutral-400">
                                                {{ __('No items found.') }}
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                            @error("lines.$index.item_id") <p class="text-sm text-rose-600 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div class="md:col-span-2">
                            <flux:input wire:model.live="lines.{{ $index }}.quantity" type="number" min="0.001" step="0.001" :label="__('Qty (packages)')" />
                            @error("lines.$index.quantity") <p class="text-sm text-rose-600 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div class="md:col-span-2">
                            <flux:input wire:model.live="lines.{{ $index }}.unit_price" type="number" step="0.01" min="0" :label="__('Unit Price (pkg)')" />
                            @error("lines.$index.unit_price") <p class="text-sm text-rose-600 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-neutral-800 dark:text-neutral-200 mb-1">{{ __('Total') }}</label>
                            <div class="rounded-md border border-neutral-200 bg-neutral-50 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                {{ number_format((float) ($line['quantity'] ?? 0) * (float) ($line['unit_price'] ?? 0), 2) }}
                            </div>
                        </div>
                        <div class="md:col-span-1 flex justify-end">
                            <flux:button type="button" wire:click="removeLine({{ $index }})" variant="ghost">
                                {{ __('Remove') }}
                            </flux:button>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="flex items-center justify-end gap-3">
                <div class="text-right">
                    <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Total Amount') }}</p>
                    <p class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                        {{ number_format(collect($lines)->sum(fn ($l) => (float) ($l['quantity'] ?? 0) * (float) ($l['unit_price'] ?? 0)), 2) }}
                    </p>
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-3">
            @if($purchaseOrder->isApproved())
                <flux:button type="button" wire:click="saveDraft" variant="primary">{{ __('Save Changes') }}</flux:button>
            @else
                <flux:button type="button" wire:click="saveDraft">{{ __('Save Draft') }}</flux:button>
                <flux:button type="button" wire:click="submitPending" variant="primary">{{ __('Submit (Pending)') }}</flux:button>
            @endif
        </div>
    </form>
</div>

@once
    <script>
        if (!window.__purchaseOrderSupplierLookupBootstrapped) {
            window.__purchaseOrderSupplierLookupBootstrapped = true;

            window.registerPurchaseOrderSupplierLookup = () => {
                Alpine.data('poSupplierLookup', ({ initial, selectedId, searchUrl }) => ({
                    query: initial || '',
                    selectedId: selectedId || null,
                    selectedLabel: initial || '',
                    searchUrl,
                    results: [],
                    loading: false,
                    open: false,
                    hasSearched: false,
                    panelStyle: '',
                    controller: null,
                    onInput(force = false) {
                        if (this.selectedId !== null && this.query !== this.selectedLabel) {
                            this.selectedId = null;
                            this.selectedLabel = '';
                            this.$wire.clearSupplier();
                        }

                        const term = this.query.trim();
                        if (!force && term.length < 2) {
                            this.open = false;
                            this.results = [];
                            this.hasSearched = false;
                            return;
                        }
                        if (term.length < 2) {
                            this.open = false;
                            this.results = [];
                            this.hasSearched = false;
                            return;
                        }

                        this.fetchResults(term);
                    },
                    fetchResults(term) {
                        this.loading = true;
                        this.hasSearched = true;
                        this.open = true;
                        if (this.controller) {
                            this.controller.abort();
                        }
                        this.controller = new AbortController();
                        const params = new URLSearchParams({ q: term });
                        fetch(this.searchUrl + '?' + params.toString(), {
                            headers: { 'Accept': 'application/json' },
                            signal: this.controller.signal,
                            credentials: 'same-origin',
                        })
                            .then((response) => response.ok ? response.json() : [])
                            .then((data) => {
                                this.results = Array.isArray(data) ? data : [];
                                this.loading = false;
                                this.$nextTick(() => this.positionDropdown());
                            })
                            .catch((error) => {
                                if (error.name === 'AbortError') {
                                    return;
                                }
                                this.loading = false;
                                this.results = [];
                            });
                    },
                    choose(supplier) {
                        const label = supplier.label || supplier.name || '';
                        this.query = label;
                        this.selectedLabel = label;
                        this.selectedId = supplier.id;
                        this.open = false;
                        this.results = [];
                        this.loading = false;
                        this.$wire.selectSupplier(supplier.id, label);
                    },
                    close() {
                        this.open = false;
                    },
                    positionDropdown() {
                        const input = this.$el.querySelector('input');
                        if (!input) {
                            return;
                        }
                        const rect = input.getBoundingClientRect();
                        this.panelStyle = [
                            'position: fixed',
                            'left: ' + rect.left + 'px',
                            'top: ' + rect.bottom + 'px',
                            'width: ' + rect.width + 'px',
                            'z-index: 9999',
                        ].join('; ');
                    },
                }));
            };

            if (window.Alpine) {
                window.registerPurchaseOrderSupplierLookup();
            } else {
                document.addEventListener('alpine:init', () => {
                    window.registerPurchaseOrderSupplierLookup();
                });
            }
        }

        if (!window.__purchaseOrderItemLookupBootstrapped) {
            window.__purchaseOrderItemLookupBootstrapped = true;

            window.registerPurchaseOrderItemLookup = () => {
                Alpine.data('poItemLookup', ({ index, initial, selectedId, searchUrl }) => ({
                    index,
                    query: initial || '',
                    selectedId: selectedId || null,
                    selectedLabel: initial || '',
                    searchUrl,
                    results: [],
                    loading: false,
                    open: false,
                    hasSearched: false,
                    panelStyle: '',
                    controller: null,
                    onInput(force = false) {
                        if (this.selectedId !== null && this.query !== this.selectedLabel) {
                            this.selectedId = null;
                            this.selectedLabel = '';
                            this.$wire.clearLineItem(this.index);
                        }

                        const term = this.query.trim();
                        if (!force && term.length < 2) {
                            this.open = false;
                            this.results = [];
                            this.hasSearched = false;
                            return;
                        }
                        if (term.length < 2) {
                            this.open = false;
                            this.results = [];
                            this.hasSearched = false;
                            return;
                        }

                        this.fetchResults(term);
                    },
                    fetchResults(term) {
                        this.loading = true;
                        this.hasSearched = true;
                        this.open = true;
                        if (this.controller) {
                            this.controller.abort();
                        }
                        this.controller = new AbortController();
                        const params = new URLSearchParams({ q: term });
                        fetch(this.searchUrl + '?' + params.toString(), {
                            headers: { 'Accept': 'application/json' },
                            signal: this.controller.signal,
                            credentials: 'same-origin',
                        })
                            .then((response) => response.ok ? response.json() : [])
                            .then((data) => {
                                this.results = Array.isArray(data) ? data : [];
                                this.loading = false;
                                this.$nextTick(() => this.positionDropdown());
                            })
                            .catch((error) => {
                                if (error.name === 'AbortError') {
                                    return;
                                }
                                this.loading = false;
                                this.results = [];
                            });
                    },
                    choose(item) {
                        const label = item.label || item.name || '';
                        this.query = label;
                        this.selectedLabel = label;
                        this.selectedId = item.id;
                        this.open = false;
                        this.results = [];
                        this.loading = false;
                        this.$wire.selectLineItem(this.index, item.id, label);
                    },
                    close() {
                        this.open = false;
                    },
                    positionDropdown() {
                        const input = this.$el.querySelector('input');
                        if (!input) {
                            return;
                        }
                        const rect = input.getBoundingClientRect();
                        this.panelStyle = [
                            'position: fixed',
                            'left: ' + rect.left + 'px',
                            'top: ' + rect.bottom + 'px',
                            'width: ' + rect.width + 'px',
                            'z-index: 9999',
                        ].join('; ');
                    },
                }));
            };

            if (window.Alpine) {
                window.registerPurchaseOrderItemLookup();
            } else {
                document.addEventListener('alpine:init', () => {
                    window.registerPurchaseOrderItemLookup();
                });
            }
        }
    </script>
@endonce
