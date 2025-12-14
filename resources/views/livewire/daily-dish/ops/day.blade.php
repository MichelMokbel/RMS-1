<?php

use App\Models\DailyDishMenu;
use App\Models\OpsEvent;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\DailyDish\DailyDishOpsQueryService;
use App\Services\DailyDish\DailyDishMenuService;
use App\Services\Orders\OrderWorkflowService;
use App\Services\Orders\SubscriptionOrderGenerationService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public int $branch = 1;
    public string $date;

    public string $mode = 'ByOrder'; // ByOrder|ByItemTotals

    public bool $includeSubscription = true;
    public bool $includeManual = true;

    public string $search = '';

    public function mount(int $branch, string $date): void
    {
        $this->branch = $branch ?: 1;
        $this->date = $date ?: now()->toDateString();
    }

    private function canManage(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'manager']) ?? false;
    }

    private function canKitchen(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'manager', 'kitchen']) ?? false;
    }

    public function with(DailyDishOpsQueryService $q): array
    {
        $menu = $q->getMenu($this->branch, $this->date);
        $publishedMenu = $q->getPublishedMenu($this->branch, $this->date);

        $statusCounts = $q->getStatusCounts($this->branch, $this->date, ['is_daily_dish' => true]);

        $subscriptionOrders = $q->getOrdersForDay($this->branch, $this->date, [
            'is_daily_dish' => true,
            'include_subscription' => true,
            'include_manual' => false,
            'statuses' => ['Draft', 'Confirmed', 'InProduction', 'Ready', 'OutForDelivery', 'Delivered', 'Cancelled'],
            'search' => $this->search,
        ]);

        $manualOrders = $q->getOrdersForDay($this->branch, $this->date, [
            'is_daily_dish' => true,
            'include_subscription' => false,
            'include_manual' => true,
            'statuses' => ['Draft', 'Confirmed', 'InProduction', 'Ready', 'OutForDelivery', 'Delivered', 'Cancelled'],
            'search' => $this->search,
        ]);

        $workOrders = $q->getOrdersForDay($this->branch, $this->date, [
            'is_daily_dish' => true,
            'include_subscription' => $this->includeSubscription,
            'include_manual' => $this->includeManual,
            'statuses' => ['Confirmed', 'InProduction', 'Ready'],
            'search' => $this->search,
        ]);

        $prepTotals = $q->getPrepTotals($this->branch, $this->date, [
            'statuses' => ['Confirmed', 'InProduction'],
            'include_subscription' => $this->includeSubscription,
            'include_manual' => $this->includeManual,
            'department' => 'DailyDish',
        ]);

        $runs = $q->getRecentSubscriptionRuns($this->branch, $this->date, 8);

        return compact(
            'menu',
            'publishedMenu',
            'statusCounts',
            'subscriptionOrders',
            'manualOrders',
            'workOrders',
            'prepTotals',
            'runs'
        );
    }

    public function publishMenu(DailyDishMenuService $service): void
    {
        if (! $this->canManage()) {
            abort(403);
        }

        $menu = DailyDishMenu::where('branch_id', $this->branch)->whereDate('service_date', $this->date)->first();
        if (! $menu) {
            $this->dispatch('toast', type: 'error', message: __('Create the menu first.'));
            return;
        }

        try {
            $service->publish($menu, (int) auth()->id());
            OpsEvent::create([
                'event_type' => 'menu_published',
                'branch_id' => $this->branch,
                'service_date' => $this->date,
                'actor_user_id' => (int) auth()->id(),
                'metadata_json' => ['menu_id' => $menu->id],
                'created_at' => now(),
            ]);
            $this->dispatch('toast', type: 'success', message: __('Menu published.'));
        } catch (ValidationException $e) {
            $msg = collect($e->errors())->flatten()->first() ?? __('Publish failed.');
            $this->dispatch('toast', type: 'error', message: $msg);
        }
    }

    public function unpublishMenu(DailyDishMenuService $service): void
    {
        if (! $this->canManage()) {
            abort(403);
        }

        $menu = DailyDishMenu::where('branch_id', $this->branch)->whereDate('service_date', $this->date)->first();
        if (! $menu) {
            $this->dispatch('toast', type: 'error', message: __('Menu not found.'));
            return;
        }

        try {
            $service->unpublish($menu, (int) auth()->id());
            OpsEvent::create([
                'event_type' => 'menu_unpublished',
                'branch_id' => $this->branch,
                'service_date' => $this->date,
                'actor_user_id' => (int) auth()->id(),
                'metadata_json' => ['menu_id' => $menu->id],
                'created_at' => now(),
            ]);
            $this->dispatch('toast', type: 'success', message: __('Menu reverted to draft.'));
        } catch (ValidationException $e) {
            $msg = collect($e->errors())->flatten()->first() ?? __('Unpublish failed.');
            $this->dispatch('toast', type: 'error', message: $msg);
        }
    }

    public function dryRunSubscriptions(SubscriptionOrderGenerationService $service): void
    {
        if (! $this->canManage()) {
            abort(403);
        }

        $service->generateForDate($this->date, $this->branch, (int) auth()->id(), dryRun: true);
        $this->dispatch('toast', type: 'success', message: __('Dry run completed.'));
    }

    public function generateSubscriptions(SubscriptionOrderGenerationService $service): void
    {
        if (! $this->canManage()) {
            abort(403);
        }

        $service->generateForDate($this->date, $this->branch, (int) auth()->id(), dryRun: false);
        $this->dispatch('toast', type: 'success', message: __('Generation completed.'));
    }

    public function advanceOrderStatus(int $orderId, string $toStatus): void
    {
        if (! $this->canKitchen()) {
            abort(403);
        }

        try {
            /** @var Order $order */
            $order = Order::findOrFail($orderId);
            app(OrderWorkflowService::class)->advanceOrder($order, $toStatus, (int) auth()->id());
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
            app(OrderWorkflowService::class)->setItemStatus($item, $toStatus, (int) auth()->id());
            $this->dispatch('toast', type: 'success', message: __('Item status updated.'));
        } catch (\Throwable $e) {
            $this->dispatch('toast', type: 'error', message: __('Could not update item.'));
        }
    }
}; ?>

