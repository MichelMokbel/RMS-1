<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Orders\OrderWorkflowService;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public string $date;
    public ?int $branchId = null;

    public array $statusFilter = ['Confirmed', 'InProduction', 'Ready'];
    public bool $includeDraft = false;
    public bool $includeReady = true;
    public bool $includeCancelled = false;
    public bool $includeDelivered = false;
    public bool $showSubscriptionOrders = true;
    public bool $showManualOrders = true;

    public string $search = '';

    public function mount(): void
    {
        $this->date = now()->toDateString();
    }

    public function with(): array
    {
        $statuses = $this->statusFilter;

        if (! $this->includeDraft) {
            $statuses = array_values(array_diff($statuses, ['Draft']));
        } else {
            $statuses[] = 'Draft';
        }

        if (! $this->includeReady) {
            $statuses = array_values(array_diff($statuses, ['Ready']));
        } else {
            $statuses[] = 'Ready';
        }

        if ($this->includeCancelled) {
            $statuses[] = 'Cancelled';
        }
        if ($this->includeDelivered) {
            $statuses[] = 'Delivered';
        }

        $statuses = array_values(array_unique($statuses));
        if (empty($statuses)) {
            $statuses = ['Confirmed','InProduction','Ready'];
        }

        $query = Order::query()
            ->select([
                'id','order_number','branch_id','source','is_daily_dish','type','status',
                'customer_name_snapshot','customer_phone_snapshot','delivery_address_snapshot',
                'scheduled_date','scheduled_time','notes',
            ])
            ->whereDate('scheduled_date', $this->date)
            ->whereIn('status', $statuses)
            ->when($this->branchId, fn ($q) => $q->where('branch_id', $this->branchId))
            ->when(! $this->showSubscriptionOrders, fn ($q) => $q->where('source', '!=', 'Subscription'))
            ->when(! $this->showManualOrders, fn ($q) => $q->where('source', 'Subscription'))
            ->when($this->search, function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(function ($qq) use ($term) {
                    $qq->where('order_number', 'like', $term)
                        ->orWhere('customer_name_snapshot', 'like', $term)
                        ->orWhere('customer_phone_snapshot', 'like', $term);
                });
            })
            ->with(['items' => function ($q) {
                $q->orderBy('sort_order')->orderBy('id')
                    ->select(['id','order_id','description_snapshot','quantity','status','sort_order']);
            }])
            ->orderByRaw('CASE WHEN scheduled_time IS NULL THEN 1 ELSE 0 END')
            ->orderBy('scheduled_time')
            ->orderBy('id');

        $orders = $query->get();

        $grouped = [
            'Confirmed' => collect(),
            'InProduction' => collect(),
            'Ready' => collect(),
        ];

        foreach ($orders as $order) {
            if (isset($grouped[$order->status])) {
                $grouped[$order->status]->push($order);
            }
        }

        return [
            'columns' => $grouped,
        ];
    }

    private function ensureCanUpdate(): void
    {
        if (! auth()->user()?->hasAnyRole(['admin','manager','kitchen'])) {
            abort(403);
        }
    }

    public function advanceOrderStatus(int $orderId, string $toStatus): void
    {
        $this->ensureCanUpdate();
        try {
            /** @var Order $order */
            $order = Order::findOrFail($orderId);
            app(OrderWorkflowService::class)->advanceOrder($order, $toStatus, (int) auth()->id());
            $this->dispatch('toast', type: 'success', message: __('Order status updated.'));
        } catch (\Throwable $e) {
            $this->dispatch('toast', type: 'error', message: __('Could not update order status.'));
        }
    }

    public function setItemStatus(int $itemId, string $toStatus): void
    {
        $this->ensureCanUpdate();
        try {
            /** @var OrderItem $item */
            $item = OrderItem::findOrFail($itemId);
            app(OrderWorkflowService::class)->setItemStatus($item, $toStatus, (int) auth()->id());
            $this->dispatch('toast', type: 'success', message: __('Item status updated.'));
        } catch (\Throwable $e) {
            $this->dispatch('toast', type: 'error', message: __('Could not update item status.'));
        }
    }
}; ?>

