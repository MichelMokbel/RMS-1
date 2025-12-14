<?php

use App\Models\OrderItem;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public string $service_date;
    public ?int $branch_id = null;
    public bool $include_subscription = true;

    public function mount(): void
    {
        $this->service_date = now()->toDateString();
    }

    public function with(): array
    {
        $query = OrderItem::query()
            ->select('order_items.*')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereDate('orders.scheduled_date', $this->service_date)
            ->when($this->branch_id, fn ($q) => $q->where('orders.branch_id', $this->branch_id))
            ->whereIn('orders.status', ['Confirmed','InProduction','Ready']);

        if (! $this->include_subscription) {
            $query->where('orders.source', '!=', 'Subscription');
        }

        $items = $query->with('order')->orderBy('orders.order_number')->orderBy('order_items.sort_order')->get();

        return ['items' => $items];
    }
}; ?>

<div class="w-full max-w-7xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Items Board') }}</h1>
        <flux:button :href="route('orders.index')" wire:navigate variant="ghost">{{ __('Orders') }}</flux:button>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
        <div class="flex flex-wrap items-end gap-3">
            <flux:input wire:model.live="service_date" type="date" :label="__('Service Date')" />
            <flux:input wire:model.live="branch_id" type="number" :label="__('Branch ID')" class="w-24" />
            <div class="flex items-center gap-2 pt-6">
                <flux:checkbox wire:model.live="include_subscription" :label="__('Include Subscription')" />
            </div>
        </div>
    </div>

    <div class="overflow-x-auto rounded-lg border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Order #') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Desc') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Qty') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Status') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                @forelse ($items as $item)
                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $item->order?->order_number }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-800 dark:text-neutral-100">{{ $item->description_snapshot }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-800 dark:text-neutral-100">{{ $item->quantity }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $item->status }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-4 text-center text-sm text-neutral-700 dark:text-neutral-200">{{ __('No items.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

