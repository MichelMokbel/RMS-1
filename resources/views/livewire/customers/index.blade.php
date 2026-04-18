<?php

use App\Models\Customer;
use App\Services\Customers\CustomerMergeService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $customer_type = 'all';
    public string $status = 'active';

    // Merge state
    public bool   $showMergeModal  = false;
    public ?int   $mergeSourceId   = null;
    public string $mergeTargetSearch = '';
    public ?int   $mergeTargetId   = null;
    public array  $mergeSummary    = [];

    protected $paginationTheme = 'tailwind';
    protected $queryString = [
        'search'        => ['except' => ''],
        'customer_type' => ['except' => 'all'],
        'status'        => ['except' => 'active'],
    ];

    public function updatingSearch(): void        { $this->resetPage(); }
    public function updatingCustomerType(): void  { $this->resetPage(); }
    public function updatingStatus(): void        { $this->resetPage(); }

    public function with(): array
    {
        return [
            'customers' => $this->query()->paginate(15),
        ];
    }

    // ── Merge ──────────────────────────────────────────────────────────────

    public function openMerge(int $sourceId, CustomerMergeService $service): void
    {
        $this->resetErrorBag();
        $this->mergeSourceId     = $sourceId;
        $this->mergeTargetId     = null;
        $this->mergeTargetSearch = '';
        $this->mergeSummary      = $service->summary(Customer::findOrFail($sourceId));
        $this->showMergeModal    = true;
    }

    public function closeMerge(): void
    {
        $this->showMergeModal    = false;
        $this->mergeSourceId     = null;
        $this->mergeTargetId     = null;
        $this->mergeTargetSearch = '';
        $this->mergeSummary      = [];
        $this->resetErrorBag();
    }

    public function mergeTargetResults(): array
    {
        if (strlen(trim($this->mergeTargetSearch)) < 2) {
            return [];
        }

        return Customer::query()
            ->where('id', '!=', $this->mergeSourceId)
            ->where('is_active', true)
            ->where(function ($q) {
                $term = '%'.trim($this->mergeTargetSearch).'%';
                $q->where('name', 'like', $term)
                  ->orWhere('customer_code', 'like', $term)
                  ->orWhere('phone', 'like', $term);
            })
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name', 'customer_code', 'phone'])
            ->toArray();
    }

    public function selectMergeTarget(int $targetId): void
    {
        $this->mergeTargetId     = $targetId;
        $this->mergeTargetSearch = '';
    }

    public function confirmMerge(CustomerMergeService $service): void
    {
        if (! Auth::user()?->hasRole('admin')) {
            abort(403);
        }

        $this->resetErrorBag();

        if (! $this->mergeSourceId || ! $this->mergeTargetId) {
            $this->addError('merge', __('Please select a target customer.'));
            return;
        }

        try {
            $source = Customer::findOrFail($this->mergeSourceId);
            $target = Customer::findOrFail($this->mergeTargetId);
            $service->merge($source, $target, Auth::id());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->addError('merge', $message);
                }
            }
            return;
        }

        $this->closeMerge();
        session()->flash('status', __('Customers merged successfully.'));
    }

    // ── Toggle active ──────────────────────────────────────────────────────

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

