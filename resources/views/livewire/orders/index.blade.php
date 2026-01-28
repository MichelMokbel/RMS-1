<?php

use App\Models\Order;
use App\Services\Orders\OrderWorkflowService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $status = 'all';
    public ?string $source = null;
    public ?int $branch_id = null;
    public string $daily_dish_filter = 'all';
    public ?string $scheduled_date = null;
    public string $search = '';

    protected $paginationTheme = 'tailwind';

    public function updating($field): void
    {
        if (in_array($field, ['status','source','branch_id','daily_dish_filter','scheduled_date','search'], true)) {
            $this->resetPage();
        }
    }

    public function with(): array
    {
        return [
            'orders' => $this->query()->paginate(15),
        ];
    }

    public function confirmOrder(int $orderId): void
    {
        $order = Order::find($orderId);
        if (! $order) {
            return;
        }

        if ($order->status !== 'Draft') {
            return;
        }

        try {
            $actorId = Auth::id();
            if (! $actorId) {
                throw ValidationException::withMessages(['auth' => __('Authentication required.')]);
            }
            app(OrderWorkflowService::class)->advanceOrder($order, 'Confirmed', $actorId);
            session()->flash('status_message', __('Order confirmed.'));
        } catch (ValidationException $e) {
            session()->flash('error_message', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);
            session()->flash('error_message', __('Failed to confirm order.'));
        }
    }

    private function query()
    {
        return Order::query()
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->when($this->source, fn ($q) => $q->where('source', $this->source))
            ->when($this->branch_id, fn ($q) => $q->where('branch_id', $this->branch_id))
            ->when($this->daily_dish_filter === 'only', fn ($q) => $q->where('is_daily_dish', 1))
            ->when($this->daily_dish_filter === 'exclude', function ($q) {
                $q->where(function ($qq) {
                    $qq->whereNull('is_daily_dish')
                        ->orWhere('is_daily_dish', 0);
                });
            })
            ->when($this->scheduled_date, fn ($q) => $q->whereDate('scheduled_date', $this->scheduled_date))
            ->when($this->search, function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(function ($qq) use ($term) {
                    $qq->where('order_number', 'like', $term)
                       ->orWhere('customer_name_snapshot', 'like', $term)
                       ->orWhere('customer_phone_snapshot', 'like', $term);
                });
            })
            ->when(Schema::hasTable('meal_plan_request_orders'), function ($q) {
                $q->whereNotExists(function ($sub) {
                    $sub->selectRaw('1')
                        ->from('meal_plan_request_orders as mpro')
                        ->join('meal_plan_requests as mpr', 'mpr.id', '=', 'mpro.meal_plan_request_id')
                        ->whereColumn('mpro.order_id', 'orders.id')
                        ->whereNotIn('mpr.status', ['converted', 'closed']);
                });
            })
            ->when(! Schema::hasTable('meal_plan_request_orders') && Schema::hasTable('meal_plan_requests'), function ($q) {
                $q->whereRaw(
                    "NOT EXISTS (
                        SELECT 1
                        FROM meal_plan_requests mpr
                        WHERE mpr.status NOT IN ('converted', 'closed')
                          AND mpr.order_ids IS NOT NULL
                          AND JSON_CONTAINS(mpr.order_ids, JSON_ARRAY(orders.id))
                    )"
                );
            })
            ->orderByDesc('created_at');
    }
}; ?>