<div class="w-full max-w-7xl mx-auto px-4 space-y-6" wire:poll.15s>
    @php
        $printParams = [
            'date' => $date,
            'branch_id' => $branchId,
            'show_subscription' => $showSubscriptionOrders ? 1 : 0,
            'show_manual' => $showManualOrders ? 1 : 0,
            'include_draft' => $includeDraft ? 1 : 0,
            'include_ready' => $includeReady ? 1 : 0,
            'include_cancelled' => $includeCancelled ? 1 : 0,
            'include_delivered' => $includeDelivered ? 1 : 0,
            'search' => $search,
        ];
    @endphp

    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Kitchen Board') }}</h1>
        <div class="flex gap-2">
            <flux:button :href="route('orders.kitchen.print', $printParams)" target="_blank" variant="ghost">{{ __('Print') }}</flux:button>
            <flux:button :href="route('orders.kitchen.cards')" wire:navigate variant="ghost">{{ __('Cards View') }}</flux:button>
            <flux:button :href="route('orders.index')" wire:navigate variant="ghost">{{ __('Orders') }}</flux:button>
        </div>
    </div>

    <div class="sticky top-0 z-10 rounded-lg border border-neutral-200 bg-white/95 p-4 shadow-sm backdrop-blur dark:border-neutral-700 dark:bg-neutral-900/95 space-y-3">
        <div class="flex flex-wrap items-end gap-3">
            <flux:input wire:model.live="date" type="date" :label="__('Date')" />
            <flux:input wire:model.live="branchId" type="number" :label="__('Branch ID')" class="w-28" />
            <div class="flex flex-col gap-1">
                <span class="text-xs font-semibold text-neutral-700 dark:text-neutral-200">{{ __('Orders') }}</span>
                <div class="flex items-center gap-3">
                    <flux:checkbox wire:model.live="showSubscriptionOrders" :label="__('Subscription')" />
                    <flux:checkbox wire:model.live="showManualOrders" :label="__('Manual')" />
                </div>
            </div>
            <div class="flex flex-col gap-1">
                <span class="text-xs font-semibold text-neutral-700 dark:text-neutral-200">{{ __('Include') }}</span>
                <div class="flex flex-wrap items-center gap-3">
                    <flux:checkbox wire:model.live="includeDraft" :label="__('Draft')" />
                    <flux:checkbox wire:model.live="includeReady" :label="__('Ready')" />
                    <flux:checkbox wire:model.live="includeCancelled" :label="__('Cancelled')" />
                    <flux:checkbox wire:model.live="includeDelivered" :label="__('Delivered')" />
                </div>
            </div>
            <div class="flex-1 min-w-[180px]">
                <flux:input wire:model.live.debounce.300ms="search" :label="__('Search')" placeholder="{{ __('Order # or customer') }}" />
            </div>
        </div>
    </div>

    <div class="grid gap-4 md:grid-cols-3">
        @php
            $columnsConfig = [
                'Confirmed' => ['title' => __('Confirmed'), 'color' => 'border-amber-300 bg-amber-50 dark:border-amber-800 dark:bg-amber-950/40'],
                'InProduction' => ['title' => __('In Production'), 'color' => 'border-sky-300 bg-sky-50 dark:border-sky-800 dark:bg-sky-950/40'],
                'Ready' => ['title' => __('Ready'), 'color' => 'border-emerald-300 bg-emerald-50 dark:border-emerald-800 dark:bg-emerald-950/40'],
            ];
        @endphp

        @foreach (['Confirmed','InProduction','Ready'] as $status)
            @php
                $orders = $columns[$status] ?? collect();
                $conf = $columnsConfig[$status];
            @endphp
            <div class="flex flex-col gap-3 rounded-xl border {{ $conf['color'] }} p-3">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ $conf['title'] }}</span>
                    <span class="text-xs rounded-full bg-neutral-900/5 px-2 py-0.5 text-neutral-700 dark:bg-neutral-100/10 dark:text-neutral-200">{{ $orders->count() }}</span>
                </div>

                <div class="space-y-3">
                    @forelse ($orders as $order)
                        @php
                            $urgent = $order->scheduled_time && $order->status !== 'Ready' && \Carbon\Carbon::parse($order->scheduled_time)->between(now()->subMinutes(5), now()->addMinutes(30));
                        @endphp
                        <div class="rounded-lg border border-neutral-200 bg-white p-3 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 @if($urgent) ring-2 ring-amber-400 dark:ring-amber-500 @endif">
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-semibold text-neutral-900 dark:text-neutral-50">{{ $order->order_number }}</span>
                                        @if($order->is_daily_dish)
                                            <span class="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-[11px] font-semibold text-blue-800 dark:bg-blue-900 dark:text-blue-100">DD</span>
                                        @endif
                                    </div>
                                    <div class="mt-1 flex flex-wrap items-center gap-1 text-[11px] text-neutral-700 dark:text-neutral-300">
                                        <span class="inline-flex items-center rounded-full bg-neutral-100 px-2 py-0.5 dark:bg-neutral-800">{{ $order->type }}</span>
                                        <span class="inline-flex items-center rounded-full bg-neutral-100 px-2 py-0.5 dark:bg-neutral-800">{{ $order->source }}</span>
                                        <span class="inline-flex items-center rounded-full bg-neutral-100 px-2 py-0.5 dark:bg-neutral-800">
                                            {{ $order->scheduled_time ?? __('No time') }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-2 space-y-1 text-xs text-neutral-800 dark:text-neutral-100">
                                <div class="font-semibold">{{ $order->customer_name_snapshot ?? '—' }}</div>
                                <div>{{ $order->customer_phone_snapshot ?? '—' }}</div>
                                @if(in_array($order->type, ['Delivery','Pastry']) || $order->source === 'Subscription')
                                    <div class="line-clamp-2 text-[11px] text-neutral-700 dark:text-neutral-300">
                                        {{ $order->delivery_address_snapshot ?? '—' }}
                                    </div>
                                @endif
                            </div>

                            @if($order->notes)
                                <div class="mt-2 rounded-md bg-neutral-50 p-2 text-[11px] text-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 line-clamp-3">
                                    {!! nl2br(e($order->notes)) !!}
                                </div>
                            @endif

                            <ul class="mt-2 divide-y divide-neutral-200 text-xs dark:divide-neutral-800">
                                @foreach ($order->items as $item)
                                    <li class="flex items-center justify-between gap-2 py-1">
                                        <div>
                                            <div class="text-neutral-900 dark:text-neutral-100">{{ $item->description_snapshot }}</div>
                                            <div class="text-[11px] text-neutral-600 dark:text-neutral-300">
                                                {{ number_format((float) $item->quantity, 3) }}
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <span class="inline-flex items-center rounded-full bg-neutral-100 px-2 py-0.5 text-[10px] font-semibold text-neutral-800 dark:bg-neutral-800 dark:text-neutral-100">
                                                {{ $item->status }}
                                            </span>
                                            @php
                                                $orderLocked = in_array($order->status, ['Cancelled','Delivered'], true);
                                            @endphp
                                            @if (! $orderLocked)
                                                @if ($item->status === 'Pending')
                                                    <flux:button size="xs" wire:click="setItemStatus({{ $item->id }}, 'InProduction')">{{ __('Start') }}</flux:button>
                                                    <flux:button size="xs" variant="ghost" wire:click="setItemStatus({{ $item->id }}, 'Cancelled')">{{ __('Cancel') }}</flux:button>
                                                @elseif ($item->status === 'InProduction')
                                                    <flux:button size="xs" wire:click="setItemStatus({{ $item->id }}, 'Ready')">{{ __('Ready') }}</flux:button>
                                                    <flux:button size="xs" variant="ghost" wire:click="setItemStatus({{ $item->id }}, 'Cancelled')">{{ __('Cancel') }}</flux:button>
                                                @elseif ($item->status === 'Ready')
                                                    <flux:button size="xs" wire:click="setItemStatus({{ $item->id }}, 'Completed')">{{ __('Complete') }}</flux:button>
                                                    <flux:button size="xs" variant="ghost" wire:click="setItemStatus({{ $item->id }}, 'Cancelled')">{{ __('Cancel') }}</flux:button>
                                                @endif
                                            @endif
                                        </div>
                                    </li>
                                @endforeach
                            </ul>

                            <div class="mt-3 flex flex-wrap items-center gap-2">
                                @php $locked = in_array($order->status, ['Cancelled','Delivered'], true); @endphp
                                @if (! $locked && $order->status === 'Confirmed')
                                    <flux:button size="xs" wire:click="advanceOrderStatus({{ $order->id }}, 'InProduction')">{{ __('Start') }}</flux:button>
                                @elseif (! $locked && $order->status === 'InProduction')
                                    <flux:button size="xs" wire:click="advanceOrderStatus({{ $order->id }}, 'Ready')">{{ __('Mark Ready') }}</flux:button>
                                @endif
                                @if (! $locked && auth()->user()?->hasAnyRole(['admin','manager']))
                                    <flux:button size="xs" variant="ghost" wire:click="advanceOrderStatus({{ $order->id }}, 'Cancelled')">{{ __('Cancel') }}</flux:button>
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="text-xs text-neutral-600 dark:text-neutral-300">{{ __('No orders.') }}</p>
                    @endforelse
                </div>
            </div>
        @endforeach
    </div>
</div>
