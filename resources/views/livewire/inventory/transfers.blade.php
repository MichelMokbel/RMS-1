<?php

use App\Models\InventoryTransfer;
use App\Services\Inventory\InventoryTransferService;
use App\Services\Inventory\InventoryTransferQueryService;
use App\Support\Inventory\InventoryTransferRules;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public ?int $from_branch_id = null;
    public ?int $to_branch_id = null;
    public ?string $transfer_date = null;
    public ?string $notes = null;
    public array $lines = [];
    public array $lineSearch = [];

    public string $status = 'all';
    public ?int $branch_filter = null;

    protected $paginationTheme = 'tailwind';

    public function mount(): void
    {
        $this->from_branch_id = (int) config('inventory.default_branch_id', 1);
        $this->transfer_date = now()->toDateString();
        $this->lines = [
            ['item_id' => null, 'quantity' => 1],
        ];
        $this->lineSearch = [''];
    }

    public function updatingStatus(): void { $this->resetPage(); }
    public function updatingBranchFilter(): void { $this->resetPage(); }

    public function with(InventoryTransferQueryService $queryService): array
    {
        return [
            'transfers' => $queryService->transfers($this->status, $this->branch_filter, 15),
            'branches' => $queryService->branches(),
            'sourceStocks' => $queryService->sourceStocks($this->from_branch_id, $this->lines),
        ];
    }

    public function addLine(): void
    {
        $this->lines[] = ['item_id' => null, 'quantity' => 1];
        $this->lineSearch[] = '';
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
        unset($this->lineSearch[$index]);
        $this->lineSearch = array_values($this->lineSearch);
    }

    public function selectTransferItem(int $index, int $itemId, string $label = ''): void
    {
        if (! array_key_exists($index, $this->lines)) {
            return;
        }

        $this->lines[$index]['item_id'] = $itemId;
        $this->lineSearch[$index] = $label;
    }

    public function clearTransferItem(int $index): void
    {
        if (! array_key_exists($index, $this->lines)) {
            return;
        }

        $this->lines[$index]['item_id'] = null;
        $this->lineSearch[$index] = '';
    }

    public function submit(InventoryTransferService $service, InventoryTransferRules $rules): void
    {
        $data = $this->validate($rules->rules());

        $service->createAndPostBulk(
            (int) $data['from_branch_id'],
            (int) $data['to_branch_id'],
            $data['lines'],
            (int) (Auth::id() ?? 0),
            $data['notes'] ?? null,
            $data['transfer_date'] ?? null
        );

        $this->reset(['to_branch_id', 'notes']);
        $this->lines = [['item_id' => null, 'quantity' => 1]];
        $this->lineSearch = [''];
        session()->flash('status', __('Transfer completed.'));
    }
}; ?>

