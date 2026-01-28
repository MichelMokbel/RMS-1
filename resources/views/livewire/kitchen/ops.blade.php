<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\DailyDish\DailyDishOpsQueryService;
use App\Services\Orders\OrderWorkflowService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public int $branch = 1;
    public string $date;

    public string $department = 'All'; // All|DailyDish|Pastry|Other
    public string $mode = 'ByOrder'; // ByOrder|ByItemTotals

    public bool $includeSubscription = true;
    public bool $includeManual = true;

    public string $search = '';

    public function mount(int $branch, string $date): void
    {
        $this->branch = $branch ?: 1;
        $this->date = $date ?: now()->toDateString();
    }

    private function canKitchen(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'manager', 'kitchen']) ?? false;
    }

    public function with(DailyDishOpsQueryService $q): array
    {
        $orders = $q->getOrdersForDay($this->branch, $this->date, [
            'statuses' => ['Confirmed', 'InProduction', 'Ready'],
            'include_subscription' => $this->includeSubscription,
            'include_manual' => $this->includeManual,
            'search' => $this->search,
            'types' => $this->department === 'Pastry' ? ['Pastry'] : null,
        ]);

        if ($this->department === 'DailyDish') {
            $orders = $orders->where('is_daily_dish', true)->values();
        } elseif ($this->department === 'Other') {
            $orders = $orders->where('is_daily_dish', false)->filter(fn ($o) => $o->type !== 'Pastry')->values();
        } elseif ($this->department === 'Pastry') {
            // already filtered above
        }

        $prepTotals = $q->getPrepTotals($this->branch, $this->date, [
            'statuses' => ['Confirmed', 'InProduction'],
            'include_subscription' => $this->includeSubscription,
            'include_manual' => $this->includeManual,
            'department' => $this->department,
        ]);

        return compact('orders', 'prepTotals');
    }

    public function advanceOrderStatus(int $orderId, string $toStatus): void
    {
        if (! $this->canKitchen()) {
            abort(403);
        }

        try {
            /** @var Order $order */
            $order = Order::findOrFail($orderId);
            app(OrderWorkflowService::class)->advanceOrder($order, $toStatus, (int) Illuminate\Support\Facades\Auth::id());
            $this->dispatch('toast', type: 'success', message: __('Order status updated.'));
        } catch (\Throwable $e) {
            $this->dispatch('toast', type: 'error', message: __('Could not update order.'));
        }
    }

    public function setItemStatus(int $itemId, string $toStatus): void
    {
        if (! $this->canKitchen()) {
            abort(403);
        }

        try {
            /** @var OrderItem $item */
            $item = OrderItem::findOrFail($itemId);
            app(OrderWorkflowService::class)->setItemStatus($item, $toStatus, (int) Illuminate\Support\Facades\Auth::id());
            $this->dispatch('toast', type: 'success', message: __('Item status updated.'));
        } catch (\Throwable $e) {
            $this->dispatch('toast', type: 'error', message: __('Could not update item.'));
        }
    }
}; ?>

