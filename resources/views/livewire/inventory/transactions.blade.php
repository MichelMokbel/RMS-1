<?php

use App\Models\InventoryItem;
use App\Services\Inventory\InventoryItemFormQueryService;
use App\Services\Inventory\InventoryItemPersistService;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\InventoryTransactionQueryService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public ?int $item_id = null;
    public string $item_search = '';
    public ?int $branch_id = null;
    public string $transaction_type = 'in';
    public float $quantity = 1.0;
    public ?float $unit_cost = null;
    public ?string $notes = null;
    public ?string $transaction_date = null;

    public array $bulk_rows = [];
    public array $bulk_search = [];
    public ?string $bulk_notes = null;

    public string $create_item_target = 'single';
    public ?int $create_item_target_index = null;
    public string $new_item_name = '';
    public ?string $new_item_description = null;
    public ?int $new_item_category_id = null;
    public float $new_item_units_per_package = 1.0;
    public ?string $new_item_package_label = null;
    public ?string $new_item_unit_of_measure = null;
    public ?float $new_item_cost_per_unit = null;

    protected $paginationTheme = 'tailwind';

    public function mount(): void
    {
        $this->branch_id = (int) config('inventory.default_branch_id', 1);
        $this->transaction_date = now()->format('Y-m-d\TH:i');
        $this->bulk_rows = [
            ['item_id' => null, 'target_quantity' => 0],
        ];
        $this->bulk_search = [''];
    }

    public function with(InventoryItemFormQueryService $formQuery, InventoryTransactionQueryService $queryService): array
    {
        $items = InventoryItem::query()
            ->where('status', 'active')
            ->with('category.parent.parent.parent')
            ->with(['stocks' => fn ($query) => $query->where('branch_id', (int) $this->branch_id)])
            ->orderBy('name')
            ->get();

        $itemOptions = $items
            ->map(function (InventoryItem $item): array {
                $categoryLabel = $item->categoryLabel();
                $stock = (float) ($item->stocks->first()?->current_stock ?? 0);

                return [
                    'id' => (int) $item->id,
                    'name' => $item->name,
                    'code' => (string) ($item->item_code ?? ''),
                    'label' => trim(($item->item_code ? $item->item_code.' ' : '').$item->name),
                    'meta' => $categoryLabel ? '['.$categoryLabel.']' : '',
                    'stock' => $stock,
                    'stock_label' => number_format($stock, 3, '.', ''),
                ];
            })
            ->values();

        return [
            'itemOptions' => $itemOptions,
            'branches' => $formQuery->branches(),
            'categories' => $formQuery->categories(),
            'transactions' => $queryService->query([
                'branch_id' => $this->branch_id,
            ])->paginate(20),
        ];
    }

    public function addBulkRow(): void
    {
        $this->bulk_rows[] = ['item_id' => null, 'target_quantity' => 0];
        $this->bulk_search[] = '';
    }

    public function removeBulkRow(int $index): void
    {
        if (! array_key_exists($index, $this->bulk_rows) || count($this->bulk_rows) === 1) {
            return;
        }

        unset($this->bulk_rows[$index], $this->bulk_search[$index]);
        $this->bulk_rows = array_values($this->bulk_rows);
        $this->bulk_search = array_values($this->bulk_search);
    }

    public function selectItem(int $itemId, string $label = ''): void
    {
        $this->item_id = $itemId;
        $this->item_search = $label;
    }

    public function clearItem(): void
    {
        $this->item_id = null;
        $this->item_search = '';
    }

    public function selectBulkItem(int $index, int $itemId, string $label = ''): void
    {
        if (! array_key_exists($index, $this->bulk_rows)) {
            return;
        }

        $this->bulk_rows[$index]['item_id'] = $itemId;
        $this->bulk_search[$index] = $label;
    }

    public function clearBulkItem(int $index): void
    {
        if (! array_key_exists($index, $this->bulk_rows)) {
            return;
        }

        $this->bulk_rows[$index]['item_id'] = null;
        $this->bulk_search[$index] = '';
    }

    public function openCreateItem(string $target = 'single', ?int $index = null): void
    {
        $this->resetCreateItemForm();
        $this->create_item_target = in_array($target, ['single', 'bulk'], true) ? $target : 'single';
        $this->create_item_target_index = $index;
    }

    public function closeCreateItem(): void
    {
        $this->create_item_target = 'single';
        $this->create_item_target_index = null;
        $this->resetCreateItemForm();
        $this->dispatch('modal-close', name: 'create-inventory-item');
    }

    public function createItem(InventoryItemPersistService $persist): void
    {
        $validated = $this->validate([
            'new_item_name' => ['required', 'string', 'max:200'],
            'new_item_description' => ['nullable', 'string'],
            'new_item_category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'new_item_units_per_package' => ['required', 'numeric', 'min:0.001'],
            'new_item_package_label' => ['nullable', 'string', 'max:50'],
            'new_item_unit_of_measure' => ['nullable', 'string', 'max:50'],
            'new_item_cost_per_unit' => ['nullable', 'numeric', 'min:0'],
        ]);

        $item = $persist->createFromForm([
            'name' => $validated['new_item_name'],
            'description' => $validated['new_item_description'] ?? null,
            'category_id' => $validated['new_item_category_id'] ?? null,
            'supplier_id' => null,
            'branch_id' => $this->branch_id,
            'units_per_package' => (float) $validated['new_item_units_per_package'],
            'package_label' => $validated['new_item_package_label'] ?? null,
            'unit_of_measure' => $validated['new_item_unit_of_measure'] ?? null,
            'minimum_stock' => 0,
            'current_stock' => 0,
            'cost_per_unit' => $validated['new_item_cost_per_unit'] ?? null,
            'location' => null,
            'status' => 'active',
        ], null, Auth::id());

        $label = trim(($item->item_code ? $item->item_code.' ' : '').$item->name);

        if ($this->create_item_target === 'bulk' && $this->create_item_target_index !== null) {
            $this->selectBulkItem($this->create_item_target_index, (int) $item->id, $label);
        } else {
            $this->selectItem((int) $item->id, $label);
        }

        $this->resetCreateItemForm();
        $this->create_item_target = 'single';
        $this->create_item_target_index = null;
        $this->dispatch('modal-close', name: 'create-inventory-item');
        session()->flash('status', __('Inventory item created.'));
    }

    public function save(InventoryStockService $stockService): void
    {
        $data = $this->validate([
            'item_id' => ['required', 'integer', 'exists:inventory_items,id'],
            'branch_id' => ['required', 'integer', 'min:1'],
            'transaction_type' => ['required', 'in:in,out,adjust'],
            'quantity' => ['required', 'numeric'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'transaction_date' => ['required', 'date'],
        ]);

        $item = InventoryItem::query()->findOrFail((int) $data['item_id']);

        $stockService->postTransaction(
            $item,
            $data['transaction_type'],
            (float) $data['quantity'],
            $data['notes'] ?? null,
            (int) (Auth::id() ?? 0),
            (int) $data['branch_id'],
            isset($data['unit_cost']) ? (float) $data['unit_cost'] : null,
            'manual',
            null,
            $data['transaction_date']
        );

        $this->reset(['item_id', 'item_search', 'transaction_type', 'quantity', 'unit_cost', 'notes']);
        $this->transaction_type = 'in';
        $this->quantity = 1.0;
        $this->transaction_date = now()->format('Y-m-d\TH:i');
        session()->flash('status', __('Transaction recorded.'));
    }

    public function saveBulkAdjustments(InventoryStockService $stockService): void
    {
        $data = $this->validate([
            'branch_id' => ['required', 'integer', 'min:1'],
            'transaction_date' => ['required', 'date'],
            'bulk_notes' => ['nullable', 'string'],
            'bulk_rows' => ['required', 'array', 'min:1'],
            'bulk_rows.*.item_id' => ['required', 'integer', 'exists:inventory_items,id'],
            'bulk_rows.*.target_quantity' => ['required', 'numeric', 'min:0'],
        ]);

        $itemIds = collect($data['bulk_rows'])
            ->pluck('item_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $items = InventoryItem::query()
            ->whereIn('id', $itemIds)
            ->with(['stocks' => fn ($query) => $query->where('branch_id', (int) $data['branch_id'])])
            ->get()
            ->keyBy('id');

        $posted = 0;

        foreach ($data['bulk_rows'] as $row) {
            $item = $items->get((int) $row['item_id']);

            if (! $item) {
                continue;
            }

            $currentStock = (float) ($item->stocks->first()?->current_stock ?? 0);
            $targetQuantity = round((float) $row['target_quantity'], 3);
            $delta = round($targetQuantity - $currentStock, 3);

            if (abs($delta) < 0.0005) {
                continue;
            }

            $stockService->postTransaction(
                $item,
                'adjustment',
                $delta,
                $data['bulk_notes'] ?? null,
                (int) (Auth::id() ?? 0),
                (int) $data['branch_id'],
                null,
                'manual',
                null,
                $data['transaction_date']
            );

            $posted++;
        }

        if ($posted === 0) {
            throw ValidationException::withMessages([
                'bulk_rows' => __('Enter at least one quantity that changes the current stock.'),
            ]);
        }

        $this->bulk_rows = [['item_id' => null, 'target_quantity' => 0]];
        $this->bulk_search = [''];
        $this->bulk_notes = null;
        $this->transaction_date = now()->format('Y-m-d\TH:i');
        session()->flash('status', __('Bulk adjustment recorded.'));
    }

    private function resetCreateItemForm(): void
    {
        $this->reset([
            'new_item_name',
            'new_item_description',
            'new_item_category_id',
            'new_item_package_label',
            'new_item_unit_of_measure',
            'new_item_cost_per_unit',
        ]);
        $this->new_item_units_per_package = 1.0;
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Inventory Transactions') }}</h1>
        <div class="flex gap-2">
            <flux:button :href="route('inventory.index')" wire:navigate variant="ghost">{{ __('Back to Inventory') }}</flux:button>
            <flux:button :href="route('reports.inventory-transactions')" wire:navigate variant="ghost">{{ __('Open Report') }}</flux:button>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 dark:border-rose-900 dark:bg-rose-950 dark:text-rose-100">
            <p class="font-semibold">{{ __('Please fix the highlighted issues.') }}</p>
            <ul class="mt-1 list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @php
        $selectedSingleItem = $itemOptions->firstWhere('id', $item_id);
    @endphp

    <div class="grid gap-6 xl:grid-cols-[1.4fr,1fr]">
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="mb-4 flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Bulk Adjust Quantities') }}</h2>
                    <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">{{ __('Set the target stock for multiple items and post the adjustments in one go.') }}</p>
                </div>
                <flux:button type="button" variant="ghost" size="sm" wire:click="openCreateItem('bulk', 0)" x-data="" x-on:click.prevent="$dispatch('open-modal', 'create-inventory-item')">{{ __('New Item') }}</flux:button>
            </div>

            <form wire:submit="saveBulkAdjustments" class="space-y-4">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Branch') }}</label>
                        <select wire:model.live="branch_id" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <flux:input wire:model="transaction_date" type="datetime-local" :label="__('Adjustment Date')" />
                </div>

                <div class="space-y-3">
                    @foreach ($bulk_rows as $index => $row)
                        @php
                            $selectedBulkItem = $itemOptions->firstWhere('id', $row['item_id'] ?? null);
                            $currentStock = (float) ($selectedBulkItem['stock'] ?? 0);
                            $targetQuantity = round((float) ($row['target_quantity'] ?? 0), 3);
                            $delta = round($targetQuantity - $currentStock, 3);
                        @endphp
                        <div class="rounded-lg border border-neutral-200 p-4 dark:border-neutral-700">
                            <div class="grid grid-cols-1 gap-4 lg:grid-cols-[1.8fr,0.8fr,0.8fr,auto] lg:items-end">
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Item') }}</label>
                                    <div
                                        class="relative"
                                        wire:key="bulk-item-picker-{{ $index }}-{{ $branch_id }}-{{ $row['item_id'] ?? 'none' }}-{{ $itemOptions->count() }}"
                                        wire:ignore
                                        x-data="inventoryTransactionPicker({
                                            initial: @js($bulk_search[$index] ?? ''),
                                            selectedId: @js($row['item_id'] ?? null),
                                            options: @js($itemOptions),
                                            select(item) { this.$wire.selectBulkItem({{ $index }}, item.id, item.label) },
                                            clear() { this.$wire.clearBulkItem({{ $index }}) },
                                        })"
                                        x-on:keydown.escape.stop="open = false"
                                        x-on:click.outside="open = false"
                                    >
                                        <input
                                            type="text"
                                            x-model="query"
                                            x-on:focus="open = true"
                                            x-on:input="handleInput()"
                                            placeholder="{{ __('Search item') }}"
                                            class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50"
                                        />
                                        <button
                                            x-show="selectedId !== null || query !== ''"
                                            x-on:click.prevent="clearSelection()"
                                            type="button"
                                            class="absolute inset-y-0 right-0 px-3 text-xs text-neutral-500 hover:text-neutral-700 dark:text-neutral-400 dark:hover:text-neutral-200"
                                        >{{ __('Clear') }}</button>
                                        <div
                                            x-show="open"
                                            class="absolute z-30 mt-1 max-h-64 w-full overflow-auto rounded-md border border-neutral-200 bg-white shadow-lg dark:border-neutral-700 dark:bg-neutral-900"
                                        >
                                            <template x-for="item in filteredOptions" :key="item.id">
                                                <button
                                                    type="button"
                                                    x-on:click="choose(item)"
                                                    class="w-full px-3 py-2 text-left text-sm hover:bg-neutral-50 dark:hover:bg-neutral-800/80"
                                                >
                                                    <div class="flex items-center justify-between gap-2">
                                                        <span class="font-medium text-neutral-800 dark:text-neutral-100" x-text="item.label"></span>
                                                        <span class="text-xs text-neutral-500 dark:text-neutral-400" x-text="item.stock_label"></span>
                                                    </div>
                                                    <div class="text-xs text-neutral-500 dark:text-neutral-400" x-show="item.meta" x-text="item.meta"></div>
                                                </button>
                                            </template>
                                            <div x-show="filteredOptions.length === 0" class="px-3 py-2 text-sm text-neutral-500 dark:text-neutral-400">
                                                {{ __('No items found.') }}
                                            </div>
                                        </div>
                                    </div>
                                    @error('bulk_rows.'.$index.'.item_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                </div>

                                <div>
                                    <div class="mb-1 text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Current Stock') }}</div>
                                    <div class="rounded-md border border-neutral-200 bg-neutral-50 px-3 py-2 text-sm text-neutral-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                                        {{ number_format($currentStock, 3) }}
                                    </div>
                                </div>

                                <div>
                                    <flux:input wire:model="bulk_rows.{{ $index }}.target_quantity" type="number" min="0" step="0.001" :label="__('Target Qty')" />
                                    @error('bulk_rows.'.$index.'.target_quantity') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                </div>

                                <div class="flex items-center gap-2">
                                    <div class="rounded-md border border-neutral-200 bg-neutral-50 px-3 py-2 text-sm text-neutral-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                                        {{ __('Delta: :qty', ['qty' => number_format($delta, 3)]) }}
                                    </div>
                                    <flux:button type="button" variant="ghost" size="sm" wire:click="openCreateItem('bulk', {{ $index }})">{{ __('New') }}</flux:button>
                                    @if (count($bulk_rows) > 1)
                                        <flux:button type="button" variant="danger" size="sm" wire:click="removeBulkRow({{ $index }})">{{ __('Remove') }}</flux:button>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <flux:button type="button" variant="outline" wire:click="addBulkRow">{{ __('Add Item') }}</flux:button>
                    <div class="text-sm text-neutral-500 dark:text-neutral-400">{{ __('Use target quantity, not plus/minus. The adjustment is calculated for you.') }}</div>
                </div>

                <flux:textarea wire:model="bulk_notes" :label="__('Notes (optional)')" rows="2" />

                <div class="flex justify-end">
                    <flux:button type="submit" variant="primary">{{ __('Post Bulk Adjustment') }}</flux:button>
                </div>
            </form>
        </div>

        <div class="space-y-6">
            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="mb-4 flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Quick Transaction') }}</h2>
                        <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">{{ __('Record a single in, out, or adjustment transaction.') }}</p>
                    </div>
                    <flux:button type="button" variant="ghost" size="sm" wire:click="openCreateItem('single')" x-data="" x-on:click.prevent="$dispatch('open-modal', 'create-inventory-item')">{{ __('New Item') }}</flux:button>
                </div>

                <form wire:submit="save" class="space-y-4">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div>
                            <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Branch') }}</label>
                            <select wire:model.live="branch_id" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                @foreach ($branches as $branch)
                                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <flux:input wire:model="transaction_date" type="datetime-local" :label="__('Transaction Date')" />
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Item') }}</label>
                        <div
                            class="relative"
                            wire:key="single-item-picker-{{ $branch_id }}-{{ $item_id ?? 'none' }}-{{ $itemOptions->count() }}"
                            wire:ignore
                            x-data="inventoryTransactionPicker({
                                initial: @js($item_search),
                                selectedId: @js($item_id),
                                options: @js($itemOptions),
                                select(item) { this.$wire.selectItem(item.id, item.label) },
                                clear() { this.$wire.clearItem() },
                            })"
                            x-on:keydown.escape.stop="open = false"
                            x-on:click.outside="open = false"
                        >
                            <input
                                type="text"
                                x-model="query"
                                x-on:focus="open = true"
                                x-on:input="handleInput()"
                                placeholder="{{ __('Search item') }}"
                                class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50"
                            />
                            <button
                                x-show="selectedId !== null || query !== ''"
                                x-on:click.prevent="clearSelection()"
                                type="button"
                                class="absolute inset-y-0 right-0 px-3 text-xs text-neutral-500 hover:text-neutral-700 dark:text-neutral-400 dark:hover:text-neutral-200"
                            >{{ __('Clear') }}</button>
                            <div
                                x-show="open"
                                class="absolute z-30 mt-1 max-h-64 w-full overflow-auto rounded-md border border-neutral-200 bg-white shadow-lg dark:border-neutral-700 dark:bg-neutral-900"
                            >
                                <template x-for="item in filteredOptions" :key="item.id">
                                    <button
                                        type="button"
                                        x-on:click="choose(item)"
                                        class="w-full px-3 py-2 text-left text-sm hover:bg-neutral-50 dark:hover:bg-neutral-800/80"
                                    >
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="font-medium text-neutral-800 dark:text-neutral-100" x-text="item.label"></span>
                                            <span class="text-xs text-neutral-500 dark:text-neutral-400" x-text="item.stock_label"></span>
                                        </div>
                                        <div class="text-xs text-neutral-500 dark:text-neutral-400" x-show="item.meta" x-text="item.meta"></div>
                                    </button>
                                </template>
                                <div x-show="filteredOptions.length === 0" class="px-3 py-2 text-sm text-neutral-500 dark:text-neutral-400">
                                    {{ __('No items found.') }}
                                </div>
                            </div>
                        </div>
                        @error('item_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        @if ($selectedSingleItem)
                            <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                                {{ __('Current stock: :qty', ['qty' => number_format((float) $selectedSingleItem['stock'], 3)]) }}
                            </p>
                        @endif
                    </div>

                    <div>
                        <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Type') }}</label>
                        <select wire:model="transaction_type" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                            <option value="in">{{ __('In') }}</option>
                            <option value="out">{{ __('Out') }}</option>
                            <option value="adjust">{{ __('Adjust') }}</option>
                        </select>
                    </div>

                    <flux:input wire:model="quantity" type="number" step="0.001" :label="__('Quantity')" />
                    <flux:input wire:model="unit_cost" type="number" step="0.0001" min="0" :label="__('Unit Cost (optional)')" />
                    <flux:textarea wire:model="notes" :label="__('Description / Notes')" rows="2" />

                    <div class="flex justify-end">
                        <flux:button type="submit" variant="primary">{{ __('Record Transaction') }}</flux:button>
                    </div>
                </form>
            </div>

        </div>
    </div>

    <flux:modal name="create-inventory-item" focusable class="max-w-2xl">
        <form wire:submit="createItem" class="space-y-4">
            <div class="space-y-1">
                <flux:heading size="lg">{{ __('Create Inventory Item') }}</flux:heading>
                <flux:subheading>{{ __('Add the item here and it will be available immediately in the transaction pickers.') }}</flux:subheading>
                @if ($create_item_target === 'bulk' && $create_item_target_index !== null)
                    <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ __('Next created item will be selected for the current bulk row.') }}</p>
                @endif
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <flux:input wire:model="new_item_name" :label="__('Name')" maxlength="200" />
                @if ($categories->count())
                    <div>
                        <label class="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Category') }}</label>
                        <select wire:model="new_item_category_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                            <option value="">{{ __('None') }}</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}">{{ $category->fullName() }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <flux:input wire:model="new_item_units_per_package" type="number" min="0.001" step="0.001" :label="__('Units per Package')" />
                <flux:input wire:model="new_item_package_label" :label="__('Package Label')" maxlength="50" />
                <flux:input wire:model="new_item_unit_of_measure" :label="__('Unit of Measure')" maxlength="50" />
                <flux:input wire:model="new_item_cost_per_unit" type="number" min="0" step="0.0001" :label="__('Package Cost (optional)')" />
            </div>

            <flux:textarea wire:model="new_item_description" :label="__('Description (optional)')" rows="2" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button type="button" variant="filled" wire:click="closeCreateItem">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Create Item') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <div class="app-table-shell">
        <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Date') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Item') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Type') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Qty') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Unit Cost') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Reference') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('User') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Notes') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                @forelse ($transactions as $transaction)
                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $transaction->transaction_date?->format('Y-m-d H:i') }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $transaction->item?->item_code }} {{ $transaction->item?->name }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ ucfirst($transaction->transaction_type) }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ number_format((float) $transaction->delta(), 3) }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ number_format((float) ($transaction->unit_cost ?? 0), 4) }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ trim(($transaction->reference_type ?? '').' '.($transaction->reference_id ?? '')) }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $transaction->user?->username ?? $transaction->user?->email ?? '—' }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $transaction->notes ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No transactions found.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>{{ $transactions->links() }}</div>
