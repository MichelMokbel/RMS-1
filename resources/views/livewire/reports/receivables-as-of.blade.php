<?php

use App\Models\Customer;
use App\Services\Reports\ReceivablesAsOfReport;
use App\Support\Money\MinorUnits;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public int $branch_id = 0;
    public ?int $customer_id = null;
    public string $customer_search = '';
    public ?string $as_of_date = null;

    protected $paginationTheme = 'tailwind';

    public function mount(): void
    {
        $this->branch_id = max(0, (int) request()->integer('branch_id', 0));
        $this->customer_id = request()->integer('customer_id') ?: null;
        $this->as_of_date = request()->query('as_of_date') ?: now()->toDateString();

        if ($this->customer_id) {
            $customer = Customer::find($this->customer_id);
            $this->customer_search = $customer ? trim($customer->name.' '.($customer->phone ?? '')) : '';
        }
    }

    public function updating($name): void
    {
        if (in_array($name, ['branch_id', 'customer_id', 'customer_search', 'as_of_date'], true)) {
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
        $rows = app(ReceivablesAsOfReport::class)->rows($this->exportParams());
        $perPage = 50;
        $page = LengthAwarePaginator::resolveCurrentPage();
        $pageItems = $rows->forPage($page, $perPage)->values();
        $receivables = new LengthAwarePaginator(
            $pageItems,
            $rows->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );

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
            'receivables' => $receivables,
            'branches' => Schema::hasTable('branches') ? DB::table('branches')->where('is_active', 1)->orderBy('name')->get() : collect(),
            'customers' => $customers,
            'exportParams' => $this->exportParams(),
        ];
    }

    public function exportParams(): array
    {
        return array_filter([
            'as_of_date' => $this->as_of_date ?: now()->toDateString(),
            'branch_id' => $this->branch_id > 0 ? $this->branch_id : null,
            'customer_id' => $this->customer_id,
        ], fn ($value) => $value !== null && $value !== '');
    }

    public function formatCents(?int $cents): string
    {
        return MinorUnits::format((int) ($cents ?? 0));
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Receivables As Of') }}</h1>
            <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                {{ __('Invoice balances that were still open at the selected closing date.') }}
            </p>
        </div>
        <div class="flex gap-2">
            <flux:button :href="route('reports.index', array_filter(['category' => \App\Support\Reports\ReportRegistry::findByRoute(request()->route()?->getName())['category'] ?? null]))" wire:navigate variant="ghost">{{ __('Back to Reports') }}</flux:button>
            <flux:button href="{{ route('reports.receivables-as-of.print') . '?' . http_build_query($exportParams) }}" target="_blank" variant="ghost">{{ __('Print') }}</flux:button>
            <flux:button href="{{ route('reports.receivables-as-of.csv') . '?' . http_build_query($exportParams) }}" variant="ghost">{{ __('Export CSV') }}</flux:button>
            <flux:button href="{{ route('reports.receivables-as-of.pdf') . '?' . http_build_query($exportParams) }}" variant="ghost">{{ __('Export PDF') }}</flux:button>
        </div>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="app-filter-grid">
            <x-reports.branch-select name="branch_id" :branches="$branches" />
            <div class="min-w-[180px]">
                <flux:input wire:model.live="as_of_date" type="date" :label="__('As Of Date')" />
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
        </div>
    </div>

    <div class="app-table-shell">
        <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Customer Code') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Customer') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Invoice #') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Issue Date') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Due Date') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Invoice Total') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Paid As Of') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Balance As Of') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Aging') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                @forelse ($receivables as $row)
                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $row['customer_code'] ?: '-' }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $row['customer_name'] }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $row['invoice_number'] }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $row['issue_date'] ?: '-' }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $row['due_date'] ?: '-' }}</td>
                        <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ $this->formatCents($row['total_cents']) }}</td>
                        <td class="px-3 py-2 text-right text-sm text-neutral-700 dark:text-neutral-200">{{ $this->formatCents($row['paid_as_of_cents']) }}</td>
                        <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ $this->formatCents($row['balance_as_of_cents']) }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $row['aging_label'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No receivables found for this as-of date.') }}</td>
                    </tr>
                @endforelse
            </tbody>
            @if ($receivables->count() > 0)
                <tfoot class="bg-neutral-50 dark:bg-neutral-800/90">
                    <tr>
                        <td colspan="5" class="px-3 py-2 text-right text-sm font-semibold text-neutral-700 dark:text-neutral-200">{{ __('Total') }}</td>
                        <td class="px-3 py-2 text-right text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatCents($receivables->getCollection()->sum('total_cents')) }}</td>
                        <td class="px-3 py-2 text-right text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatCents($receivables->getCollection()->sum('paid_as_of_cents')) }}</td>
                        <td class="px-3 py-2 text-right text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatCents($receivables->getCollection()->sum('balance_as_of_cents')) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
    <div>{{ $receivables->links() }}</div>
</div>
