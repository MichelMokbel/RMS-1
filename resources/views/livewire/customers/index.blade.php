<?php

use App\Models\Customer;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $customer_type = 'all';
    public string $status = 'active';

    protected $paginationTheme = 'tailwind';
    protected $queryString = [
        'search' => ['except' => ''],
        'customer_type' => ['except' => 'all'],
        'status' => ['except' => 'active'],
    ];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingCustomerType(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        return [
            'customers' => $this->query()->paginate(15),
        ];
    }

    public function toggleActive(int $id): void
    {
        $customer = Customer::findOrFail($id);
        $customer->update(['is_active' => ! $customer->is_active]);
        session()->flash('status', __('Customer status updated.'));
    }

    private function query()
    {
        return Customer::query()
            ->search($this->search)
            ->when($this->customer_type !== 'all', fn ($q) => $q->where('customer_type', $this->customer_type))
            ->when($this->status !== 'all', fn ($q) => $q->where('is_active', $this->status === 'active'))
            ->orderBy('name');
    }
}; ?>

<div class="w-full max-w-7xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
            {{ __('Customers') }}
        </h1>
        @if(auth()->user()->hasAnyRole(['admin','manager']))
            <div class="flex gap-2">
                <flux:button :href="route('customers.import')" wire:navigate variant="ghost">
                    {{ __('Import') }}
                </flux:button>
                <flux:button :href="route('customers.create')" wire:navigate variant="primary">
                    {{ __('Create Customer') }}
                </flux:button>
            </div>
        @endif
    </div>

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div class="flex flex-1 flex-col gap-3 md:flex-row md:items-center md:gap-4">
            <flux:input
                wire:model.live.debounce.300ms="search"
                placeholder="{{ __('Search name, phone, email, code') }}"
                class="w-full md:max-w-sm"
            />

            <div class="flex items-center gap-2">
                <label for="customer_type" class="text-sm text-neutral-800 dark:text-neutral-200">{{ __('Type') }}</label>
                <select
                    id="customer_type"
                    wire:model.live="customer_type"
                    class="rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50"
                >
                    <option value="all">{{ __('All') }}</option>
                    <option value="retail">{{ __('Retail') }}</option>
                    <option value="corporate">{{ __('Corporate') }}</option>
                    <option value="subscription">{{ __('Subscription') }}</option>
                </select>
            </div>

            <div class="flex items-center gap-2">
                <label for="status" class="text-sm text-neutral-800 dark:text-neutral-200">{{ __('Status') }}</label>
                <select
                    id="status"
                    wire:model.live="status"
                    class="rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50"
                >
                    <option value="active">{{ __('Active') }}</option>
                    <option value="inactive">{{ __('Inactive') }}</option>
                    <option value="all">{{ __('All') }}</option>
                </select>
            </div>
        </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <table class="w-full min-w-full table-fixed divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Code') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Name') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Type') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Phone') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Email') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Credit Limit') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Terms (days)') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Active') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Updated') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 bg-white dark:divide-neutral-800 dark:bg-neutral-900">
                @forelse ($customers as $customer)
                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                        <td class="px-3 py-3 text-sm text-neutral-900 dark:text-neutral-100">{{ $customer->customer_code }}</td>
                        <td class="px-3 py-3 text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ $customer->name }}</td>
                        <td class="px-3 py-3 text-sm">
                            <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold bg-neutral-100 text-neutral-800 dark:bg-neutral-800 dark:text-neutral-100">
                                {{ ucfirst($customer->customer_type) }}
                            </span>
                        </td>
                        <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ $customer->phone }}</td>
                        <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ $customer->email }}</td>
                        <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">
                            {{ rtrim(rtrim(number_format((float) $customer->credit_limit, 3, '.', ''), '0'), '.') }}
                        </td>
                        <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ $customer->credit_terms_days }}</td>
                        <td class="px-3 py-3 text-sm">
                            <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold {{ $customer->is_active ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-100' : 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-100' }}">
                                {{ $customer->is_active ? __('Active') : __('Inactive') }}
                            </span>
                        </td>
                        <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ optional($customer->updated_at)->format('Y-m-d H:i') ?? '—' }}</td>
                        <td class="px-3 py-3 text-sm">
                            <div class="flex flex-wrap gap-2">
                                @if(auth()->user()->hasAnyRole(['admin','manager']))
                                    <flux:button size="xs" :href="route('customers.edit', $customer)" wire:navigate>
                                        {{ __('Edit') }}
                                    </flux:button>
                                    @if ($customer->is_active)
                                        <flux:button size="xs" variant="danger" wire:click="toggleActive({{ $customer->id }})">
                                            {{ __('Deactivate') }}
                                        </flux:button>
                                    @else
                                        <flux:button size="xs" variant="success" wire:click="toggleActive({{ $customer->id }})">
                                            {{ __('Activate') }}
                                        </flux:button>
                                    @endif
                                @else
                                    <span class="text-xs text-neutral-500">{{ __('View only') }}</span>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">
                            {{ __('No customers found.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>
        {{ $customers->links() }}
    </div>
</div>
