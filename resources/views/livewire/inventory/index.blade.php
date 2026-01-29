<?php

use App\Models\InventoryItem;
use App\Services\Inventory\InventoryItemFormQueryService;
use App\Services\Inventory\InventoryItemIndexQueryService;
use App\Services\Inventory\InventoryItemStatusService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $status = 'active';
    public ?int $category_id = null;
    public ?int $supplier_id = null;
    public ?int $branch_id = null;
    public bool $low_stock_only = false;

    protected $paginationTheme = 'tailwind';

    public function updatingSearch(): void { $this->resetPage(); }
    public function updatingStatus(): void { $this->resetPage(); }
    public function updatingCategoryId(): void { $this->resetPage(); }
    public function updatingSupplierId(): void { $this->resetPage(); }
    public function updatingBranchId(): void { $this->resetPage(); }
    public function updatingLowStockOnly(): void { $this->resetPage(); }

    public function with(InventoryItemIndexQueryService $queryService, InventoryItemFormQueryService $formQuery): array
    {
        return [
            'items' => $queryService->paginate([
                'status' => $this->status,
                'category_id' => $this->category_id,
                'supplier_id' => $this->supplier_id,
                'branch_id' => $this->branch_id,
                'search' => $this->search,
                'low_stock_only' => $this->low_stock_only,
            ], 15),
            'categories' => $formQuery->categories(),
            'suppliers' => $formQuery->suppliers(),
            'branches' => $formQuery->branches(),
        ];
    }

    public function toggleStatus(InventoryItemStatusService $statusService, int $id): void
    {
        $item = InventoryItem::findOrFail($id);
        $statusService->toggle($item);
        session()->flash('status', __('Status updated.'));
    }
}; ?>

<div class="w-full max-w-7xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
            {{ __('Inventory') }}
        </h1>
        @if(auth()->user()->hasAnyRole(['admin','manager']))
            <flux:button :href="route('inventory.create')" wire:navigate variant="primary">
                {{ __('Create Item') }}
            </flux:button>
        @endif
    </div>

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="flex flex-wrap items-center gap-3">
            <flux:input
                wire:model.live.debounce.300ms="search"
                placeholder="{{ __('Search code, name, location') }}"
                class="w-full md:w-64"
            />

            @if ($categories->count())
                <div class="flex items-center gap-2">
                    <label for="category_id" class="text-sm text-neutral-800 dark:text-neutral-200">{{ __('Category') }}</label>
                    <select id="category_id" wire:model.live="category_id" class="rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="">{{ __('All') }}</option>
                        @foreach ($categories as $cat)
                            <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            @if ($suppliers->count())
                <div class="flex items-center gap-2">
                    <label for="supplier_id" class="text-sm text-neutral-800 dark:text-neutral-200">{{ __('Supplier') }}</label>
                    <select id="supplier_id" wire:model.live="supplier_id" class="rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="">{{ __('All') }}</option>
                        @foreach ($suppliers as $sup)
                            <option value="{{ $sup->id }}">{{ $sup->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            @if ($branches->count())
                <div class="flex items-center gap-2">
                    <label for="branch_id" class="text-sm text-neutral-800 dark:text-neutral-200">{{ __('Branch') }}</label>
                    <select id="branch_id" wire:model.live="branch_id" class="rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="">{{ __('All') }}</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            <div class="flex items-center gap-2">
                <label for="status" class="text-sm text-neutral-800 dark:text-neutral-200">{{ __('Status') }}</label>
                <select id="status" wire:model.live="status" class="rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="active">{{ __('Active') }}</option>
                    <option value="discontinued">{{ __('Discontinued') }}</option>
                    <option value="all">{{ __('All') }}</option>
                </select>
            </div>

            <div class="flex items-center gap-2">
                <flux:checkbox wire:model.live="low_stock_only" :label="__('Low stock only')" />
            </div>
        </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <table class="w-full min-w-full table-fixed divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Code') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Name') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Category') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Supplier') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Stock') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Min') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Units/Pkg') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Pkg Cost') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Unit Cost') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Status') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 bg-white dark:divide-neutral-800 dark:bg-neutral-900">
                @forelse ($items as $item)
                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                        <td class="px-3 py-3 text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ $item->item_code }}</td>
                        <td class="px-3 py-3 text-sm text-neutral-900 dark:text-neutral-100" title="{{ $item->name }}">
                            {{ \Illuminate\Support\Str::limit($item->name, 24, '...') }}
                        </td>
                        <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200" title="{{ $item->category?->name }}">
                            {{ \Illuminate\Support\Str::limit($item->category?->name, 12, '...') }}
                        </td>
                        <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200" title="{{ $item->supplier?->name }}">
                            {{ \Illuminate\Support\Str::limit($item->supplier?->name, 12, '...') }}
                        </td>
                        <td class="px-3 py-3 text-sm text-neutral-900 dark:text-neutral-100">
                            {{ $item->current_stock }}
                            @if ($item->isLowStock())
                                <span class="ml-2 inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-800 dark:bg-amber-900 dark:text-amber-100">{{ __('Low') }}</span>
                            @endif
                        </td>
                        <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ $item->minimum_stock }}</td>
                        <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ $item->units_per_package }}</td>
                        <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ number_format((float) $item->cost_per_unit, 2, '.', '') }}</td>
                        <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">
                            @php $unitCost = $item->perUnitCost(); @endphp
                            {{ $unitCost !== null ? number_format($unitCost, 2, '.', '') : '—' }}
                        </td>
                        <td class="px-3 py-3 text-sm">
                            <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold {{ $item->status === 'active' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-100' : 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-100' }}">
                                {{ ucfirst($item->status) }}
                            </span>
                        </td>
                        <td class="px-3 py-3 text-sm">
                            <div class="flex flex-wrap gap-2">
                                <flux:button size="xs" :href="route('inventory.show', $item)" wire:navigate>{{ __('View') }}</flux:button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="13" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">
                            {{ __('No inventory items found.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>
        {{ $items->links() }}
    </div>
</div>
