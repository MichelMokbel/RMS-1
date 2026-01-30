<?php

use App\Services\Inventory\InventoryItemFormQueryService;
use App\Services\Inventory\InventoryItemIndexQueryService;
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

    public function updating($name): void
    {
        if (in_array($name, ['search', 'status', 'category_id', 'supplier_id', 'branch_id', 'low_stock_only'], true)) {
            $this->resetPage();
        }
    }

    public function with(InventoryItemIndexQueryService $queryService, InventoryItemFormQueryService $formQuery): array
    {
        $filters = [
                'status' => $this->status,
                'category_id' => $this->category_id,
                'supplier_id' => $this->supplier_id,
                'branch_id' => $this->branch_id,
                'search' => $this->search,
                'low_stock_only' => $this->low_stock_only,
            ];
        return [
            'items' => $queryService->query($filters)->with('category')->paginate(15),
            'categories' => $formQuery->categories(),
            'suppliers' => $formQuery->suppliers(),
            'branches' => $formQuery->branches(),
            'exportParams' => $this->exportParams(),
        ];
    }

    public function exportParams(): array
    {
        return array_filter([
            'search' => $this->search ?: null,
            'status' => $this->status !== 'all' ? $this->status : null,
            'category_id' => $this->category_id,
            'supplier_id' => $this->supplier_id,
            'branch_id' => $this->branch_id,
            'low_stock_only' => $this->low_stock_only ? 1 : null,
        ], fn ($v) => $v !== null && $v !== '');
    }
}; ?>

<div class="w-full max-w-7xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Inventory Report') }}</h1>
        <div class="flex gap-2">
            <flux:button :href="route('reports.index')" wire:navigate variant="ghost">{{ __('Back to Reports') }}</flux:button>
            <flux:button href="{{ route('reports.inventory.print') . '?' . http_build_query($exportParams) }}" target="_blank" variant="ghost">{{ __('Print') }}</flux:button>
            <flux:button href="{{ route('reports.inventory.csv') . '?' . http_build_query($exportParams) }}" variant="ghost">{{ __('Export CSV') }}</flux:button>
            <flux:button href="{{ route('reports.inventory.pdf') . '?' . http_build_query($exportParams) }}" variant="ghost">{{ __('Export PDF') }}</flux:button>
        </div>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="flex flex-wrap items-end gap-3">
            <div class="min-w-[200px]">
                <flux:input wire:model.live.debounce.300ms="search" :label="__('Search')" placeholder="{{ __('Code, name, location') }}" />
            </div>
            <x-reports.branch-select name="branch_id" :branches="$branches" />
            <x-reports.status-select name="status" :options="[
                ['value' => 'all', 'label' => __('All')],
                ['value' => 'active', 'label' => __('Active')],
                ['value' => 'inactive', 'label' => __('Inactive')],
            ]" />
            @if ($categories->count())
                <div class="min-w-[180px]">
                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Category') }}</label>
                    <select wire:model.live="category_id" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="">{{ __('All') }}</option>
                        @foreach ($categories as $c)
                            <option value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            @if ($suppliers->count())
                <div class="min-w-[180px]">
                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Supplier') }}</label>
                    <select wire:model.live="supplier_id" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="">{{ __('All') }}</option>
                        @foreach ($suppliers as $s)
                            <option value="{{ $s->id }}">{{ $s->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div class="flex items-center gap-2">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Low stock only') }}</label>
                <input type="checkbox" wire:model.live="low_stock_only" class="rounded border-neutral-300" />
            </div>
        </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Code') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Name') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Category') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Current Stock') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Min Stock') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Cost') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                @forelse ($items as $item)
                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $item->item_code ?? '—' }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $item->name }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $item->category?->name ?? '—' }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ number_format((float) ($item->current_stock ?? 0), 3) }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ number_format((float) ($item->minimum_stock ?? 0), 3) }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ number_format((float) ($item->cost_per_unit ?? 0), 3) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No inventory items found.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div>{{ $items->links() }}</div>
</div>
