<?php

use App\Models\Customer;
use App\Models\Order;
use App\Services\AR\ArInvoiceService;
use App\Support\Money\MinorUnits;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public int $branch_id = 0;
    public string $date_from = '';
    public string $date_to = '';
    public string $order_type = 'all';
    public string $search = '';
    public ?int $customer_id = null;
    public string $customer_search = '';

    public function mount(): void
    {
        $this->branch_id = (int) config('inventory.default_branch_id', 1) ?: 1;
        $this->date_from = now()->subDays(30)->toDateString();
        $this->date_to = now()->toDateString();
    }

    public function with(): array
    {
        $orders = Order::query()
            ->with(['customer', 'items'])
            ->whereIn('status', ['Confirmed', 'InProduction', 'Ready', 'OutForDelivery', 'Delivered'])
            ->whereNull('invoiced_at')
            ->whereNotNull('customer_id')
            ->when($this->branch_id > 0, fn ($q) => $q->where('branch_id', $this->branch_id))
            ->when($this->date_from, fn ($q) => $q->whereDate('scheduled_date', '>=', $this->date_from))
            ->when($this->date_to, fn ($q) => $q->whereDate('scheduled_date', '<=', $this->date_to))
            ->when($this->order_type === 'regular', fn ($q) => $q->where('is_daily_dish', false)->where('source', '!=', 'Subscription'))
            ->when($this->order_type === 'daily_dish', fn ($q) => $q->where('is_daily_dish', true)->where('source', '!=', 'Subscription'))
            ->when($this->order_type === 'subscription', fn ($q) => $q->where('source', 'Subscription'))
            ->when($this->customer_id, fn ($q) => $q->where('customer_id', $this->customer_id))
            ->when($this->search, function ($q) {
                $term = '%' . $this->search . '%';
                $q->where(function ($sq) use ($term) {
                    $sq->where('order_number', 'like', $term)
                       ->orWhere('customer_name_snapshot', 'like', $term);
                });
            })
            ->orderBy('scheduled_date', 'desc')
            ->orderBy('id', 'desc')
            ->paginate(50);

        $customers = collect();
        if (Schema::hasTable('customers') && $this->customer_id === null && trim($this->customer_search) !== '') {
            $customers = Customer::query()
                ->search($this->customer_search)
                ->orderBy('name')
                ->limit(25)
                ->get();
        }

        return [
            'orders' => $orders,
            'customers' => $customers,
            'branches' => Schema::hasTable('branches')
                ? DB::table('branches')->where('is_active', 1)->orderBy('name')->get()
                : collect(),
        ];
    }

    public function selectCustomer(int $id): void
    {
        $this->customer_id = $id;
        $c = Customer::find($id);
        $this->customer_search = $c ? $c->name : '';
        $this->resetPage();
    }

    public function clearCustomer(): void
    {
        $this->customer_id = null;
        $this->customer_search = '';
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        $this->resetPage();
    }

    public function updatedOrderType(): void
    {
        $this->resetPage();
    }

    public function updatedBranchId(): void
    {
        $this->resetPage();
    }

    public function createInvoice(int $orderId, ArInvoiceService $service): void
    {
        $order = Order::find($orderId);
        if (! $order) {
            session()->flash('error', __('Order not found.'));
            return;
        }

        if ($order->isInvoiced()) {
            session()->flash('error', __('Order has already been invoiced.'));
            return;
        }

        if (! $order->customer_id) {
            session()->flash('error', __('Order has no customer.'));
            return;
        }

        try {
            $invoice = $service->createFromOrder($order, Auth::id());
            session()->flash('status', __('Invoice created successfully.'));
            $this->redirectRoute('invoices.show', $invoice, navigate: true);
        } catch (\Exception $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function formatMoney(float $amount): string
    {
        $scale = MinorUnits::posScale();
        $cents = MinorUnits::parse((string) $amount, $scale);
        return MinorUnits::format($cents, $scale);
    }
}; ?>

<div class="w-full max-w-7xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Orders to Invoice') }}</h1>
        <div class="flex items-center gap-2">
            <flux:button :href="route('invoices.index')" wire:navigate variant="ghost">{{ __('View Invoices') }}</flux:button>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    @if (session('error'))
        <div class="rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 dark:border-rose-900 dark:bg-rose-950 dark:text-rose-100">
            {{ session('error') }}
        </div>
    @endif

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="flex flex-wrap items-end gap-3">
            <div class="min-w-[180px]">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Branch') }}</label>
                @if ($branches->count())
                    <select wire:model.live="branch_id" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="0">{{ __('All Branches') }}</option>
                        @foreach ($branches as $b)
                            <option value="{{ $b->id }}">{{ $b->name }}</option>
                        @endforeach
                    </select>
                @else
                    <flux:input wire:model.live="branch_id" type="number" />
                @endif
            </div>

            <div class="min-w-[140px]">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Date From') }}</label>
                <input type="date" wire:model.live="date_from" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" />
            </div>

            <div class="min-w-[140px]">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Date To') }}</label>
                <input type="date" wire:model.live="date_to" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" />
            </div>

            <div class="min-w-[180px]">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Order Type') }}</label>
                <select wire:model.live="order_type" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="all">{{ __('All Types') }}</option>
                    <option value="regular">{{ __('Regular Orders') }}</option>
                    <option value="daily_dish">{{ __('Daily Dish') }}</option>
                    <option value="subscription">{{ __('Subscription') }}</option>
                </select>
            </div>

            <div class="min-w-[200px] relative">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Customer') }}</label>
                <div class="relative">
                    <input
                        type="text"
                        wire:model.live.debounce.300ms="customer_search"
                        placeholder="{{ __('Search customer...') }}"
                        class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50"
                    />
                    @if ($customer_id)
                        <button type="button" wire:click="clearCustomer" class="absolute right-2 top-1/2 -translate-y-1/2 text-neutral-400 hover:text-neutral-600">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                        </button>
                    @endif
                </div>
                @if ($customer_id === null && trim($customer_search) !== '')
                    <div class="absolute z-10 mt-1 w-full overflow-hidden rounded-md border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="max-h-64 overflow-auto">
                            @forelse ($customers as $c)
                                <button type="button" class="w-full px-3 py-2 text-left text-sm text-neutral-800 hover:bg-neutral-50 dark:text-neutral-100 dark:hover:bg-neutral-800/80" wire:click="selectCustomer({{ $c->id }})">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="font-medium">{{ $c->name }}</span>
                                        @if ($c->customer_code)
                                            <span class="text-xs text-neutral-500 dark:text-neutral-400">{{ $c->customer_code }}</span>
                                        @endif
                                    </div>
                                </button>
                            @empty
                                <div class="px-3 py-2 text-sm text-neutral-500 dark:text-neutral-400">{{ __('No customers found.') }}</div>
                            @endforelse
                        </div>
                    </div>
                @endif
            </div>

            <div class="min-w-[200px]">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Search') }}</label>
                <flux:input wire:model.live.debounce.300ms="search" placeholder="{{ __('Order # or customer...') }}" />
            </div>
        </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Order #') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Date') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Customer') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Type') }}</th>
                    <th class="px-3 py-2 text-center text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Items') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Total') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Status') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                @forelse ($orders as $order)
                    @php
                        $orderType = $order->source === 'Subscription' ? 'Subscription' : ($order->is_daily_dish ? 'Daily Dish' : 'Regular');
                    @endphp
                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100 font-medium">
                            {{ $order->order_number }}
                        </td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                            {{ $order->scheduled_date?->format('d M Y') }}
                        </td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                            {{ $order->customer?->name ?: $order->customer_name_snapshot ?: '—' }}
                        </td>
                        <td class="px-3 py-2 text-sm">
                            @if ($order->source === 'Subscription')
                                <span class="inline-flex items-center rounded-full bg-purple-100 px-2 py-0.5 text-xs font-medium text-purple-800 dark:bg-purple-900/30 dark:text-purple-300">
                                    {{ __('Subscription') }}
                                </span>
                            @elseif ($order->is_daily_dish)
                                <span class="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800 dark:bg-blue-900/30 dark:text-blue-300">
                                    {{ __('Daily Dish') }}
                                </span>
                            @else
                                <span class="inline-flex items-center rounded-full bg-neutral-100 px-2 py-0.5 text-xs font-medium text-neutral-800 dark:bg-neutral-700 dark:text-neutral-300">
                                    {{ __('Regular') }}
                                </span>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-sm text-center text-neutral-700 dark:text-neutral-200">
                            {{ $order->items->count() }}
                        </td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">
                            {{ $this->formatMoney((float) $order->total_amount) }}
                        </td>
                        <td class="px-3 py-2 text-sm">
                            @php
                                $statusColors = [
                                    'Confirmed' => 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300',
                                    'InProduction' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300',
                                    'Ready' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300',
                                    'OutForDelivery' => 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-300',
                                    'Delivered' => 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300',
                                ];
                            @endphp
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $statusColors[$order->status] ?? 'bg-neutral-100 text-neutral-800' }}">
                                {{ $order->status }}
                            </span>
                        </td>
                        <td class="px-3 py-2 text-sm text-right">
                            <div class="flex justify-end gap-2">
                                <flux:button size="xs" wire:click="createInvoice({{ $order->id }})" wire:loading.attr="disabled">
                                    {{ __('Create Invoice') }}
                                </flux:button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">
                            {{ __('No orders pending invoicing.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($orders->hasPages())
        <div class="mt-4">
            {{ $orders->links() }}
        </div>
    @endif
</div>
