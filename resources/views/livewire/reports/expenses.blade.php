<?php

use App\Models\ExpenseCategory;
use App\Models\Supplier;
use App\Services\Spend\SpendReportService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $source = 'all';
    public ?int $supplier_id = null;
    public ?int $category_id = null;
    public string $payment_status = 'all';
    public string $payment_method = 'all';
    public ?string $date_from = null;
    public ?string $date_to = null;

    public function updating($name): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        $rows = app(SpendReportService::class)->collect($this->exportParams(), 2000);
        $perPage = 15;
        $pageName = 'page';
        $currentPage = $this->getPage($pageName);
        $items = $rows->slice(($currentPage - 1) * $perPage, $perPage)->values();
        $paginator = new LengthAwarePaginator(
            $items,
            $rows->count(),
            $perPage,
            $currentPage,
            [
                'path' => request()->url(),
                'query' => request()->query(),
                'pageName' => $pageName,
            ]
        );

        return [
            'expenses' => $paginator,
            'categories' => Schema::hasTable('expense_categories') ? ExpenseCategory::orderBy('name')->get() : collect(),
            'suppliers' => Schema::hasTable('suppliers') ? Supplier::orderBy('name')->get() : collect(),
            'exportParams' => $this->exportParams(),
        ];
    }

    public function exportParams(): array
    {
        return array_filter([
            'search' => $this->search ?: null,
            'source' => $this->source !== 'all' ? $this->source : null,
            'supplier_id' => $this->supplier_id,
            'category_id' => $this->category_id,
            'payment_status' => $this->payment_status !== 'all' ? $this->payment_status : null,
            'payment_method' => $this->payment_method !== 'all' ? $this->payment_method : null,
            'date_from' => $this->date_from,
            'date_to' => $this->date_to,
        ], fn ($v) => $v !== null && $v !== '');
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Spend Report') }}</h1>
        <div class="flex gap-2">
            <flux:button :href="route('reports.index')" wire:navigate variant="ghost">{{ __('Back to Reports') }}</flux:button>
            <flux:button href="{{ route('reports.expenses.print') . '?' . http_build_query($exportParams) }}" target="_blank" variant="ghost">{{ __('Print') }}</flux:button>
            <flux:button href="{{ route('reports.expenses.csv') . '?' . http_build_query($exportParams) }}" variant="ghost">{{ __('Export CSV') }}</flux:button>
            <flux:button href="{{ route('reports.expenses.pdf') . '?' . http_build_query($exportParams) }}" variant="ghost">{{ __('Export PDF') }}</flux:button>
        </div>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="app-filter-grid">
            <div class="min-w-[200px]">
                <flux:input wire:model.live.debounce.300ms="search" :label="__('Search')" placeholder="{{ __('Description / reference') }}" />
            </div>
            <x-reports.date-range fromName="date_from" toName="date_to" />
            <div class="min-w-[180px]">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Source') }}</label>
                <select wire:model.live="source" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="all">{{ __('All') }}</option>
                    <option value="vendor">{{ __('Vendor') }}</option>
                    <option value="petty_cash">{{ __('Petty Cash') }}</option>
                    <option value="reimbursement">{{ __('Reimbursement') }}</option>
                </select>
            </div>
            <div class="min-w-[180px]">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Supplier') }}</label>
                <select wire:model.live="supplier_id" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="">{{ __('All') }}</option>
                    @foreach ($suppliers as $s)
                        <option value="{{ $s->id }}">{{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="min-w-[180px]">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Category') }}</label>
                <select wire:model.live="category_id" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="">{{ __('All') }}</option>
                    @foreach ($categories as $c)
                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>
            <x-reports.status-select name="payment_status" :options="[
                ['value' => 'all', 'label' => __('All')],
                ['value' => 'draft', 'label' => __('Draft')],
                ['value' => 'submitted', 'label' => __('Submitted')],
                ['value' => 'manager_approved', 'label' => __('Manager Approved')],
                ['value' => 'approved', 'label' => __('Approved')],
                ['value' => 'rejected', 'label' => __('Rejected')],
                ['value' => 'unpaid', 'label' => __('Unpaid')],
                ['value' => 'partial', 'label' => __('Partial')],
                ['value' => 'paid', 'label' => __('Paid')],
            ]" />
            <div class="min-w-[140px]">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Payment Method') }}</label>
                <select wire:model.live="payment_method" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="all">{{ __('All') }}</option>
                    <option value="cash">{{ __('Cash') }}</option>
                    <option value="card">{{ __('Card') }}</option>
                    <option value="bank_transfer">{{ __('Bank Transfer') }}</option>
                    <option value="cheque">{{ __('Cheque') }}</option>
                    <option value="petty_cash">{{ __('Petty Cash') }}</option>
                    <option value="other">{{ __('Other') }}</option>
                </select>
            </div>
        </div>
    </div>

    <div class="app-table-shell">
        <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Date') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Reference') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Source') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Description') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Supplier') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Category') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Status') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Amount') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                @forelse ($expenses as $exp)
                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $exp['date'] ?? '—' }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $exp['reference'] ?: '—' }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ str_replace('_', ' ', $exp['source'] ?? '') }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ Str::limit((string) ($exp['description'] ?? ''), 40) }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $exp['supplier'] ?: '—' }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $exp['category'] ?: '—' }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $exp['status'] ?: '—' }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ number_format((float) ($exp['amount'] ?? 0), 3) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No spend records found.') }}</td>
                    </tr>
                @endforelse
            </tbody>
            @if ($expenses->count() > 0)
                <tfoot class="bg-neutral-50 dark:bg-neutral-800/90">
                    <tr>
                        <td colspan="7" class="px-3 py-2 text-right text-sm font-semibold text-neutral-700 dark:text-neutral-200">{{ __('Total') }}</td>
                        <td class="px-3 py-2 text-right text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($expenses->getCollection()->sum('amount'), 3) }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
    <div>{{ $expenses->links() }}</div>
</div>