<div class="app-page space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
            {{ __('Customers') }}
        </h1>
        @if(auth()->user()?->hasAnyRole(['admin','manager']))
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

    <div class="app-table-shell">
        <table class="w-full min-w-full table-fixed divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Code') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Name') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Type') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Phone') }}</th>
                    <th class="w-56 px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Email') }}</th>
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
                        <td class="w-56 px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">
                            <div class="truncate" title="{{ $customer->email }}">{{ $customer->email ?: '—' }}</div>
                        </td>
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
                                @if(auth()->user()?->hasAnyRole(['admin','manager']))
                                    <flux:button size="xs" :href="route('customers.edit', $customer)" wire:navigate>
                                        {{ __('Edit') }}
                                    </flux:button>
                                    @if(auth()->user()?->hasRole('admin'))
                                        <flux:button size="xs" variant="ghost" wire:click="openMerge({{ $customer->id }})">
                                            {{ __('Merge') }}
                                        </flux:button>
                                    @endif
                                    @if ($customer->is_active)
                                        <flux:button size="xs" variant="danger" wire:click="toggleActive({{ $customer->id }})">
                                            {{ __('Deactivate') }}
                                        </flux:button>
                                    @else
                                        <flux:button size="xs" variant="primary" color="emerald" wire:click="toggleActive({{ $customer->id }})">
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

    {{-- Merge Modal --}}
    @if ($showMergeModal && $mergeSourceId)
        @php $sourceCustomer = \App\Models\Customer::find($mergeSourceId); @endphp
        <div
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            wire:click.self="closeMerge"
        >
            <div class="w-full max-w-lg rounded-xl bg-white shadow-2xl dark:bg-neutral-900">
                <div class="border-b border-neutral-200 px-6 py-4 dark:border-neutral-700">
                    <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Merge Customer') }}</h2>
                    <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                        {{ __('All records from the duplicate will be moved to the target. The duplicate will be deactivated.') }}
                    </p>
                </div>

                <div class="space-y-5 px-6 py-5">

                    {{-- Source customer --}}
                    <div>
                        <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-neutral-500">{{ __('Duplicate (will be deactivated)') }}</p>
                        <div class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 dark:border-rose-900 dark:bg-rose-950">
                            <p class="font-semibold text-rose-900 dark:text-rose-100">{{ $sourceCustomer?->name }}</p>
                            <p class="text-xs text-rose-700 dark:text-rose-300">{{ $sourceCustomer?->customer_code }} · {{ $sourceCustomer?->phone }}</p>
                        </div>

                        {{-- Summary counts --}}
                        @if (! empty($mergeSummary))
                            <div class="mt-2 flex flex-wrap gap-3 text-xs text-neutral-600 dark:text-neutral-400">
                                @if ($mergeSummary['invoices'])      <span>{{ $mergeSummary['invoices'] }} {{ __('invoice(s)') }}</span> @endif
                                @if ($mergeSummary['payments'])      <span>{{ $mergeSummary['payments'] }} {{ __('payment(s)') }}</span> @endif
                                @if ($mergeSummary['orders'])        <span>{{ $mergeSummary['orders'] }} {{ __('order(s)') }}</span> @endif
                                @if ($mergeSummary['subscriptions']) <span>{{ $mergeSummary['subscriptions'] }} {{ __('subscription(s)') }}</span> @endif
                                @if ($mergeSummary['sales'])         <span>{{ $mergeSummary['sales'] }} {{ __('sale(s)') }}</span> @endif
                                @if ($mergeSummary['pastry_orders']) <span>{{ $mergeSummary['pastry_orders'] }} {{ __('pastry order(s)') }}</span> @endif
                                @if (array_sum($mergeSummary) === 0) <span class="text-neutral-400">{{ __('No linked records') }}</span> @endif
                            </div>
                        @endif
                    </div>

                    {{-- Target search --}}
                    <div>
                        <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-neutral-500">{{ __('Keep (target customer)') }}</p>

                        @if ($mergeTargetId)
                            @php $targetCustomer = \App\Models\Customer::find($mergeTargetId); @endphp
                            <div class="flex items-center justify-between rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 dark:border-emerald-900 dark:bg-emerald-950">
                                <div>
                                    <p class="font-semibold text-emerald-900 dark:text-emerald-100">{{ $targetCustomer?->name }}</p>
                                    <p class="text-xs text-emerald-700 dark:text-emerald-300">{{ $targetCustomer?->customer_code }} · {{ $targetCustomer?->phone }}</p>
                                </div>
                                <button wire:click="$set('mergeTargetId', null)" class="text-xs text-emerald-700 underline hover:text-emerald-900 dark:text-emerald-400">{{ __('Change') }}</button>
                            </div>
                        @else
                            <flux:input
                                wire:model.live.debounce.250ms="mergeTargetSearch"
                                placeholder="{{ __('Search by name, code or phone…') }}"
                                autofocus
                            />

                            @php $results = $this->mergeTargetResults(); @endphp
                            @if (count($results))
                                <div class="mt-1 divide-y divide-neutral-100 overflow-hidden rounded-lg border border-neutral-200 bg-white shadow dark:divide-neutral-800 dark:border-neutral-700 dark:bg-neutral-800">
                                    @foreach ($results as $r)
                                        <button
                                            wire:click="selectMergeTarget({{ $r['id'] }})"
                                            class="w-full px-4 py-2 text-left text-sm hover:bg-neutral-50 dark:hover:bg-neutral-700"
                                        >
                                            <span class="font-medium text-neutral-900 dark:text-neutral-100">{{ $r['name'] }}</span>
                                            <span class="ml-2 text-xs text-neutral-500">{{ $r['customer_code'] }} · {{ $r['phone'] }}</span>
                                        </button>
                                    @endforeach
                                </div>
                            @elseif (strlen(trim($mergeTargetSearch)) >= 2)
                                <p class="mt-1 text-xs text-neutral-500">{{ __('No customers found.') }}</p>
                            @endif
                        @endif
                    </div>

                    @error('merge') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>

                <div class="flex justify-end gap-3 border-t border-neutral-200 px-6 py-4 dark:border-neutral-700">
                    <flux:button variant="ghost" wire:click="closeMerge">{{ __('Cancel') }}</flux:button>
                    <flux:button
                        variant="danger"
                        wire:click="confirmMerge"
                        wire:confirm="{{ __('This will move all records from the duplicate to the target and deactivate the duplicate. This cannot be undone. Continue?') }}"
                        :disabled="! $mergeTargetId"
                    >
                        {{ __('Merge Customers') }}
                    </flux:button>
                </div>
            </div>
        </div>
    @endif
</div>