<div class="w-full max-w-7xl mx-auto px-4 space-y-6" wire:poll.12s>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Daily Dish Ops') }}</p>
            <h1 class="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">
                {{ __('Branch') }} {{ $branch }} · {{ $date }}
            </h1>
        </div>
        <div class="flex flex-wrap gap-2">
            <flux:button :href="route('daily-dish.menus.edit', [$branch, $date])" wire:navigate>{{ __('Menu') }}</flux:button>
            <flux:button :href="route('orders.index')" wire:navigate variant="ghost">{{ __('Orders') }}</flux:button>
        </div>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-5">
            <flux:input wire:model.live="branch" type="number" min="1" :label="__('Branch')" class="md:col-span-1" />
            <flux:input wire:model.live="date" type="date" :label="__('Date')" class="md:col-span-1" />
            <div class="md:col-span-3">
                <flux:input wire:model.live.debounce.300ms="search" :label="__('Search')" placeholder="{{ __('Order # or customer') }}" />
            </div>
        </div>

        <div class="mt-4 flex flex-wrap gap-2 text-xs">
            @php
                $confirmed = $statusCounts['Confirmed'] ?? 0;
                $inProd = $statusCounts['InProduction'] ?? 0;
                $ready = $statusCounts['Ready'] ?? 0;
            @endphp
            <span class="inline-flex items-center rounded-full bg-neutral-100 px-2 py-1 text-neutral-800 dark:bg-neutral-800 dark:text-neutral-100">
                {{ __('Confirmed') }}: {{ $confirmed }}
            </span>
            <span class="inline-flex items-center rounded-full bg-neutral-100 px-2 py-1 text-neutral-800 dark:bg-neutral-800 dark:text-neutral-100">
                {{ __('In Production') }}: {{ $inProd }}
            </span>
            <span class="inline-flex items-center rounded-full bg-neutral-100 px-2 py-1 text-neutral-800 dark:bg-neutral-800 dark:text-neutral-100">
                {{ __('Ready') }}: {{ $ready }}
            </span>
        </div>
    </div>

    {{-- Menu contract --}}
    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
        <div class="flex items-center justify-between">
            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Menu Contract') }}</h2>
            <div class="flex gap-2">
                @if($menu)
                    <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold
                        {{ $menu->isDraft() ? 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-100' : ($menu->isPublished() ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-100' : 'bg-neutral-200 text-neutral-800 dark:bg-neutral-700 dark:text-neutral-100') }}">
                        {{ ucfirst($menu->status) }}
                    </span>
                @else
                    <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                        {{ __('No menu') }}
                    </span>
                @endif
            </div>
        </div>

        @if($publishedMenu)
            @php $grouped = $publishedMenu->items->groupBy('role'); @endphp
            <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                @foreach($grouped as $role => $rows)
                    <div class="rounded-md border border-neutral-200 p-3 dark:border-neutral-700">
                        <p class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ ucfirst($role) }}</p>
                        <ul class="mt-2 space-y-1 text-sm text-neutral-700 dark:text-neutral-200">
                            @foreach($rows as $row)
                                <li class="flex justify-between gap-2">
                                    <span>{{ $row->menuItem?->name ?? '—' }}</span>
                                    @if($role === 'addon' && $row->is_required)
                                        <span class="text-[11px] text-neutral-500 dark:text-neutral-300">{{ __('Required') }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        @elseif($menu)
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Menu exists but is not published. Kitchen production should use published menus only.') }}</p>
        @else
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('No menu created for this day/branch.') }}</p>
        @endif

        @if(auth()->user()?->hasAnyRole(['admin','manager']))
            <div class="flex flex-wrap gap-2">
                @if($menu && $menu->isDraft())
                    <flux:button type="button" wire:click="publishMenu" variant="primary">{{ __('Publish') }}</flux:button>
                @elseif($menu && $menu->isPublished())
                    <flux:button type="button" wire:click="unpublishMenu">{{ __('Unpublish') }}</flux:button>
                @endif
                <flux:button :href="route('daily-dish.menus.edit', [$branch, $date])" wire:navigate variant="ghost">{{ __('Edit Menu') }}</flux:button>
            </div>
        @endif
    </div>

    {{-- Subscription generation --}}
    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
        <div class="flex items-center justify-between">
            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Subscriptions') }}</h2>
            @if(auth()->user()?->hasAnyRole(['admin','manager']))
                <div class="flex gap-2">
                    <flux:button type="button" wire:click="dryRunSubscriptions" variant="ghost">{{ __('Dry Run') }}</flux:button>
                    <flux:button type="button" wire:click="generateSubscriptions" variant="primary">{{ __('Generate Now') }}</flux:button>
                </div>
            @endif
        </div>

        @if(! $publishedMenu)
            <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100">
                {{ __('A published menu is required before generating subscription orders.') }}
            </div>
        @endif

        <div class="overflow-x-auto">
            <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                    <tr>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Started') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Status') }}</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Created') }}</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Skipped') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    @forelse($runs as $r)
                        <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $r->started_at?->format('H:i:s') ?? '—' }}</td>
                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $r->status }}</td>
                            <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ $r->created_count }}</td>
                            <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ $r->skipped_existing_count }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ __('No runs yet.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Work mode --}}
    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Kitchen Work') }}</h2>
            <div class="flex flex-wrap gap-2">
                <flux:button type="button" wire:click="$set('mode', 'ByOrder')" :variant="$mode === 'ByOrder' ? 'primary' : 'ghost'">{{ __('By Order') }}</flux:button>
                <flux:button type="button" wire:click="$set('mode', 'ByItemTotals')" :variant="$mode === 'ByItemTotals' ? 'primary' : 'ghost'">{{ __('By Item Totals') }}</flux:button>
                <div class="flex items-center gap-3 pl-2">
                    <flux:checkbox wire:model.live="includeSubscription" :label="__('Subscription')" />
                    <flux:checkbox wire:model.live="includeManual" :label="__('Manual')" />
                </div>
            </div>
        </div>

        @if($mode === 'ByItemTotals')
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
            <p class="text-xs text-neutral-600 dark:text-neutral-300">
                {{ __('Prep totals include Confirmed + InProduction orders only.') }}
            </p>
        @else
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @forelse ($workOrders as $order)
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
                                    <span class="inline-flex items-center rounded-full bg-neutral-100 px-2 py-0.5 dark:bg-neutral-800">{{ $order->source }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="mt-3 space-y-1 text-xs text-neutral-800 dark:text-neutral-100">
                            <div>{{ $order->customer_phone_snapshot ?? '—' }}</div>
                            <div class="line-clamp-2 text-[11px] text-neutral-700 dark:text-neutral-300">
                                {{ $order->delivery_address_snapshot ?? '—' }}
                            </div>
                        </div>

                        <ul class="mt-3 divide-y divide-neutral-200 text-xs dark:divide-neutral-800">
                            @foreach ($order->items as $item)
                                <li class="flex items-center justify-between gap-2 py-1">
                                    <div>
                                        <div class="text-neutral-900 dark:text-neutral-100">{{ $item->description_snapshot }}</div>
                                        <div class="text-[11px] text-neutral-600 dark:text-neutral-300">{{ number_format((float) $item->quantity, 3) }}</div>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <span class="inline-flex items-center rounded-full bg-neutral-100 px-2 py-0.5 text-[10px] font-semibold text-neutral-800 dark:bg-neutral-800 dark:text-neutral-100">
                                            {{ $item->status }}
                                        </span>
                                        @if(auth()->user()?->hasAnyRole(['admin','manager','kitchen']) && ! in_array($order->status, ['Cancelled','Delivered'], true))
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
                            <div class="text-right text-[11px] text-neutral-600 dark:text-neutral-300">
                                {{ __('Items') }}: {{ $order->items->count() }}
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('No work orders.') }}</p>
                @endforelse
            </div>
        @endif
    </div>

    {{-- Orders breakdown (subscription vs manual) --}}
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Subscription Orders') }}</h2>
            </div>
            <div class="space-y-2">
                @forelse($subscriptionOrders as $o)
                    <div class="flex items-center justify-between rounded-md border border-neutral-200 p-3 dark:border-neutral-700">
                        <div>
                            <div class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ $o->order_number }}</div>
                            <div class="text-xs text-neutral-600 dark:text-neutral-300">{{ $o->customer_name_snapshot ?? '—' }} · {{ $o->status }}</div>
                        </div>
                        <flux:button size="xs" :href="route('orders.edit', $o)" wire:navigate variant="ghost">{{ __('View') }}</flux:button>
                    </div>
                @empty
                    <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('No subscription orders.') }}</p>
                @endforelse
            </div>
        </div>

        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Manual Daily Dish Orders') }}</h2>
                <flux:button :href="route('daily-dish.ops.manual.create', [$branch, $date])" wire:navigate variant="primary">
                    {{ __('New Manual Order') }}
                </flux:button>
            </div>
            <div class="space-y-2">
                @forelse($manualOrders as $o)
                    <div class="flex items-center justify-between rounded-md border border-neutral-200 p-3 dark:border-neutral-700">
                        <div>
                            <div class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ $o->order_number }}</div>
                            <div class="text-xs text-neutral-600 dark:text-neutral-300">{{ $o->customer_name_snapshot ?? '—' }} · {{ $o->status }}</div>
                        </div>
                        <flux:button size="xs" :href="route('orders.edit', $o)" wire:navigate>{{ __('Edit') }}</flux:button>
                    </div>
                @empty
                    <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('No manual daily dish orders.') }}</p>
                @endforelse
            </div>
        </div>
    </div>
</div>


