<?php

use App\Models\AccountingCompany;
use App\Models\Customer;
use App\Models\Payment;
use App\Support\Money\MinorUnits;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public ?int $company_id = null;
    public int $branch_id = 1;
    public ?int $customer_id = null;
    public string $customer_search = '';
    public ?string $date_from = null;
    public ?string $date_to = null;

    protected $paginationTheme = 'tailwind';

    public function mount(): void
    {
        $this->company_id = request()->integer('company_id')
            ?: AccountingCompany::query()->where('is_default', true)->value('id');
        $this->branch_id = max(0, (int) request()->integer('branch_id', 0));
        $this->customer_id = request()->integer('customer_id') ?: null;
        $this->date_from = request()->query('date_from') ?: null;
        $this->date_to = request()->query('date_to') ?: null;

        if ($this->customer_id) {
            $customer = Customer::find($this->customer_id);
            $this->customer_search = $customer ? trim($customer->name.' '.($customer->phone ?? '')) : '';
        }
    }

    public function updating($name): void
    {
        if (in_array($name, ['company_id', 'branch_id', 'customer_id', 'customer_search', 'date_from', 'date_to'], true)) {
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
        $customer = Customer::find($id);
        $this->customer_search = $customer ? trim($customer->name.' '.($customer->phone ?? '')) : '';
    }

    public function with(): array
    {
        $customers = collect();
        if (Schema::hasTable('customers') && $this->customer_id === null && trim($this->customer_search) !== '') {
            $customers = Customer::query()
                ->active()
                ->search($this->customer_search)
                ->orderBy('name')
                ->limit(25)
                ->get();
        }

        return [
            'companies' => AccountingCompany::query()->orderBy('name')->get(),
            'payments' => $this->query()->paginate(15),
            'branches' => Schema::hasTable('branches') ? DB::table('branches')->where('is_active', 1)->orderBy('name')->get() : collect(),
            'customers' => $customers,
            'exportParams' => $this->exportParams(),
        ];
    }

    private function query()
    {
        return Payment::query()
            ->where('source', 'ar')
            ->whereNull('voided_at')
            ->with(['customer'])
            ->withSum('allocations as allocated_sum', 'amount_cents')
            ->when($this->company_id, fn ($q) => $q->where('company_id', $this->company_id))
            ->when($this->branch_id > 0, fn ($q) => $q->where('branch_id', $this->branch_id))
            ->when($this->customer_id, fn ($q) => $q->where('customer_id', $this->customer_id))
            ->when($this->date_from, fn ($q) => $q->whereDate('received_at', '>=', $this->date_from))
            ->when($this->date_to, fn ($q) => $q->whereDate('received_at', '<=', $this->date_to))
            ->orderByDesc('received_at')
            ->orderByDesc('id');
    }

    public function exportParams(): array
    {
        return array_filter([
            'company_id' => $this->company_id,
            'branch_id' => $this->branch_id,
            'customer_id' => $this->customer_id,
            'date_from' => $this->date_from,
            'date_to' => $this->date_to,
        ], fn ($value) => $value !== null && $value !== '');
    }

    public function formatMoney(?int $cents): string
    {
        return MinorUnits::format((int) ($cents ?? 0));
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Customer Payments') }}</h1>
            <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                {{ __('Shows all receivables customer payments, including fully allocated and partially allocated payments.') }}
            </p>
        </div>
        <div class="flex gap-2">
            <flux:button :href="route('reports.index', array_filter(['category' => \App\Support\Reports\ReportRegistry::findByRoute(request()->route()?->getName())['category'] ?? null]))" wire:navigate variant="ghost">{{ __('Back to Reports') }}</flux:button>
            <flux:button href="{{ route('reports.customer-payments.print') . '?' . http_build_query($exportParams) }}" target="_blank" variant="ghost">{{ __('Print') }}</flux:button>
            <flux:button href="{{ route('reports.customer-payments.xlsx') . '?' . http_build_query($exportParams) }}" variant="ghost">{{ __('Export Excel') }}</flux:button>
            <flux:button href="{{ route('reports.customer-payments.pdf') . '?' . http_build_query($exportParams) }}" variant="ghost">{{ __('Export PDF') }}</flux:button>
        </div>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="app-filter-grid">
            <div class="min-w-[220px]">
                <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Company') }}</label>
                <select wire:model.live="company_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    @foreach ($companies as $company)
                        <option value="{{ $company->id }}">{{ $company->name }}</option>
                    @endforeach
                </select>
            </div>
            <x-reports.branch-select name="branch_id" :branches="$branches" />
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
            <x-reports.date-range fromName="date_from" toName="date_to" />
        </div>
    </div>

    <div class="app-table-shell">
        <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Payment #') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Customer') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Date') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Method') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Reference') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Amount') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Allocated') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Unallocated') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                @forelse ($payments as $payment)
                    @php
                        $allocated = (int) ($payment->allocated_sum ?? 0);
                        $remaining = (int) $payment->amount_cents - $allocated;
                    @endphp
                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">#{{ $payment->id }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $payment->customer?->name ?? '—' }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $payment->received_at?->format('Y-m-d') }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ strtoupper($payment->method ?? '—') }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $payment->reference ?: '—' }}</td>
                        <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($payment->amount_cents) }}</td>
                        <td class="px-3 py-2 text-right text-sm text-neutral-700 dark:text-neutral-200">{{ $this->formatMoney($allocated) }}</td>
                        <td class="px-3 py-2 text-right text-sm text-neutral-700 dark:text-neutral-200">{{ $this->formatMoney($remaining) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No customer payments found.') }}</td>
                    </tr>
                @endforelse
            </tbody>
            @if ($payments->count() > 0)
                <tfoot class="bg-neutral-50 dark:bg-neutral-800/90">
                    <tr>
                        <td colspan="5" class="px-3 py-2 text-right text-sm font-semibold text-neutral-700 dark:text-neutral-200">{{ __('Total') }}</td>
                        <td class="px-3 py-2 text-right text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($payments->getCollection()->sum('amount_cents')) }}</td>
                        <td class="px-3 py-2 text-right text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($payments->getCollection()->sum(fn ($payment) => (int) ($payment->allocated_sum ?? 0))) }}</td>
                        <td class="px-3 py-2 text-right text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($payments->getCollection()->sum(fn ($payment) => (int) $payment->amount_cents - (int) ($payment->allocated_sum ?? 0))) }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>

    <div>{{ $payments->links() }}</div>
</div>
