<?php

use App\Models\ApInvoice;
use App\Models\Supplier;
use App\Services\AP\ApReportsService;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $tab = 'invoices';
    public ?int $supplier_id = null;
    public string $invoice_status = 'all';
    public ?string $invoice_date_from = null;
    public ?string $invoice_date_to = null;
    public ?string $payment_date_from = null;
    public ?string $payment_date_to = null;
    public ?string $payment_method = null;

    protected $paginationTheme = 'tailwind';

    public function updating($name): void
    {
        $this->resetPage();
    }

    public function with(ApReportsService $reportsService): array
    {
        $suppliers = Schema::hasTable('suppliers') ? Supplier::orderBy('name')->get() : collect();

        $invoiceFilters = [
            'supplier_id' => $this->supplier_id,
            'status' => $this->invoice_status,
            'invoice_date_from' => $this->invoice_date_from,
            'invoice_date_to' => $this->invoice_date_to,
            'per_page' => 15,
        ];
        $paymentFilters = [
            'supplier_id' => $this->supplier_id,
            'payment_date_from' => $this->payment_date_from,
            'payment_date_to' => $this->payment_date_to,
            'payment_method' => $this->payment_method,
            'per_page' => 15,
        ];

        return [
            'suppliers' => $suppliers,
            'aging' => $reportsService->agingSummary($this->supplier_id),
            'invoicePage' => $reportsService->invoiceRegister($invoiceFilters),
            'paymentPage' => $reportsService->paymentRegister($paymentFilters),
            'exportParams' => $this->exportParams(),
        ];
    }

    public function exportParams(): array
    {
        return array_filter([
            'tab' => $this->tab,
            'supplier_id' => $this->supplier_id,
            'invoice_status' => $this->invoice_status !== 'all' ? $this->invoice_status : null,
            'invoice_date_from' => $this->invoice_date_from,
            'invoice_date_to' => $this->invoice_date_to,
            'payment_date_from' => $this->payment_date_from,
            'payment_date_to' => $this->payment_date_to,
            'payment_method' => $this->payment_method,
        ], fn ($v) => $v !== null && $v !== '');
    }

    public function formatMoney(?float $amount): string
    {
        return number_format((float) ($amount ?? 0), 3, '.', '');
    }
}; ?>

