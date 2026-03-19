<?php

use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Reports\PurchaseOrderReceivingReportQueryService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public ?int $supplier_id = null;
    public ?int $purchase_order_id = null;
    public ?int $item_id = null;
    public ?int $receiver_id = null;
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
        if (in_array($name, ['search', 'supplier_id', 'purchase_order_id', 'item_id', 'receiver_id', 'date_from', 'date_to'], true)) {
            $this->resetPage();
        }
    }

    public function with(PurchaseOrderReceivingReportQueryService $queryService): array
    {
        $filters = $this->filters();

        return [
            'rows' => $queryService->query($filters)->paginate(20),
            'suppliers' => Supplier::query()->orderBy('name')->get(),
            'purchaseOrders' => PurchaseOrder::query()->orderByDesc('order_date')->orderByDesc('id')->get(),
            'items' => InventoryItem::query()->orderBy('name')->get(),
            'receivers' => User::query()->orderBy('name')->get(),
            'exportParams' => $this->exportParams(),
        ];
    }

    private function filters(): array
    {
        return [
            'search' => $this->search,
            'supplier_id' => $this->supplier_id,
            'purchase_order_id' => $this->purchase_order_id,
            'item_id' => $this->item_id,
            'receiver_id' => $this->receiver_id,
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
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Purchase Order Receiving') }}</h1>
        <div class="flex gap-2">
            <flux:button :href="route('reports.index')" wire:navigate variant="ghost">{{ __('Back to Reports') }}</flux:button>
            <flux:button href="{{ route('reports.purchase-order-receiving.print') . '?' . http_build_query($exportParams) }}" target="_blank" variant="ghost">{{ __('Print') }}</flux:button>
            <flux:button href="{{ route('reports.purchase-order-receiving.csv') . '?' . http_build_query($exportParams) }}" variant="ghost">{{ __('Export CSV') }}</flux:button>
            <flux:button href="{{ route('reports.purchase-order-receiving.pdf') . '?' . http_build_query($exportParams) }}" variant="ghost">{{ __('Export PDF') }}</flux:button>
        </div>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="app-filter-grid">
            <div class="min-w-[200px]">
                <flux:input wire:model.live.debounce.300ms="search" :label="__('Search')" placeholder="{{ __('PO, supplier, item, notes') }}" />
            </div>
            <x-reports.date-range fromName="date_from" toName="date_to" />
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
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('PO') }}</label>
                <select wire:model.live="purchase_order_id" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="">{{ __('All') }}</option>
                    @foreach ($purchaseOrders as $purchaseOrder)
                        <option value="{{ $purchaseOrder->id }}">{{ $purchaseOrder->po_number }}</option>
                    @endforeach
                </select>
            </div>
            <div class="min-w-[180px]">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Item') }}</label>
                <select wire:model.live="item_id" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="">{{ __('All') }}</option>
                    @foreach ($items as $item)
                        <option value="{{ $item->id }}">{{ $item->item_code }} {{ $item->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="min-w-[180px]">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Receiver') }}</label>
                <select wire:model.live="receiver_id" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="">{{ __('All') }}</option>
                    @foreach ($receivers as $receiver)
                        <option value="{{ $receiver->id }}">{{ $receiver->name ?? $receiver->username ?? $receiver->email }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    <div class="app-table-shell">
        <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Received At') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('PO #') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Supplier') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Item') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Qty') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Unit Cost') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Total Cost') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Receiver') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Notes') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                @forelse ($rows as $row)
                    @php
                        $receiving = $row->receiving;
                        $purchaseOrder = $receiving?->purchaseOrder;
                    @endphp
                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $receiving?->received_at?->format('Y-m-d H:i') }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $purchaseOrder?->po_number ?? '—' }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $purchaseOrder?->supplier?->name ?? '—' }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $row->item?->item_code }} {{ $row->item?->name }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ number_format((float) $row->received_quantity, 3) }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ number_format((float) ($row->unit_cost ?? 0), 4) }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ number_format((float) ($row->total_cost ?? 0), 4) }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $receiving?->creator?->username ?? $receiving?->creator?->email ?? '—' }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $receiving?->notes ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No receiving records found.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>{{ $rows->links() }}</div>
</div>
