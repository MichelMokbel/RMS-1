<?php

use App\Models\InventoryItem;
use App\Services\Inventory\InventoryItemFormQueryService;
use App\Services\Reports\InventoryTransactionsReportQueryService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public ?int $branch_id = null;
    public ?int $item_id = null;
    public ?int $supplier_id = null;
    public ?int $category_id = null;
    public string $transaction_type = 'all';
    public string $reference_type = 'all';
    public ?string $date_from = null;
    public ?string $date_to = null;

    protected $paginationTheme = 'tailwind';

    public function mount(): void
    {
        $this->date_from = now()->startOfMonth()->toDateString();
        $this->date_to = now()->endOfMonth()->toDateString();
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'branch_id', 'item_id', 'supplier_id', 'category_id', 'transaction_type', 'reference_type', 'date_from', 'date_to'], true)) {
            $this->resetPage();
        }
    }

    public function with(InventoryTransactionsReportQueryService $queryService, InventoryItemFormQueryService $formQuery): array
    {
        $filters = $this->filters();

        return [
            'transactions' => $queryService->query($filters)->paginate(20),
            'branches' => $formQuery->branches(),
            'categories' => $formQuery->categories(),
            'suppliers' => $formQuery->suppliers(),
            'items' => InventoryItem::query()->orderBy('name')->get(),
            'exportParams' => $this->exportParams(),
        ];
    }

    private function filters(): array
    {
        return [
            'search' => $this->search,
            'branch_id' => $this->branch_id,
            'item_id' => $this->item_id,
            'supplier_id' => $this->supplier_id,
            'category_id' => $this->category_id,
            'transaction_type' => $this->transaction_type,
            'reference_type' => $this->reference_type,
            'date_from' => $this->date_from,
            'date_to' => $this->date_to,
        ];
    }

    private function exportParams(): array
    {
        return array_filter($this->filters(), fn ($value) => $value !== null && $value !== '');
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Inventory Transactions') }}</h1>
        <div class="flex gap-2">
            <flux:button :href="route('reports.index')" wire:navigate variant="ghost">{{ __('Back to Reports') }}</flux:button>
            <flux:button href="{{ route('reports.inventory-transactions.print') . '?' . http_build_query($exportParams) }}" target="_blank" variant="ghost">{{ __('Print') }}</flux:button>
            <flux:button href="{{ route('reports.inventory-transactions.csv') . '?' . http_build_query($exportParams) }}" variant="ghost">{{ __('Export CSV') }}</flux:button>
            <flux:button href="{{ route('reports.inventory-transactions.pdf') . '?' . http_build_query($exportParams) }}" variant="ghost">{{ __('Export PDF') }}</flux:button>
        </div>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="app-filter-grid">
            <div class="min-w-[200px]">
                <flux:input wire:model.live.debounce.300ms="search" :label="__('Search')" placeholder="{{ __('Item, code, notes, reference') }}" />
            </div>
            <x-reports.date-range fromName="date_from" toName="date_to" />
            <x-reports.branch-select name="branch_id" :branches="$branches" />
            <div class="min-w-[200px]">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Item') }}</label>
                <select wire:model.live="item_id" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="">{{ __('All') }}</option>
                    @foreach ($items as $item)
                        <option value="{{ $item->id }}">{{ $item->item_code }} {{ $item->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="min-w-[180px]">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Supplier') }}</label>
                <select wire:model.live="supplier_id" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="">{{ __('All') }}</option>
                    @foreach ($suppliers as $supplier)
                        <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="min-w-[180px]">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Category') }}</label>
                <select wire:model.live="category_id" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="">{{ __('All') }}</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}">{{ $category->fullName() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="min-w-[160px]">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Type') }}</label>
                <select wire:model.live="transaction_type" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="all">{{ __('All') }}</option>
                    <option value="in">{{ __('In') }}</option>
                    <option value="out">{{ __('Out') }}</option>
                    <option value="adjustment">{{ __('Adjust') }}</option>
                </select>
            </div>
            <div class="min-w-[160px]">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Reference') }}</label>
                <select wire:model.live="reference_type" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="all">{{ __('All') }}</option>
                    <option value="manual">{{ __('Manual') }}</option>
                    <option value="purchase_order">{{ __('Purchase Order') }}</option>
                    <option value="recipe">{{ __('Recipe') }}</option>
                    <option value="transfer">{{ __('Transfer') }}</option>
                </select>
            </div>
        </div>
    </div>

    <div class="app-table-shell">
        <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Date') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Item') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Category') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Type') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Qty') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Unit Cost') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Total Cost') }}</th>
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
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $transaction->item?->categoryLabel() ?? '—' }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ ucfirst($transaction->transaction_type) }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ number_format((float) $transaction->delta(), 3) }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ number_format((float) ($transaction->unit_cost ?? 0), 4) }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ number_format((float) ($transaction->total_cost ?? 0), 4) }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ trim(($transaction->reference_type ?? '').' '.($transaction->reference_id ?? '')) }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $transaction->user?->username ?? $transaction->user?->email ?? '—' }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $transaction->notes ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No inventory transactions found.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>{{ $transactions->links() }}</div>
</div>
