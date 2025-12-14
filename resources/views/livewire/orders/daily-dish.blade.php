<?php

use App\Models\DailyDishMenu;
use App\Models\Order;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public string $service_date;
    public int $branch_id = 1;

    public function mount(): void
    {
        $this->service_date = now()->toDateString();
    }

    public function with(): array
    {
        $menu = DailyDishMenu::with(['items.menuItem'])
            ->where('branch_id', $this->branch_id)
            ->whereDate('service_date', $this->service_date)
            ->where('status', 'published')
            ->first();

        $subscriptionOrders = Order::query()
            ->whereDate('scheduled_date', $this->service_date)
            ->where('branch_id', $this->branch_id)
            ->where('is_daily_dish', 1)
            ->where('source', 'Subscription')
            ->orderBy('order_number')
            ->get();

        $manualOrders = Order::query()
            ->whereDate('scheduled_date', $this->service_date)
            ->where('branch_id', $this->branch_id)
            ->where('is_daily_dish', 1)
            ->where('source', '!=', 'Subscription')
            ->orderBy('order_number')
            ->get();

        return [
            'menu' => $menu,
            'subscriptionOrders' => $subscriptionOrders,
            'manualOrders' => $manualOrders,
        ];
    }
}; ?>

<div class="w-full max-w-6xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Daily Dish Orders') }}</h1>
        <div class="flex gap-2">
            <flux:button :href="route('daily-dish.menus.index')" wire:navigate>{{ __('Daily Dish Menus') }}</flux:button>
            <flux:button :href="route('subscriptions.generate')" wire:navigate>{{ __('Generate Subscriptions') }}</flux:button>
            <flux:button :href="route('orders.create', ['daily_dish' => 1, 'branch' => $branch_id, 'date' => $service_date])" wire:navigate variant="primary">{{ __('Create Manual Daily Dish Order') }}</flux:button>
        </div>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Service Date') }}</label>
                <flux:input wire:model.live="service_date" type="date" />
            </div>
            <div>
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Branch ID') }}</label>
                <flux:input wire:model.live="branch_id" type="number" class="w-24" />
            </div>
        </div>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-2">
        <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Daily Dish Menu') }}</h2>
        @if($menu)
            @php $grouped = $menu->items->groupBy('role'); @endphp
            @foreach($grouped as $role => $rows)
                <div class="space-y-1">
                    <p class="text-sm font-semibold text-neutral-800 dark:text-neutral-100">{{ ucfirst($role) }}</p>
                    <ul class="list-disc pl-5 text-sm text-neutral-700 dark:text-neutral-200">
                        @foreach($rows as $row)
                            <li>{{ $row->menuItem?->name ?? '—' }} ({{ $row->menuItem?->selling_price_per_unit }})</li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        @else
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('No published menu for this date/branch.') }}</p>
        @endif
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-2">
        <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Generated Subscription Orders') }}</h2>
        @if($subscriptionOrders->count())
            <div class="overflow-x-auto">
                <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                    <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Order #') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Customer') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Status') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Total') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        @foreach($subscriptionOrders as $o)
                            <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                                <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $o->order_number }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $o->customer_name_snapshot ?? '—' }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $o->status }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ number_format((float) $o->total_amount, 3) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('No subscription orders.') }}</p>
        @endif
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-2">
        <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Manual Daily Dish Orders') }}</h2>
        @if($manualOrders->count())
            <div class="overflow-x-auto">
                <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                    <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Order #') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Customer') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Status') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Total') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        @foreach($manualOrders as $o)
                            <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                                <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $o->order_number }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $o->customer_name_snapshot ?? '—' }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $o->status }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ number_format((float) $o->total_amount, 3) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('No manual daily dish orders.') }}</p>
        @endif
    </div>
</div>

