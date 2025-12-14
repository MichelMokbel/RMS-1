<?php

use App\Models\Order;
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
        $query = Order::query()
            ->whereDate('scheduled_date', $this->service_date)
            ->when($this->branch_id, fn ($q) => $q->where('branch_id', $this->branch_id))
            ->whereIn('status', ['Confirmed','InProduction','Ready'])
            ->where('type', 'Pastry');

        if (! $this->include_subscription) {
            $query->where('source', '!=', 'Subscription');
        }

        return [
            'orders' => $query->orderBy('order_number')->with('items')->get(),
        ];
    }
}; ?>

<div class="w-full max-w-7xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Pastry Board') }}</h1>
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

    <div class="space-y-3">
        @forelse ($orders as $order)
            <div class="rounded-lg border border-neutral-200 bg-white p-3 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ $order->order_number }} · {{ $order->source }} · {{ $order->type }}</p>
                        <p class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ $order->customer_name_snapshot ?? '—' }}</p>
                    </div>
                    <span class="text-xs rounded-full bg-neutral-200 px-2 py-1 text-neutral-800 dark:bg-neutral-700 dark:text-neutral-100">{{ $order->status }}</span>
                </div>
                <ul class="mt-2 divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
                    @foreach ($order->items as $item)
                        <li class="py-1 flex justify-between">
                            <span class="text-neutral-800 dark:text-neutral-100">{{ $item->description_snapshot }}</span>
                            <span class="text-neutral-700 dark:text-neutral-200">{{ $item->quantity }} x {{ number_format((float) $item->unit_price, 3) }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @empty
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('No orders.') }}</p>
        @endforelse
    </div>
</div>

