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

        $orders = Order::query()
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
            ->orderBy('id')
            ->get();

        return ['orders' => $orders];
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
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Kitchen Orders') }}</h1>
        <div class="flex gap-2">
            <flux:button :href="route('orders.kitchen')" wire:navigate variant="ghost">{{ __('Kanban View') }}</flux:button>
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
                        <span class="inline-flex items-center rounded-full bg-neutral-100 px-2 py-0.5 text-[11px] font-semibold text-neutral-800 dark:bg-neutral-800 dark:text-neutral-100">
                            {{ $order->status }}
                        </span>
                        <div class="flex flex-wrap justify-end gap-1 text-[10px]">
                            <span class="inline-flex items-center rounded-full bg-neutral-100 px-2 py-0.5 dark:bg-neutral-800">{{ $order->type }}</span>
                            <span class="inline-flex items-center rounded-full bg-neutral-100 px-2 py-0.5 dark:bg-neutral-800">{{ $order->source }}</span>
                            @if($order->is_daily_dish)
                                <span class="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-[10px] font-semibold text-blue-800 dark:bg-blue-900 dark:text-blue-100">
                                    DD
                                </span>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="mt-3 space-y-1 text-xs text-neutral-800 dark:text-neutral-100">
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

                <div class="mt-3 grid grid-cols-[1fr_auto] gap-2">
                    <div class="space-y-1">
                        @foreach ($order->items as $item)
                            <div class="flex justify-between text-xs">
                                <span class="text-neutral-900 dark:text-neutral-100">{{ $item->description_snapshot }}</span>
                                <span class="text-[11px] text-neutral-600 dark:text-neutral-300">{{ number_format((float) $item->quantity, 3) }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="mt-3 flex items-center justify-between">
                    @php $locked = in_array($order->status, ['Cancelled','Delivered'], true); @endphp
                    <div class="flex flex-wrap gap-2 text-[11px]">
                        @if (! $locked && $order->status === 'Confirmed')
                            <flux:button size="xs" wire:click="advanceOrderStatus({{ $order->id }}, 'InProduction')">{{ __('Start') }}</flux:button>
                        @elseif (! $locked && $order->status === 'InProduction')
                            <flux:button size="xs" wire:click="advanceOrderStatus({{ $order->id }}, 'Ready')">{{ __('Mark Ready') }}</flux:button>
                        @endif
                    </div>
                    <div class="text-right text-[11px] text-neutral-600 dark:text-neutral-300">
                        {{ __('Items') }}: {{ $order->items->count() }}
                    </div>
                </div>
            </div>
        @empty
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('No orders.') }}</p>
        @endforelse
    </div>
</div>


