<?php

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $status = 'all';
    public ?int $supplier_id = null;
    public ?string $date_from = null;
    public ?string $date_to = null;

    protected $paginationTheme = 'tailwind';

    public function updatingSearch(): void { $this->resetPage(); }
    public function updatingStatus(): void { $this->resetPage(); }
    public function updatingSupplierId(): void { $this->resetPage(); }
    public function updatingDateFrom(): void { $this->resetPage(); }
    public function updatingDateTo(): void { $this->resetPage(); }

    public function with(): array
    {
        return [
            'orders' => $this->query()->paginate(10),
            'suppliers' => Schema::hasTable('suppliers')
                ? Supplier::orderBy('name')->get()
                : collect(),
        ];
    }

    private function query()
    {
        return PurchaseOrder::query()
            ->with(['supplier', 'creator'])
            ->when($this->search, fn ($q) => $q->where('po_number', 'like', '%'.$this->search.'%'))
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->when($this->supplier_id, fn ($q) => $q->where('supplier_id', $this->supplier_id))
            ->when($this->date_from, fn ($q) => $q->whereDate('order_date', '>=', $this->date_from))
            ->when($this->date_to, fn ($q) => $q->whereDate('order_date', '<=', $this->date_to))
            ->orderByDesc('order_date')
            ->orderByDesc('id');
    }

    public function approve(int $id): void
    {
        $po = PurchaseOrder::findOrFail($id);
        if (! $po->isPending()) {
            $this->addError('status', __('Only pending POs can be approved.'));
            return;
        }
        if (! $po->supplier_id || $po->items()->count() === 0) {
            $this->addError('status', __('Supplier and at least one line are required.'));
            return;
        }
        $po->update(['status' => PurchaseOrder::STATUS_APPROVED]);
        session()->flash('status', __('Purchase order approved.'));
    }

    public function cancel(int $id): void
    {
        $po = PurchaseOrder::with('items')->findOrFail($id);
        if ($po->isReceived()) {
            $this->addError('status', __('Cannot cancel a received PO.'));
            return;
        }
        if ($po->isApproved() && $po->items->sum('received_quantity') > 0) {
            $this->addError('status', __('Cannot cancel after receiving items.'));
            return;
        }
        $po->update(['status' => PurchaseOrder::STATUS_CANCELLED]);
        session()->flash('status', __('Purchase order cancelled.'));
    }
}; ?>

<div class="w-full max-w-7xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
            {{ __('Purchase Orders') }}
        </h1>
        <div class="flex items-center gap-2">
            <flux:button :href="route('purchase-orders.create')" wire:navigate variant="primary">
                {{ __('New PO') }}
            </flux:button>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif
    @error('status')
        <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100">
            {{ $message }}
        </div>
    @enderror

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
            <flux:input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search PO number') }}" />
            <div>
                <label class="block text-sm font-medium text-neutral-800 dark:text-neutral-200 mb-1">{{ __('Supplier') }}</label>
                <select wire:model.live="supplier_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="">{{ __('All') }}</option>
                    @foreach ($suppliers as $supplier)
                        <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-neutral-800 dark:text-neutral-200 mb-1">{{ __('Status') }}</label>
                <select wire:model.live="status" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="all">{{ __('All') }}</option>
                    <option value="draft">{{ __('Draft') }}</option>
                    <option value="pending">{{ __('Pending') }}</option>
                    <option value="approved">{{ __('Approved') }}</option>
                    <option value="received">{{ __('Received') }}</option>
                    <option value="cancelled">{{ __('Cancelled') }}</option>
                </select>
            </div>
            <div class="grid grid-cols-2 gap-2">
                <flux:input wire:model.live="date_from" type="date" :label="__('From')" />
                <flux:input wire:model.live="date_to" type="date" :label="__('To')" />
            </div>
        </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('PO Number') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Supplier') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Order Date') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Expected') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Status') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Total') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Received') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 bg-white dark:divide-neutral-800 dark:bg-neutral-900">
                @forelse ($orders as $order)
                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                        <td class="px-3 py-3 text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ $order->po_number }}</td>
                        <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ $order->supplier->name ?? '—' }}</td>
                        <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ $order->order_date?->format('Y-m-d') }}</td>
                        <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ $order->expected_delivery_date?->format('Y-m-d') }}</td>
                        <td class="px-3 py-3 text-sm">
                            <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold
                                @if($order->status === 'received') bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-100
                                @elseif($order->status === 'approved') bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-100
                                @elseif($order->status === 'pending') bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-100
                                @elseif($order->status === 'draft') bg-neutral-100 text-neutral-800 dark:bg-neutral-800 dark:text-neutral-100
                                @else bg-rose-100 text-rose-800 dark:bg-rose-900 dark:text-rose-100 @endif">
                                {{ ucfirst($order->status) }}
                            </span>
                        </td>
                        <td class="px-3 py-3 text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $order->total_amount, 2) }}</td>
                        <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ $order->received_date?->format('Y-m-d') ?? '—' }}</td>
                        <td class="px-3 py-3 text-sm">
                            <div class="flex flex-wrap gap-2">
                                <flux:button size="xs" :href="route('purchase-orders.show', $order)" wire:navigate>{{ __('View') }}</flux:button>
                                @if($order->canEditLines())
                                    <flux:button size="xs" :href="route('purchase-orders.edit', $order)" wire:navigate>{{ __('Edit') }}</flux:button>
                                @endif
                                @if($order->isPending())
                                    <flux:button size="xs" wire:click="approve({{ $order->id }})">{{ __('Approve') }}</flux:button>
                                @endif
                                @if(! $order->isReceived())
                                    <flux:button size="xs" wire:click="cancel({{ $order->id }})" variant="ghost">{{ __('Cancel') }}</flux:button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">
                            {{ __('No purchase orders found.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>
        {{ $orders->links() }}
    </div>
</div>
