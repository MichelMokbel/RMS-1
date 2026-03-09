<?php

use App\Models\MealSubscription;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $status = 'all';
    public ?int $customer_id = null;
    public string $customer_search = '';
    public ?int $branch_id = null;
    public ?string $date_from = null;
    public ?string $date_to = null;
    public string $search = '';

    protected $paginationTheme = 'tailwind';

    public function mount(): void
    {
        $this->date_from = now()->startOfMonth()->toDateString();
        $this->date_to = now()->endOfMonth()->toDateString();
    }

    public function updating($name): void
    {
        if (in_array($name, ['status', 'customer_id', 'customer_search', 'branch_id', 'date_from', 'date_to', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function updatedCustomerSearch(): void
    {
        if ($this->customer_id === null) {
            return;
        }

        $selected = Customer::find($this->customer_id);
        $selectedLabel = $selected ? trim($selected->name.' '.($selected->phone ?? '')) : '';
        if (trim($this->customer_search) !== $selectedLabel) {
            $this->customer_id = null;
        }
    }

    public function selectCustomer(int $id): void
    {
        $this->customer_id = $id;
        $c = Customer::find($id);
        $this->customer_search = $c ? trim($c->name.' '.($c->phone ?? '')) : '';
    }

    public function with(): array
    {
        $customers = collect();
        if (Schema::hasTable('customers') && $this->customer_id === null && trim($this->customer_search) !== '') {
            $customers = Customer::query()
                ->search($this->customer_search)
                ->orderBy('name')
                ->limit(25)
                ->get();
        }

        return [
            'subscriptions' => $this->query()->paginate(15),
            'customers' => $customers,
            'branches' => Schema::hasTable('branches') ? DB::table('branches')->where('is_active', 1)->orderBy('name')->get() : collect(),
            'exportParams' => $this->exportParams(),
        ];
    }

    private function query()
    {
        return MealSubscription::query()
            ->with(['customer'])
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->when($this->customer_id, fn ($q) => $q->where('customer_id', $this->customer_id))
            ->when($this->branch_id, fn ($q) => $q->where('branch_id', $this->branch_id))
            ->when($this->date_from, fn ($q) => $q->whereDate('start_date', '>=', $this->date_from))
            ->when($this->date_to, fn ($q) => $q->where(function ($qq) {
                $qq->whereDate('end_date', '<=', $this->date_to)
                   ->orWhereNull('end_date');
            }))
            ->when($this->search, function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(fn ($qq) => $qq->where('subscription_code', 'like', $term)
                    ->orWhereHas('customer', fn ($qc) => $qc->where('name', 'like', $term)));
            })
            ->orderByDesc('created_at');
    }

    public function exportParams(): array
    {
        return array_filter([
            'status' => $this->status !== 'all' ? $this->status : null,
            'customer_id' => $this->customer_id,
            'branch_id' => $this->branch_id,
            'date_from' => $this->date_from,
            'date_to' => $this->date_to,
            'search' => $this->search ?: null,
        ], fn ($v) => $v !== null && $v !== '');
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Subscription Details Report') }}</h1>
        <div class="flex gap-2">
            <flux:button :href="route('reports.index')" wire:navigate variant="ghost">{{ __('Back to Reports') }}</flux:button>
            <flux:button href="{{ route('reports.subscription-details.print') . '?' . http_build_query($exportParams) }}" target="_blank" variant="ghost">{{ __('Print') }}</flux:button>
            <flux:button href="{{ route('reports.subscription-details.csv') . '?' . http_build_query($exportParams) }}" variant="ghost">{{ __('Export CSV') }}</flux:button>
        </div>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="app-filter-grid">
            <div class="min-w-[200px]">
                <flux:input wire:model.live.debounce.300ms="search" :label="__('Search')" placeholder="{{ __('Subscription code / customer') }}" />
            </div>
            <div class="min-w-[220px] relative">
                <flux:input wire:model.live.debounce.300ms="customer_search" :label="__('Customer')" placeholder="{{ __('Search by name/phone/code') }}" />
                @if($customer_id === null && trim($customer_search) !== '')
                    <div class="absolute z-10 mt-1 w-full overflow-hidden rounded-md border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="max-h-64 overflow-auto">
                            @forelse ($customers as $customer)
                                <button type="button" class="w-full px-3 py-2 text-left text-sm text-neutral-800 hover:bg-neutral-50 dark:text-neutral-100 dark:hover:bg-neutral-800/80" wire:click="selectCustomer({{ $customer->id }})">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="font-medium">{{ $customer->name }}</span>
                                        @if($customer->customer_code)
                                            <span class="text-xs text-neutral-500 dark:text-neutral-400">{{ $customer->customer_code }}</span>
                                        @endif
                                    </div>
                                    @if($customer->phone)
                                        <div class="text-xs text-neutral-500 dark:text-neutral-400">{{ $customer->phone }}</div>
                                    @endif
                                </button>
                            @empty
                                <div class="px-3 py-2 text-sm text-neutral-500 dark:text-neutral-400">{{ __('No customers found.') }}</div>
                            @endforelse
                        </div>
                    </div>
                @endif
            </div>
            <div class="w-40">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Branch') }}</label>
                <select wire:model.live="branch_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="">{{ __('All') }}</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="w-40">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Status') }}</label>
                <select wire:model.live="status" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="all">{{ __('All') }}</option>
                    <option value="active">{{ __('Active') }}</option>
                    <option value="paused">{{ __('Paused') }}</option>
                    <option value="cancelled">{{ __('Cancelled') }}</option>
                    <option value="expired">{{ __('Expired') }}</option>
                </select>
            </div>
            <div class="w-40">
                <flux:input wire:model.live="date_from" type="date" :label="__('Start From')" />
            </div>
            <div class="w-40">
                <flux:input wire:model.live="date_to" type="date" :label="__('End To')" />
            </div>
        </div>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Subscription Code') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Customer') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Status') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Start Date') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('End Date') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Order Type') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Plan') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Meals Used') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Created') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    @forelse ($subscriptions as $subscription)
                        <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                            <td class="px-4 py-3 text-sm text-neutral-900 dark:text-neutral-100">
                                <a href="{{ route('subscriptions.show', $subscription) }}" wire:navigate class="hover:underline text-primary-600 dark:text-primary-400">
                                    {{ $subscription->subscription_code }}
                                </a>
                            </td>
                            <td class="px-4 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ $subscription->customer->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-sm">
                                <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold
                                    {{ $subscription->status === 'active' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-100'
                                        : ($subscription->status === 'paused' ? 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-100'
                                        : 'bg-neutral-200 text-neutral-800 dark:bg-neutral-700 dark:text-neutral-100') }}">
                                    {{ ucfirst($subscription->status) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ $subscription->start_date?->format('Y-m-d') ?? '—' }}</td>
                            <td class="px-4 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ $subscription->end_date?->format('Y-m-d') ?? '—' }}</td>
                            <td class="px-4 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ $subscription->default_order_type }}</td>
                            <td class="px-4 py-3 text-sm text-neutral-700 dark:text-neutral-200">
                                @if ($subscription->plan_meals_total)
                                    {{ $subscription->plan_meals_total }} {{ __('meals') }}
                                @else
                                    {{ __('Unlimited') }}
                                @endif
                            </td>
                            <td class="px-4 py-3 text-sm text-neutral-700 dark:text-neutral-200">
                                @if ($subscription->plan_meals_total)
                                    {{ $subscription->meals_used ?? 0 }} / {{ $subscription->plan_meals_total }}
                                @else
                                    {{ $subscription->meals_used ?? 0 }}
                                @endif
                            </td>
                            <td class="px-4 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ $subscription->created_at?->format('Y-m-d') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-8 text-center text-sm text-neutral-600 dark:text-neutral-300">
                                {{ __('No subscriptions found.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="flex justify-center">
        {{ $subscriptions->links() }}
    </div>
</div>