<div class="w-full max-w-7xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Inventory Transfers') }}</h1>
        <flux:button :href="route('inventory.index')" wire:navigate variant="ghost">{{ __('Back') }}</flux:button>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <h2 class="text-sm font-semibold text-neutral-800 dark:text-neutral-100 mb-3">{{ __('Create Transfer (Bulk)') }}</h2>
        @if ($errors->any())
            <div class="mb-4 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 dark:border-rose-900 dark:bg-rose-950 dark:text-rose-100">
                <p class="font-semibold">{{ __('Please fix the highlighted issues.') }}</p>
                <ul class="mt-1 list-disc space-y-1 pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        <form wire:submit="submit" class="space-y-4">
            <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                <div>
                    <label class="block text-sm font-medium text-neutral-800 dark:text-neutral-200 mb-1">{{ __('From Branch') }}</label>
                    <select wire:model="from_branch_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                        @endforeach
                    </select>
                    @error('from_branch_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-neutral-800 dark:text-neutral-200 mb-1">{{ __('To Branch') }}</label>
                    <select wire:model="to_branch_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="">{{ __('Select') }}</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}" @if($from_branch_id == $branch->id) disabled @endif>{{ $branch->name }}</option>
                        @endforeach
                    </select>
                    @error('to_branch_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <flux:input wire:model="transfer_date" type="date" :label="__('Transfer Date')" />
            </div>
            @error('transfer_date') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror

            <div class="space-y-2">
                <h3 class="text-sm font-semibold text-neutral-800 dark:text-neutral-100">{{ __('Items') }}</h3>
                @error('lines') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                @error('quantity') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                @foreach ($lines as $index => $line)
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-4 md:items-end">
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-neutral-800 dark:text-neutral-200 mb-1">{{ __('Item') }}</label>
                            <div
                                class="relative"
                                wire:ignore
                                x-data="inventoryItemLookup({
                                    index: {{ $index }},
                                    initial: @js($lineSearch[$index] ?? ''),
                                    selectedId: @js($line['item_id'] ?? null),
                                    searchUrl: '{{ route('inventory.items.search') }}',
                                    branchId: @entangle('from_branch_id')
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
                                    placeholder="{{ __('Search item') }}"
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
                            @error('lines.'.$index.'.item_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            @if (!empty($line['item_id']))
                                @php
                                    $available = $sourceStocks[$line['item_id']] ?? null;
                                @endphp
                                @if ($available === null)
                                    <p class="mt-1 text-xs text-amber-600">{{ __('Not available in source branch.') }}</p>
                                @else
                                    <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                                        {{ __('Available: :qty', ['qty' => number_format((float) $available, 3, '.', '')]) }}
                                    </p>
                                @endif
                            @endif
                        </div>
                        <div>
                            <flux:input wire:model="lines.{{ $index }}.quantity" type="number" min="0.001" step="0.001" :label="__('Quantity (packages)')" />
                            @error('lines.'.$index.'.quantity') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div class="flex gap-2">
                            @if (count($lines) > 1)
                                <flux:button size="xs" variant="danger" type="button" wire:click="removeLine({{ $index }})">{{ __('Remove') }}</flux:button>
                            @endif
                        </div>
                    </div>
                @endforeach

                <flux:button size="xs" variant="outline" type="button" wire:click="addLine">{{ __('Add Line') }}</flux:button>
            </div>

            <flux:input wire:model="notes" :label="__('Notes')" />

            <div class="flex justify-end">
                <flux:button type="submit" variant="primary">{{ __('Submit Transfer') }}</flux:button>
            </div>
        </form>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="flex flex-wrap items-center gap-3 mb-4">
            <div class="flex items-center gap-2">
                <label for="status" class="text-sm text-neutral-800 dark:text-neutral-200">{{ __('Status') }}</label>
                <select id="status" wire:model.live="status" class="rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="all">{{ __('All') }}</option>
                    <option value="posted">{{ __('Posted') }}</option>
                    <option value="draft">{{ __('Draft') }}</option>
                    <option value="cancelled">{{ __('Cancelled') }}</option>
                </select>
            </div>
            @if ($branches->count())
                <div class="flex items-center gap-2">
                    <label for="branch_filter" class="text-sm text-neutral-800 dark:text-neutral-200">{{ __('Branch') }}</label>
                    <select id="branch_filter" wire:model.live="branch_filter" class="rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="">{{ __('All') }}</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
        </div>

        <div class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <table class="w-full min-w-full table-fixed divide-y divide-neutral-200 dark:divide-neutral-800">
                <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                    <tr>
                        <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('ID') }}</th>
                        <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Date') }}</th>
                        <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('From') }}</th>
                        <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('To') }}</th>
                        <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Lines') }}</th>
                        <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Status') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 bg-white dark:divide-neutral-800 dark:bg-neutral-900">
                    @forelse ($transfers as $transfer)
                        <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                            <td class="px-3 py-3 text-sm text-neutral-900 dark:text-neutral-100">#{{ $transfer->id }}</td>
                            <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ optional($transfer->transfer_date)->format('Y-m-d') }}</td>
                            <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ $transfer->from_branch_id }}</td>
                            <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ $transfer->to_branch_id }}</td>
                            <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ $transfer->lines->count() }}</td>
                            <td class="px-3 py-3 text-sm">
                                <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold {{ $transfer->status === 'posted' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-100' : 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-100' }}">
                                    {{ ucfirst($transfer->status) }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">
                                {{ __('No transfers found.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $transfers->links() }}
        </div>
    </div>
</div>

@once
    <script>
        if (!window.__inventoryTransferLookupBootstrapped) {
            window.__inventoryTransferLookupBootstrapped = true;

            window.registerInventoryTransferLookup = () => {
                Alpine.data('inventoryItemLookup', ({ index, initial, selectedId, searchUrl, branchId }) => ({
                    index,
                    query: initial || '',
                    selectedId: selectedId || null,
                    selectedLabel: initial || '',
                    searchUrl,
                    branchId,
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
                            this.$wire.clearTransferItem(this.index);
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
                        if (this.branchId) {
                            params.append('branch_id', this.branchId);
                        }
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
                        this.$wire.selectTransferItem(this.index, item.id, label);
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
                window.registerInventoryTransferLookup();
            } else {
                document.addEventListener('alpine:init', () => {
                    window.registerInventoryTransferLookup();
                });
            }
        }
    </script>
@endonce
