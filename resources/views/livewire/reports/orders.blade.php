<?php

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $status = 'all';
    public ?string $source = null;
    public ?int $branch_id = null;
    public string $daily_dish_filter = 'all';
    public ?string $scheduled_date = null;
    public ?string $date_from = null;
    public ?string $date_to = null;
    public string $search = '';

    protected $paginationTheme = 'tailwind';

    public function updating($name): void
    {
        if (in_array($name, ['status', 'source', 'branch_id', 'daily_dish_filter', 'scheduled_date', 'date_from', 'date_to', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function with(): array
    {
        return [
            'orders' => $this->query()->paginate(15),
            'branches' => Schema::hasTable('branches') ? DB::table('branches')->where('is_active', 1)->orderBy('name')->get() : collect(),
            'exportParams' => $this->exportParams(),
        ];
    }

    private function query()
    {
        return Order::query()
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->when($this->source, fn ($q) => $q->where('source', $this->source))
            ->when($this->branch_id, fn ($q) => $q->where('branch_id', $this->branch_id))
            ->when($this->daily_dish_filter === 'only', fn ($q) => $q->where('is_daily_dish', 1))
            ->when($this->daily_dish_filter === 'exclude', fn ($q) => $q->where(fn ($qq) => $qq->whereNull('is_daily_dish')->orWhere('is_daily_dish', 0)))
            ->when($this->scheduled_date, fn ($q) => $q->whereDate('scheduled_date', $this->scheduled_date))
            ->when($this->date_from, fn ($q) => $q->whereDate('scheduled_date', '>=', $this->date_from))
            ->when($this->date_to, fn ($q) => $q->whereDate('scheduled_date', '<=', $this->date_to))
            ->when($this->search, function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(fn ($qq) => $qq->where('order_number', 'like', $term)->orWhere('customer_name_snapshot', 'like', $term)->orWhere('customer_phone_snapshot', 'like', $term));
            })
            ->when(Schema::hasTable('meal_plan_request_orders'), function ($q) {
                $q->whereNotExists(function ($sub) {
                    $sub->selectRaw('1')->from('meal_plan_request_orders as mpro')
                        ->join('meal_plan_requests as mpr', 'mpr.id', '=', 'mpro.meal_plan_request_id')
                        ->whereColumn('mpro.order_id', 'orders.id')
                        ->whereNotIn('mpr.status', ['converted', 'closed']);
                });
            })
            ->orderByDesc('scheduled_date')
            ->orderByDesc('id');
    }

    public function exportParams(): array
    {
        return array_filter([
            'status' => $this->status !== 'all' ? $this->status : null,
            'source' => $this->source,
            'branch_id' => $this->branch_id,
            'daily_dish_filter' => $this->daily_dish_filter !== 'all' ? $this->daily_dish_filter : null,
            'scheduled_date' => $this->scheduled_date,
            'date_from' => $this->date_from,
            'date_to' => $this->date_to,
            'search' => $this->search ?: null,
        ], fn ($v) => $v !== null && $v !== '');
    }
}; ?>

<div class="w-full max-w-7xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Orders Report') }}</h1>
        <div class="flex gap-2">
            <flux:button :href="route('reports.index')" wire:navigate variant="ghost">{{ __('Back to Reports') }}</flux:button>
            <flux:button href="{{ route('reports.orders.print') . '?' . http_build_query($exportParams) }}" target="_blank" variant="ghost">{{ __('Print') }}</flux:button>
            <flux:button href="{{ route('reports.orders.csv') . '?' . http_build_query($exportParams) }}" variant="ghost">{{ __('Export CSV') }}</flux:button>
            <flux:button href="{{ route('reports.orders.pdf') . '?' . http_build_query($exportParams) }}" variant="ghost">{{ __('Export PDF') }}</flux:button>
        </div>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="flex flex-wrap items-end gap-3">
            <div class="min-w-[200px]">
                <flux:input wire:model.live.debounce.300ms="search" :label="__('Search')" placeholder="{{ __('Order # / customer') }}" />
            </div>
            <x-reports.branch-select name="branch_id" :branches="$branches" />
            <div class="w-40">
                <flux:input wire:model.live="scheduled_date" type="date" :label="__('Date')" />
            </div>
            <x-reports.date-range fromName="date_from" toName="date_to" />
            <x-reports.status-select name="status" :options="[
                ['value' => 'all', 'label' => __('All')],
                ['value' => 'Draft', 'label' => __('Draft')],
                ['value' => 'Confirmed', 'label' => __('Confirmed')],
                ['value' => 'InProduction', 'label' => __('In Production')],
                ['value' => 'Ready', 'label' => __('Ready')],
                ['value' => 'OutForDelivery', 'label' => __('Out For Delivery')],
                ['value' => 'Delivered', 'label' => __('Delivered')],
                ['value' => 'Cancelled', 'label' => __('Cancelled')],
            ]" />
            <div class="w-40">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Source') }}</label>
                <select wire:model.live="source" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="">{{ __('All') }}</option>
                    <option value="Subscription">{{ __('Subscription') }}</option>
                    <option value="Backoffice">{{ __('Backoffice') }}</option>
                    <option value="POS">{{ __('POS') }}</option>
                    <option value="Phone">{{ __('Phone') }}</option>
                    <option value="WhatsApp">{{ __('WhatsApp') }}</option>
                </select>
            </div>
            <div class="w-48">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Orders') }}</label>
                <select wire:model.live="daily_dish_filter" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="all">{{ __('All') }}</option>
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
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Order #') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Source') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Branch') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Status') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Customer') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Scheduled') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Total') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                @forelse ($orders as $order)
                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $order->order_number }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $order->source }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $order->branch_id }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $order->status }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $order->customer_name_snapshot ?? '—' }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $order->scheduled_date?->format('Y-m-d') }} {{ $order->scheduled_time }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ number_format((float) $order->total_amount, 3) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No orders found.') }}</td>
                    </tr>
                @endforelse
            </tbody>
            @if ($orders->count() > 0)
                <tfoot class="bg-neutral-50 dark:bg-neutral-800/90">
                    <tr>
                        <td colspan="6" class="px-3 py-2 text-right text-sm font-semibold text-neutral-700 dark:text-neutral-200">{{ __('Total') }}</td>
                        <td class="px-3 py-2 text-right text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($orders->getCollection()->sum('total_amount'), 3) }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
    <div>{{ $orders->links() }}</div>
</div>
