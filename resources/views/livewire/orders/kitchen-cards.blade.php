<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Orders\OrderWorkflowService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
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
        /** @var \App\Models\User|null $user */
        $user = Auth::user();
        if (! $user || ! $user->hasAnyRole(['admin','manager','kitchen'])) {
            abort(403);
        }
    }

    public function advanceOrderStatus(int $orderId, string $toStatus): void
    {
        $this->ensureCanUpdate();
        try {
            /** @var Order $order */
            $order = Order::findOrFail($orderId);
            app(OrderWorkflowService::class)->advanceOrder($order, $toStatus, (int) Auth::id());
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
            app(OrderWorkflowService::class)->setItemStatus($item, $toStatus, (int) Auth::id());
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
                $mainLines = [];
                $saladLines = [];
                $dessertLines = [];
                $otherLines = [];
                $formatQty = function ($qty) {
                    $qty = (float) $qty;
                    if (abs($qty - round($qty)) < 0.0001) {
                        return (string) (int) round($qty);
                    }
                    return rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
                };
                $pushLine = function (&$bucket, $key, $line) {
                    if (! isset($bucket[$key])) {
                        $bucket[$key] = $line;
                        return;
                    }
                    $bucket[$key]['qty'] += $line['qty'];
                };

                foreach ($order->items as $item) {
                    $description = trim((string) $item->description_snapshot);
                    $qty = (float) $item->quantity;
                    if ($description === '') {
                        continue;
                    }
                    if (preg_match('/^Daily Dish \\(([^)]+)\\)\\s*-\\s*(.+)$/i', $description, $matches)) {
                        $label = trim($matches[1]);
                        $name = trim($matches[2]);

                        if ($label === 'Salad Add-on') {
                            $pushLine($saladLines, strtolower($name), ['name' => $name, 'qty' => $qty]);
                            continue;
                        }
                        if ($label === 'Dessert Add-on') {
                            $pushLine($dessertLines, strtolower($name), ['name' => $name, 'qty' => $qty]);
                            continue;
                        }

                        $portion = $label === 'Half Portion' ? 'Half Portion' : ($label === 'Full Portion' ? 'Full Portion' : 'Plate');
                        $key = strtolower($name.'|'.$portion);
                        $pushLine($mainLines, $key, ['name' => $name, 'portion' => $portion, 'qty' => $qty]);
                        continue;
                    }

                    $pushLine($otherLines, strtolower($description), ['name' => $description, 'qty' => $qty]);
                }

                $mainLines = array_values($mainLines);
                $saladLines = array_values($saladLines);
                $dessertLines = array_values($dessertLines);
                $otherLines = array_values($otherLines);
                $statusClasses = [
                    'Confirmed' => 'bg-neutral-100 text-neutral-700 border-neutral-200 dark:bg-neutral-800 dark:text-neutral-100 dark:border-neutral-700',
                    'InProduction' => 'bg-neutral-100 text-neutral-700 border-neutral-200 dark:bg-neutral-800 dark:text-neutral-100 dark:border-neutral-700',
                    'Ready' => 'bg-neutral-100 text-neutral-700 border-neutral-200 dark:bg-neutral-800 dark:text-neutral-100 dark:border-neutral-700',
                    'Cancelled' => 'bg-neutral-100 text-neutral-700 border-neutral-200 dark:bg-neutral-800 dark:text-neutral-100 dark:border-neutral-700',
                    'Delivered' => 'bg-neutral-100 text-neutral-700 border-neutral-200 dark:bg-neutral-800 dark:text-neutral-100 dark:border-neutral-700',
                    'Draft' => 'bg-neutral-100 text-neutral-700 border-neutral-200 dark:bg-neutral-800 dark:text-neutral-100 dark:border-neutral-700',
                ];
                $statusClass = $statusClasses[$order->status] ?? 'bg-neutral-100 text-neutral-700 border-neutral-200 dark:bg-neutral-800 dark:text-neutral-100 dark:border-neutral-700';
            @endphp
            <div class="flex flex-col gap-3 rounded-[28px] border border-neutral-300 bg-white px-6 py-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 @if($urgent) ring-2 ring-amber-400 dark:ring-amber-500 @endif">
                {{-- Header: Customer info and status tags --}}
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 mb-1">
                            <p class="text-base font-semibold text-neutral-900 dark:text-neutral-50">{{ $order->customer_name_snapshot ?? __('Unknown') }}</p>
                            <span class="text-xs font-medium text-neutral-500 dark:text-neutral-400">{{ $order->order_number }}</span>
                        </div>
                        <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Phone') }}: {{ $order->customer_phone_snapshot ?? __('N/A') }}</p>
                        <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Address') }}: {{ $order->delivery_address_snapshot ?? __('N/A') }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-1.5 justify-end">
                        <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-[11px] font-semibold {{ $statusClass }}">
                            {{ $order->status }}
                        </span>
                        <span class="inline-flex items-center rounded-full border border-neutral-300 px-2.5 py-0.5 text-[11px] font-semibold text-neutral-600 dark:border-neutral-700 dark:text-neutral-300">
                            {{ $order->type }}
                        </span>
                        <span class="inline-flex items-center rounded-full border border-neutral-300 px-2.5 py-0.5 text-[11px] font-semibold text-neutral-600 dark:border-neutral-700 dark:text-neutral-300">
                            {{ $order->source }}
                        </span>
                        @if($order->is_daily_dish)
                            <span class="inline-flex items-center rounded-full border border-blue-200 bg-blue-50 px-2.5 py-0.5 text-[11px] font-semibold text-blue-700 dark:border-blue-600/40 dark:bg-blue-900/20 dark:text-blue-300">DD</span>
                        @endif
                    </div>
                </div>

                {{-- Items list: Simple and clear --}}
                <div class="space-y-1.5">
                    @if (count($mainLines) || count($saladLines) || count($dessertLines))
                        @foreach ($mainLines as $line)
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-neutral-900 dark:text-neutral-100">
                                    <span class="text-neutral-500 dark:text-neutral-400">{{ __('Main Dish') }}:</span>
                                    <span class="ml-1 font-medium">{{ $line['name'] }}</span>
                                    @if($line['portion'] !== 'Plate')
                                        <span class="ml-1 text-xs text-neutral-500 dark:text-neutral-400">({{ $line['portion'] }})</span>
                                    @endif
                                </span>
                                <span class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">x {{ $formatQty($line['qty']) }}</span>
                            </div>
                        @endforeach
                        @foreach ($saladLines as $line)
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-neutral-900 dark:text-neutral-100">
                                    <span class="text-neutral-500 dark:text-neutral-400">{{ __('Salad') }}:</span>
                                    <span class="ml-1 font-medium">{{ $line['name'] }}</span>
                                </span>
                                <span class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">x {{ $formatQty($line['qty']) }}</span>
                            </div>
                        @endforeach
                        @foreach ($dessertLines as $line)
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-neutral-900 dark:text-neutral-100">
                                    <span class="text-neutral-500 dark:text-neutral-400">{{ __('Dessert') }}:</span>
                                    <span class="ml-1 font-medium">{{ $line['name'] }}</span>
                                </span>
                                <span class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">x {{ $formatQty($line['qty']) }}</span>
                            </div>
                        @endforeach
                        @foreach ($otherLines as $line)
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-neutral-900 dark:text-neutral-100">
                                    <span class="text-neutral-500 dark:text-neutral-400">{{ __('Item') }}:</span>
                                    <span class="ml-1 font-medium">{{ $line['name'] }}</span>
                                </span>
                                <span class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">x {{ $formatQty($line['qty']) }}</span>
                            </div>
                        @endforeach
                    @elseif (count($otherLines))
                        @foreach ($otherLines as $line)
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-neutral-900 dark:text-neutral-100">
                                    <span class="text-neutral-500 dark:text-neutral-400">{{ __('Item') }}:</span>
                                    <span class="ml-1 font-medium">{{ $line['name'] }}</span>
                                </span>
                                <span class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">x {{ $formatQty($line['qty']) }}</span>
                            </div>
                        @endforeach
                    @else
                        <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ __('No items.') }}</p>
                    @endif
                </div>

                {{-- Notes --}}
                @if($order->notes)
                    <div class="text-xs text-neutral-600 dark:text-neutral-300 pt-1 border-t border-neutral-200 dark:border-neutral-700">
                        <span class="font-semibold text-neutral-500 dark:text-neutral-400">{{ __('Notes') }}:</span>
                        <span class="ml-1">{!! nl2br(e($order->notes)) !!}</span>
                    </div>
                @endif

                {{-- Action button --}}
                <div class="flex flex-wrap items-center justify-between gap-2 pt-1">
                    <div class="flex flex-wrap items-center gap-2">
                        @php $locked = in_array($order->status, ['Cancelled','Delivered'], true); @endphp
                        @if (! $locked && $order->status === 'Confirmed')
                            <flux:button size="xs" variant="ghost" class="rounded-md border border-neutral-300 bg-neutral-50 px-4 py-1.5 text-xs font-semibold text-neutral-700 shadow-sm hover:bg-neutral-100 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-100 dark:hover:bg-neutral-700" wire:click="advanceOrderStatus({{ $order->id }}, 'InProduction')">{{ __('Start Order') }}</flux:button>
                        @elseif (! $locked && $order->status === 'InProduction')
                            <flux:button size="xs" variant="ghost" class="rounded-md border border-neutral-300 bg-neutral-50 px-4 py-1.5 text-xs font-semibold text-neutral-700 shadow-sm hover:bg-neutral-100 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-100 dark:hover:bg-neutral-700" wire:click="advanceOrderStatus({{ $order->id }}, 'Ready')">{{ __('Mark Ready') }}</flux:button>
                        @elseif (! $locked && $order->status === 'Ready')
                            <flux:button size="xs" variant="ghost" class="rounded-md border border-neutral-300 bg-neutral-50 px-4 py-1.5 text-xs font-semibold text-neutral-700 shadow-sm hover:bg-neutral-100 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-100 dark:hover:bg-neutral-700" wire:click="advanceOrderStatus({{ $order->id }}, 'Delivered')">{{ __('Complete Order') }}</flux:button>
                        @endif
                    </div>
                    @if(auth()->user()?->hasAnyRole(['admin','manager']))
                        <flux:button size="xs" variant="ghost" :href="route('orders.edit', $order)" wire:navigate>{{ __('Edit') }}</flux:button>
                    @endif
                </div>
            </div>
        @empty
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('No orders.') }}</p>
        @endforelse
    </div>
</div>