</div>

@once
    <script>
        if (!window.__inventoryTransactionPickerBootstrapped) {
            window.__inventoryTransactionPickerBootstrapped = true;

            window.registerInventoryTransactionPicker = () => {
                Alpine.data('inventoryTransactionPicker', ({ initial, selectedId, options, select, clear }) => ({
                    query: initial || '',
                    selectedId: selectedId || null,
                    selectedLabel: initial || '',
                    options: Array.isArray(options) ? options : [],
                    open: false,
                    init() {
                        if (!this.selectedLabel && this.selectedId !== null) {
                            const selected = this.options.find((item) => Number(item.id) === Number(this.selectedId));
                            this.selectedLabel = selected ? selected.label : '';
                            this.query = this.selectedLabel;
                        }
                    },
                    get filteredOptions() {
                        const term = this.query.trim().toLowerCase();
                        if (term === '') {
                            return this.options;
                        }

                        return this.options.filter((item) => {
                            return [item.label, item.meta, item.code, item.name]
                                .filter(Boolean)
                                .some((value) => value.toLowerCase().includes(term));
                        });
                    },
                    handleInput() {
                        if (this.selectedId !== null && this.query !== this.selectedLabel) {
                            this.selectedId = null;
                            this.selectedLabel = '';
                            clear.call(this);
                        }

                        this.open = true;
                    },
                    choose(item) {
                        this.selectedId = item.id;
                        this.selectedLabel = item.label;
                        this.query = item.label;
                        this.open = false;
                        select.call(this, item);
                    },
                    clearSelection() {
                        this.selectedId = null;
                        this.selectedLabel = '';
                        this.query = '';
                        this.open = false;
                        clear.call(this);
                    },
                }));
            };

            if (window.Alpine) {
                window.registerInventoryTransactionPicker();
            } else {
                document.addEventListener('alpine:init', () => {
                    window.registerInventoryTransactionPicker();
                });
            }
        }
    </script>
@endonce
