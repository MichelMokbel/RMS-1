<?php

use App\Models\Customer;
use App\Services\Reports\MealPlanRequestReportService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $customerSearch = '';
    #[Locked]
    public ?int $customerId = null;
    public array $selectedRequestIds = [];

    public function mount(): void
    {
        abort_unless(auth()->user()?->isActive() && (auth()->user()->hasAnyRole(['admin', 'manager']) || auth()->user()->can('operations.access')), 403);
    }

    public function selectCustomer(int $id): void
    {
        Customer::findOrFail($id);
        $this->customerId = $id;
        $this->selectedRequestIds = [];
        $this->resetPage();
    }

    public function changeCustomer(): void
    {
        $this->customerId = null;
        $this->customerSearch = '';
        $this->selectedRequestIds = [];
        $this->resetPage();
    }

    public function selectPage(MealPlanRequestReportService $service): void
    {
        if (! $this->customerId) {
            return;
        }

        $ids = $service->requestsForCustomer(auth()->user(), $this->customerId)
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(25)->getCollection()->modelKeys();
        $this->selectedRequestIds = array_values(array_unique(array_merge($this->selectedRequestIds, array_map('strval', $ids))));
    }

    public function with(MealPlanRequestReportService $service): array
    {
        return [
            'customer' => $this->customerId ? Customer::findOrFail($this->customerId) : null,
            'customers' => ! $this->customerId && trim($this->customerSearch) !== ''
                ? Customer::query()->search(trim($this->customerSearch))->orderBy('name')->limit(20)->get()
                : collect(),
            'requests' => $this->customerId
                ? $service->requestsForCustomer(auth()->user(), $this->customerId)
                    ->withCount('orders')->withMin('orders', 'scheduled_date')->withMax('orders', 'scheduled_date')
                    ->orderByDesc('created_at')->orderByDesc('id')->paginate(25)
                : null,
        ];
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold">{{ __('Print Multiple Plans') }}</h1>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Choose a customer, then select the meal plan requests to print together.') }}</p>
        </div>
        <flux:button :href="route('meal-plan-requests.index')" wire:navigate variant="ghost" class="touch-target">{{ __('Back to Requests') }}</flux:button>
    </div>

    @if ($errors->any())
        <div role="alert" class="rounded-lg bg-rose-50 p-4 text-rose-800 dark:bg-rose-950 dark:text-rose-200">
            @foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach
        </div>
    @endif

    <section class="space-y-3 rounded-lg border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
        @if ($customer)
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="font-semibold">{{ $customer->name }}</h2>
                    <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ $customer->phone }} {{ $customer->email }}</p>
                </div>
                <flux:button wire:click="changeCustomer" type="button" class="touch-target">{{ __('Change Customer') }}</flux:button>
            </div>
        @else
            <flux:input wire:model.live.debounce.300ms="customerSearch" :label="__('Search Customer')" :placeholder="__('Customer name, phone, or email')" />
            @if (trim($customerSearch) !== '')
                <div class="max-h-72 overflow-y-auto divide-y divide-neutral-200 dark:divide-neutral-700">
                    @forelse ($customers as $candidate)
                        <button type="button" wire:click="selectCustomer({{ $candidate->id }})" wire:key="customer-{{ $candidate->id }}" class="touch-target block w-full rounded p-3 text-left hover:bg-neutral-50 dark:hover:bg-neutral-800">
                            <span class="block font-medium">{{ $candidate->name }}</span>
                            <span class="block text-sm text-neutral-600 dark:text-neutral-300">{{ $candidate->phone }} {{ $candidate->email }}</span>
                        </button>
                    @empty
                        <p class="py-3 text-sm text-neutral-600 dark:text-neutral-300">{{ __('No customers found.') }}</p>
                    @endforelse
                </div>
            @endif
        @endif
    </section>

    @if ($customer)
        <section class="space-y-4 rounded-lg border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="font-semibold">{{ __('Select Requests') }}</h2>
                    <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Only requests whose complete set of orders you can access are shown.') }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <flux:button wire:click="selectPage" type="button" :disabled="$requests->isEmpty()" class="touch-target">{{ __('Select This Page') }}</flux:button>
                    <flux:button wire:click="$set('selectedRequestIds', [])" type="button" :disabled="empty($selectedRequestIds)" class="touch-target">{{ __('Clear Selection') }}</flux:button>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[640px] text-left text-sm">
                    <thead class="bg-neutral-50 dark:bg-neutral-800">
                        <tr>
                            @foreach ([__('Select'), __('Request'), __('Requested'), __('Plan'), __('Status'), __('Order Dates'), __('Orders')] as $heading)
                                <th class="px-3 py-2">{{ $heading }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                        @forelse ($requests as $planRequest)
                            <tr wire:key="print-request-{{ $planRequest->id }}">
                                <td class="px-3 py-2"><label class="touch-target flex items-center"><input type="checkbox" wire:model.live="selectedRequestIds" value="{{ $planRequest->id }}" aria-label="{{ __('Select request #:id', ['id' => $planRequest->id]) }}" class="size-5 rounded border-neutral-300" /></label></td>
                                <td class="px-3 py-2">#{{ $planRequest->id }}</td>
                                <td class="px-3 py-2">{{ $planRequest->created_at?->format('Y-m-d') }}</td>
                                <td class="px-3 py-2">{{ __(':count meals', ['count' => $planRequest->plan_meals]) }}</td>
                                <td class="px-3 py-2">{{ ucfirst($planRequest->status) }}</td>
                                <td class="px-3 py-2">{{ $planRequest->orders_min_scheduled_date ?: '—' }} / {{ $planRequest->orders_max_scheduled_date ?: '—' }}</td>
                                <td class="px-3 py-2">{{ $planRequest->orders_count }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="p-4 text-center">{{ __('No available requests for this customer.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $requests->links() }}
            <form method="POST" action="{{ route('meal-plan-requests.print-selected') }}" target="_blank" class="flex flex-wrap items-center justify-between gap-3">
                @csrf
                <input type="hidden" name="customer_id" value="{{ $customerId }}" />
                @foreach ($selectedRequestIds as $requestId)
                    <input type="hidden" name="request_ids[]" value="{{ $requestId }}" />
                @endforeach
                <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __(':count requests selected. Shared orders appear once.', ['count' => count($selectedRequestIds)]) }}</p>
                <flux:button type="submit" variant="primary" :disabled="empty($selectedRequestIds)" wire:loading.attr="disabled" class="touch-target">{{ __('Print Selected Orders') }}</flux:button>
            </form>
        </section>
    @endif
</div>