<div class="w-full max-w-7xl mx-auto px-4 space-y-6" wire:poll.12s>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Kitchen Ops') }}</p>
            <h1 class="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">
                {{ __('Branch') }} {{ $branch }} · {{ $date }}
            </h1>
        </div>
        <div class="flex flex-wrap gap-2">
            <flux:button :href="route('daily-dish.ops.day', [$branch, $date])" wire:navigate variant="ghost">{{ __('Daily Dish Ops') }}</flux:button>
            <flux:button :href="route('orders.index')" wire:navigate variant="ghost">{{ __('Orders') }}</flux:button>
        </div>
    </div>

    <div class="sticky top-0 z-10 rounded-lg border border-neutral-200 bg-white/95 p-4 shadow-sm backdrop-blur dark:border-neutral-700 dark:bg-neutral-900/95 space-y-3">
        <div class="flex flex-wrap items-end gap-3">
            <flux:input wire:model.live="branch" type="number" min="1" :label="__('Branch')" class="w-28" />
            <flux:input wire:model.live="date" type="date" :label="__('Date')" />
            <div>
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Department') }}</label>
                <select wire:model.live="department" class="rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="All">{{ __('All') }}</option>
                    <option value="DailyDish">{{ __('Daily Dish') }}</option>
                    <option value="Pastry">{{ __('Pastry') }}</option>
                    <option value="Other">{{ __('Other') }}</option>
                </select>
            </div>
            <div class="flex items-center gap-3 pt-6">
                <flux:checkbox wire:model.live="includeSubscription" :label="__('Subscription')" />
                <flux:checkbox wire:model.live="includeManual" :label="__('Manual')" />
            </div>
            <div class="flex-1 min-w-[180px]">
                <flux:input wire:model.live.debounce.300ms="search" :label="__('Search')" placeholder="{{ __('Order # or customer') }}" />
            </div>
            <div class="flex gap-2 pt-6">
                <flux:button type="button" wire:click="$set('mode','ByOrder')" :variant="$mode==='ByOrder' ? 'primary' : 'ghost'">{{ __('By Order') }}</flux:button>
                <flux:button type="button" wire:click="$set('mode','ByItemTotals')" :variant="$mode==='ByItemTotals' ? 'primary' : 'ghost'">{{ __('By Item Totals') }}</flux:button>
            </div>
        </div>
    </div>

    @if($mode === 'ByItemTotals')
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-2">
            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Prep Totals') }}</h2>
            <div class="overflow-x-auto rounded-lg border border-neutral-200 dark:border-neutral-700">
                <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                    <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Role') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Item') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Total Qty') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        @forelse($prepTotals as $row)
                            <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $row->role ?? '—' }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $row->description_snapshot }}</td>
                                <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ number_format((float) $row->total_quantity, 3) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ __('No items to prep.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="text-xs text-neutral-600 dark:text-neutral-300">{{ __('Prep totals include Confirmed + InProduction orders only.') }}</p>
        </div>
    @else
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @forelse ($orders as $order)
                @php
                    $urgent = $order->scheduled_time && ! in_array($order->status, ['Ready','Delivered','Cancelled'], true)
                        && \Carbon\Carbon::parse($order->scheduled_time)->between(now()->subMinutes(5), now()->addMinutes(30));
                @endphp
                <div class="flex flex-col justify-between rounded-xl border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 @if($urgent) ring-2 ring-amber-400 dark:ring-amber-500 @endif">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-sm font-semibold text-neutral-900 dark:text-neutral-50">{{ $order->customer_name_snapshot ?? '—' }}</p>
                            <p class="text-xs text-neutral-600 dark:text-neutral-300">{{ $order->order_number }}</p>
                        </div>
                        <div class="text-right space-y-1">
                            <p class="text-xs text-neutral-600 dark:text-neutral-300">{{ $order->scheduled_time ?? __('No time') }}</p>
                            <span class="inline-flex items-center rounded-full bg-neutral-100 px-2 py-0.5 text-[11px] font-semibold text-neutral-800 dark:bg-neutral-800 dark:text-neutral-100">{{ $order->status }}</span>
                            <div class="flex flex-wrap justify-end gap-1 text-[10px]">
                                <span class="inline-flex items-center rounded-full bg-neutral-100 px-2 py-0.5 dark:bg-neutral-800">{{ $order->type }}</span>
                                <span class="inline-flex items-center rounded-full bg-neutral-100 px-2 py-0.5 dark:bg-neutral-800">{{ $order->source }}</span>
                                @if($order->is_daily_dish)
                                    <span class="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-[10px] font-semibold text-blue-800 dark:bg-blue-900 dark:text-blue-100">DD</span>
                                @endif
                            </div>
                        </div>
                    </div>

                    <div class="mt-3 space-y-1 text-xs text-neutral-800 dark:text-neutral-100">
                        <div>{{ $order->customer_phone_snapshot ?? '—' }}</div>
                        <div class="line-clamp-2 text-[11px] text-neutral-700 dark:text-neutral-300">{{ $order->delivery_address_snapshot ?? '—' }}</div>
                    </div>

                    <ul class="mt-3 divide-y divide-neutral-200 text-xs dark:divide-neutral-800">
                        @foreach ($order->items as $item)
                            <li class="flex items-center justify-between gap-2 py-1">
                                <div>
                                    <div class="text-neutral-900 dark:text-neutral-100">{{ $item->description_snapshot }}</div>
                                    <div class="text-[11px] text-neutral-600 dark:text-neutral-300">{{ number_format((float) $item->quantity, 3) }}</div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="inline-flex items-center rounded-full bg-neutral-100 px-2 py-0.5 text-[10px] font-semibold text-neutral-800 dark:bg-neutral-800 dark:text-neutral-100">{{ $item->status }}</span>
                                    @if(! in_array($order->status, ['Cancelled','Delivered'], true))
                                        @if ($item->status === 'Pending')
                                            <flux:button size="xs" wire:click="setItemStatus({{ $item->id }}, 'InProduction')">{{ __('Start') }}</flux:button>
                                        @elseif ($item->status === 'InProduction')
                                            <flux:button size="xs" wire:click="setItemStatus({{ $item->id }}, 'Ready')">{{ __('Ready') }}</flux:button>
                                        @elseif ($item->status === 'Ready')
                                            <flux:button size="xs" wire:click="setItemStatus({{ $item->id }}, 'Completed')">{{ __('Complete') }}</flux:button>
                                        @endif
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>

                    <div class="mt-3 flex items-center justify-between">
                        @php $locked = in_array($order->status, ['Cancelled','Delivered'], true); @endphp
                        <div class="flex flex-wrap gap-2 text-[11px]">
                            @if (! $locked && $order->status === 'Confirmed')
                                <flux:button size="xs" wire:click="advanceOrderStatus({{ $order->id }}, 'InProduction')">{{ __('Start Order') }}</flux:button>
                            @elseif (! $locked && $order->status === 'InProduction')
                                <flux:button size="xs" wire:click="advanceOrderStatus({{ $order->id }}, 'Ready')">{{ __('Mark Order Ready') }}</flux:button>
                            @endif
                        </div>
                        <div class="text-right text-[11px] text-neutral-600 dark:text-neutral-300">{{ __('Items') }}: {{ $order->items->count() }}</div>
                    </div>
                </div>
            @empty
                <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('No orders.') }}</p>
            @endforelse
        </div>
    @endif
</div>