<div class="w-full max-w-7xl mx-auto px-4 space-y-6">
    @php
        $printParams = array_filter([
            'status' => $status,
            'source' => $source,
            'branch_id' => $branch_id,
            'daily_dish_filter' => $daily_dish_filter,
            'scheduled_date' => $scheduled_date,
            'search' => $search,
        ], fn ($v) => $v !== null && $v !== '');
    @endphp

    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Orders') }}</h1>
        <div class="flex gap-2">
            <flux:button :href="route('orders.create')" wire:navigate variant="primary">{{ __('New Order') }}</flux:button>
            <flux:button :href="route('orders.kitchen')" wire:navigate variant="ghost">{{ __('Kitchen View') }}</flux:button>
            <flux:button :href="route('orders.print', $printParams)" target="_blank" variant="ghost">{{ __('Print Report') }}</flux:button>
            <flux:button :href="route('orders.print.invoices', $printParams)" target="_blank" variant="ghost">{{ __('Print Invoices') }}</flux:button>
            <!-- <flux:button :href="route('orders.daily-dish')" wire:navigate variant="ghost">{{ __('Daily Dish') }}</flux:button>
            <flux:button :href="route('daily-dish.menus.index')" wire:navigate variant="ghost">{{ __('Daily Dish Menus') }}</flux:button>
            <flux:button :href="route('subscriptions.generate')" wire:navigate variant="ghost">{{ __('Generate Subscriptions') }}</flux:button> -->
        </div>
    </div>

    @if (session('status_message'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status_message') }}
        </div>
    @endif
    @if (session('error_message'))
        <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-100">
            {{ session('error_message') }}
        </div>
    @endif

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
        <div class="flex flex-wrap items-end gap-3">
            <div class="min-w-[220px] flex-1">
                <flux:input wire:model.live.debounce.300ms="search" :label="__('Search')" placeholder="{{ __('Search order number / customer') }}" />
            </div>
            <div class="w-40">
                <flux:input wire:model.live="scheduled_date" type="date" :label="__('Date')" />
            </div>
            <div class="w-40">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Status') }}</label>
                <select wire:model.live="status" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="all">{{ __('All') }}</option>
                    <option value="Draft">{{ __('Draft') }}</option>
                    <option value="Confirmed">{{ __('Confirmed') }}</option>
                    <option value="InProduction">{{ __('In Production') }}</option>
                    <option value="Ready">{{ __('Ready') }}</option>
                    <option value="OutForDelivery">{{ __('Out For Delivery') }}</option>
                    <option value="Delivered">{{ __('Delivered') }}</option>
                    <option value="Cancelled">{{ __('Cancelled') }}</option>
                </select>
            </div>
            <div class="w-40">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Source') }}</label>
                <select wire:model.live="source" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="">{{ __('All') }}</option>
                    <option value="Subscription">{{ __('Subscription') }}</option>
                    <option value="Backoffice">{{ __('Backoffice') }}</option>
                    <option value="POS">{{ __('POS') }}</option>
                    <option value="Phone">{{ __('Phone') }}</option>
                    <option value="WhatsApp">{{ __('WhatsApp') }}</option>
                </select>
            </div>
            <div class="w-28">
                <flux:input wire:model.live="branch_id" type="number" :label="__('Branch')" />
            </div>
            <div class="w-48">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Orders') }}</label>
                <select wire:model.live="daily_dish_filter" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="all">{{ __('All orders') }}</option>
                    <option value="exclude">{{ __('Hide Daily Dish') }}</option>
                    <option value="only">{{ __('Daily Dish only') }}</option>
                </select>
            </div>
        </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Order #') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Source') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Branch') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Type') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Status') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Customer') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Scheduled') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Total') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                @forelse ($orders as $order)
                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $order->order_number }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                            {{ $order->source }} @if($order->is_daily_dish) <span class="ml-2 inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-[11px] font-semibold text-blue-800 dark:bg-blue-900 dark:text-blue-100">DD</span> @endif
                        </td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $order->branch_id }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $order->type }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $order->status }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $order->customer_name_snapshot ?? '—' }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $order->scheduled_date?->format('Y-m-d') }} {{ $order->scheduled_time }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ number_format((float) $order->total_amount, 3) }}</td>
                        <td class="px-3 py-2 text-sm text-right">
                            <div class="flex flex-wrap justify-end gap-2">
                                @if ($order->status === 'Draft')
                                    <flux:button
                                        size="xs"
                                        type="button"
                                        variant="primary"
                                        wire:click="confirmOrder({{ $order->id }})"
                                        wire:loading.attr="disabled"
                                    >{{ __('Confirm') }}</flux:button>
                                @endif
                                <flux:button size="xs" :href="route('orders.edit', $order)" wire:navigate>{{ __('Edit') }}</flux:button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">
                            {{ __('No orders found.') }}
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