<div class="w-full max-w-7xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Payables (AP) Report') }}</h1>
        <div class="flex gap-2">
            <flux:button :href="route('reports.index')" wire:navigate variant="ghost">{{ __('Back to Reports') }}</flux:button>
            <flux:button href="{{ route('reports.payables.print') . '?' . http_build_query($exportParams) }}" target="_blank" variant="ghost">{{ __('Print') }}</flux:button>
            <flux:button href="{{ route('reports.payables.csv') . '?' . http_build_query($exportParams) }}" variant="ghost">{{ __('Export CSV') }}</flux:button>
            <flux:button href="{{ route('reports.payables.pdf') . '?' . http_build_query($exportParams) }}" variant="ghost">{{ __('Export PDF') }}</flux:button>
        </div>
    </div>

    <div class="flex gap-3">
        <button type="button" wire:click="$set('tab','invoices')" class="px-3 py-2 rounded-md text-sm font-semibold {{ $tab === 'invoices' ? 'bg-neutral-200 dark:bg-neutral-700' : 'bg-neutral-100 dark:bg-neutral-800' }}">{{ __('Invoices') }}</button>
        <button type="button" wire:click="$set('tab','payments')" class="px-3 py-2 rounded-md text-sm font-semibold {{ $tab === 'payments' ? 'bg-neutral-200 dark:bg-neutral-700' : 'bg-neutral-100 dark:bg-neutral-800' }}">{{ __('Payments') }}</button>
        <button type="button" wire:click="$set('tab','aging')" class="px-3 py-2 rounded-md text-sm font-semibold {{ $tab === 'aging' ? 'bg-neutral-200 dark:bg-neutral-700' : 'bg-neutral-100 dark:bg-neutral-800' }}">{{ __('Aging') }}</button>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="flex flex-wrap items-end gap-3">
            <div class="min-w-[200px]">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Supplier') }}</label>
                <select wire:model.live="supplier_id" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="">{{ __('All') }}</option>
                    @foreach ($suppliers as $s)
                        <option value="{{ $s->id }}">{{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
            @if ($tab === 'invoices')
                <x-reports.date-range fromName="invoice_date_from" toName="invoice_date_to" />
                <x-reports.status-select name="invoice_status" :options="[
                    ['value' => 'all', 'label' => __('All')],
                    ['value' => 'draft', 'label' => __('Draft')],
                    ['value' => 'posted', 'label' => __('Posted')],
                    ['value' => 'partially_paid', 'label' => __('Partially Paid')],
                    ['value' => 'paid', 'label' => __('Paid')],
                    ['value' => 'void', 'label' => __('Void')],
                ]" />
            @endif
            @if ($tab === 'payments')
                <x-reports.date-range fromName="payment_date_from" toName="payment_date_to" />
                <div class="min-w-[140px]">
                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Payment Method') }}</label>
                    <select wire:model.live="payment_method" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="">{{ __('All') }}</option>
                        <option value="cash">{{ __('Cash') }}</option>
                        <option value="card">{{ __('Card') }}</option>
                        <option value="bank_transfer">{{ __('Bank Transfer') }}</option>
                        <option value="cheque">{{ __('Cheque') }}</option>
                    </select>
                </div>
            @endif
        </div>
    </div>

    @if ($tab === 'invoices')
        <div class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                    <tr>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Invoice #') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Supplier') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Date') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Due') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Status') }}</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Total') }}</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Outstanding') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    @forelse ($invoicePage as $inv)
                        @php $outstanding = max((float) $inv->total_amount - (float) ($inv->paid_sum ?? 0), 0); @endphp
                        <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                            <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $inv->invoice_number }}</td>
                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $inv->supplier?->name ?? '—' }}</td>
                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $inv->invoice_date?->format('Y-m-d') }}</td>
                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $inv->due_date?->format('Y-m-d') }}</td>
                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $inv->status }}</td>
                            <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($inv->total_amount) }}</td>
                            <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($outstanding) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No invoices found.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div>{{ $invoicePage->links() }}</div>
    @endif

    @if ($tab === 'payments')
        <div class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                    <tr>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Payment #') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Supplier') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Date') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Method') }}</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Amount') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    @forelse ($paymentPage as $pay)
                        <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                            <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $pay->payment_number ?? $pay->id }}</td>
                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $pay->supplier?->name ?? '—' }}</td>
                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $pay->payment_date?->format('Y-m-d') }}</td>
                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $pay->payment_method ?? '—' }}</td>
                            <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($pay->amount ?? 0) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No payments found.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div>{{ $paymentPage->links() }}</div>
    @endif

    @if ($tab === 'aging')
        <div class="rounded-xl border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100 mb-4">{{ __('Aging Summary') }}</h2>
            <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                <div class="rounded-lg border border-neutral-200 p-3 dark:border-neutral-700">
                    <div class="text-xs text-neutral-500 dark:text-neutral-400">{{ __('Current') }}</div>
                    <div class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($aging['current'] ?? 0) }}</div>
                </div>
                <div class="rounded-lg border border-neutral-200 p-3 dark:border-neutral-700">
                    <div class="text-xs text-neutral-500 dark:text-neutral-400">{{ __('1-30 days') }}</div>
                    <div class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($aging['1_30'] ?? 0) }}</div>
                </div>
                <div class="rounded-lg border border-neutral-200 p-3 dark:border-neutral-700">
                    <div class="text-xs text-neutral-500 dark:text-neutral-400">{{ __('31-60 days') }}</div>
                    <div class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($aging['31_60'] ?? 0) }}</div>
                </div>
                <div class="rounded-lg border border-neutral-200 p-3 dark:border-neutral-700">
                    <div class="text-xs text-neutral-500 dark:text-neutral-400">{{ __('61-90 days') }}</div>
                    <div class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($aging['61_90'] ?? 0) }}</div>
                </div>
                <div class="rounded-lg border border-neutral-200 p-3 dark:border-neutral-700">
                    <div class="text-xs text-neutral-500 dark:text-neutral-400">{{ __('90+ days') }}</div>
                    <div class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($aging['90_plus'] ?? 0) }}</div>
                </div>
            </div>
        </div>
    @endif
</div>
