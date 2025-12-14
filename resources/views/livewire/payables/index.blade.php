<?php

use App\Models\ApInvoice;
use App\Models\ApPayment;
use App\Models\Supplier;
use App\Services\AP\ApReportsService;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $tab = 'invoices';
    public string $invoice_status = 'all';
    public ?int $invoice_supplier_id = null;
    public ?string $invoice_number = null;
    public ?string $invoice_date_from = null;
    public ?string $invoice_date_to = null;
    public ?string $due_date_from = null;
    public ?string $due_date_to = null;

    public ?int $payment_supplier_id = null;
    public ?string $payment_method = null;
    public ?string $payment_date_from = null;
    public ?string $payment_date_to = null;

    protected $paginationTheme = 'tailwind';

    public function updating($field): void
    {
        if (str_starts_with($field, 'invoice_') || str_starts_with($field, 'payment_') || $field === 'tab') {
            $this->resetPage();
        }
    }

    public function with(): array
    {
        $suppliers = Schema::hasTable('suppliers') ? Supplier::orderBy('name')->get() : collect();

        return [
            'invoicePage' => $this->invoiceQuery()->paginate(10, pageName: 'invPage'),
            'paymentPage' => $this->paymentQuery()->paginate(10, pageName: 'payPage'),
            'suppliers' => $suppliers,
            'aging' => app(ApReportsService::class)->agingSummary(),
            'agingInvoices' => ApInvoice::with('supplier')
                ->withSum('allocations as paid_sum', 'allocated_amount')
                ->when(true, function ($q) {
                    // exclude void and paid; only show open balances
                    $q->whereNotIn('status', ['void', 'paid']);
                })
                ->orderByDesc('due_date')
                ->limit(100)
                ->get(),
        ];
    }

    private function invoiceQuery()
    {
        return ApInvoice::query()
            ->with(['supplier'])
            ->withSum('allocations as paid_sum', 'allocated_amount')
            ->when($this->invoice_supplier_id, fn ($q) => $q->where('supplier_id', $this->invoice_supplier_id))
            ->when($this->invoice_status && $this->invoice_status !== 'all', fn ($q) => $q->where('status', $this->invoice_status))
            ->when($this->invoice_number, fn ($q) => $q->where('invoice_number', 'like', '%'.$this->invoice_number.'%'))
            ->when($this->invoice_date_from, fn ($q) => $q->whereDate('invoice_date', '>=', $this->invoice_date_from))
            ->when($this->invoice_date_to, fn ($q) => $q->whereDate('invoice_date', '<=', $this->invoice_date_to))
            ->when($this->due_date_from, fn ($q) => $q->whereDate('due_date', '>=', $this->due_date_from))
            ->when($this->due_date_to, fn ($q) => $q->whereDate('due_date', '<=', $this->due_date_to))
            ->orderByDesc('invoice_date');
    }

    private function paymentQuery()
    {
        return ApPayment::query()
            ->with(['supplier'])
            ->withSum('allocations as alloc_sum', 'allocated_amount')
            ->when($this->payment_supplier_id, fn ($q) => $q->where('supplier_id', $this->payment_supplier_id))
            ->when($this->payment_method, fn ($q) => $q->where('payment_method', $this->payment_method))
            ->when($this->payment_date_from, fn ($q) => $q->whereDate('payment_date', '>=', $this->payment_date_from))
            ->when($this->payment_date_to, fn ($q) => $q->whereDate('payment_date', '<=', $this->payment_date_to))
            ->orderByDesc('payment_date');
    }
}; ?>

<div class="w-full max-w-7xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Accounts Payable') }}</h1>
        <div class="flex gap-2">
            <flux:button :href="route('payables.invoices.create')" wire:navigate>{{ __('New Invoice') }}</flux:button>
            <flux:button :href="route('payables.payments.create')" wire:navigate>{{ __('New Payment') }}</flux:button>
        </div>
    </div>

    <div class="flex gap-3">
        <button type="button" wire:click="$set('tab','invoices')" class="px-3 py-2 rounded-md text-sm font-semibold {{ $tab==='invoices' ? 'bg-neutral-200 dark:bg-neutral-700' : 'bg-neutral-100 dark:bg-neutral-800' }}">{{ __('Invoices') }}</button>
        <button type="button" wire:click="$set('tab','payments')" class="px-3 py-2 rounded-md text-sm font-semibold {{ $tab==='payments' ? 'bg-neutral-200 dark:bg-neutral-700' : 'bg-neutral-100 dark:bg-neutral-800' }}">{{ __('Payments') }}</button>
        <button type="button" wire:click="$set('tab','aging')" class="px-3 py-2 rounded-md text-sm font-semibold {{ $tab==='aging' ? 'bg-neutral-200 dark:bg-neutral-700' : 'bg-neutral-100 dark:bg-neutral-800' }}">{{ __('Aging') }}</button>
    </div>

    @if($tab === 'invoices')
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-2">
            <div class="flex flex-wrap gap-3">
                <div class="flex-1 min-w-[220px]">
                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Invoice #') }}</label>
                    <input wire:model="invoice_number" type="text" placeholder="{{ __('Search invoice #') }}" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" />
                </div>
                <div class="flex-1 min-w-[180px]">
                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Supplier') }}</label>
                    <select wire:model="invoice_supplier_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="">{{ __('All') }}</option>
                        @foreach($suppliers as $supplier)
                            <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex-1 min-w-[160px]">
                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Status') }}</label>
                    <select wire:model="invoice_status" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="all">{{ __('All') }}</option>
                        <option value="draft">{{ __('Draft') }}</option>
                        <option value="posted">{{ __('Posted') }}</option>
                        <option value="partially_paid">{{ __('Partially Paid') }}</option>
                        <option value="paid">{{ __('Paid') }}</option>
                        <option value="void">{{ __('Void') }}</option>
                    </select>
                </div>
                <div class="flex-1 min-w-[170px]">
                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Invoice Date From') }}</label>
                    <input wire:model="invoice_date_from" type="date" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" />
                </div>
                <div class="flex-1 min-w-[170px]">
                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Invoice Date To') }}</label>
                    <input wire:model="invoice_date_to" type="date" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" />
                </div>
                <div class="flex-1 min-w-[170px]">
                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Due Date From') }}</label>
                    <input wire:model="due_date_from" type="date" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" />
                </div>
                <div class="flex-1 min-w-[170px]">
                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Due Date To') }}</label>
                    <input wire:model="due_date_to" type="date" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" />
                </div>
            </div>

            <div class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                    <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Invoice #') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Supplier') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Invoice Date') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Due Date') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Total') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Paid') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Outstanding') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Status') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        @forelse($invoicePage as $inv)
                            <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                                <td class="px-3 py-2 text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ $inv->invoice_number }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $inv->supplier->name ?? '—' }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $inv->invoice_date?->format('Y-m-d') }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $inv->due_date?->format('Y-m-d') }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float)$inv->total_amount, 2) }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ number_format((float)$inv->paid_sum, 2) }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ number_format((float)$inv->total_amount - (float)$inv->paid_sum, 2) }}</td>
                                <td class="px-3 py-2 text-sm">
                                    <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold bg-neutral-100 text-neutral-800 dark:bg-neutral-800 dark:text-neutral-50">{{ $inv->status }}</span>
                                </td>
                                <td class="px-3 py-2 text-sm">
                                    <div class="flex gap-2">
                                        <flux:button size="xs" :href="route('payables.invoices.show', $inv)" wire:navigate>{{ __('View') }}</flux:button>
                                        @if($inv->status === 'draft')
                                            <flux:button size="xs" :href="route('payables.invoices.edit', $inv)" wire:navigate>{{ __('Edit') }}</flux:button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="px-3 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No invoices found') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $invoicePage->links() }}
        </div>
    @endif

    @if($tab === 'payments')
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-2">
            <div class="flex flex-wrap gap-3">
                <div class="flex-1 min-w-[180px]">
                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Supplier') }}</label>
                    <select wire:model="payment_supplier_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="">{{ __('All') }}</option>
                        @foreach($suppliers as $supplier)
                            <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex-1 min-w-[160px]">
                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Method') }}</label>
                    <select wire:model="payment_method" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="">{{ __('All') }}</option>
                        <option value="cash">Cash</option>
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="card">Card</option>
                        <option value="cheque">Cheque</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="flex-1 min-w-[160px]">
                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Payment Date From') }}</label>
                    <input wire:model="payment_date_from" type="date" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" />
                </div>
                <div class="flex-1 min-w-[160px]">
                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Payment Date To') }}</label>
                    <input wire:model="payment_date_to" type="date" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" />
                </div>
            </div>

            <div class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                    <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Date') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Supplier') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Amount') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Allocated') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Method') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        @forelse($paymentPage as $pay)
                            <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                                <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $pay->payment_date?->format('Y-m-d') }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $pay->supplier->name ?? '—' }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float)$pay->amount, 2) }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ number_format((float)$pay->alloc_sum, 2) }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $pay->payment_method ?? '—' }}</td>
                                <td class="px-3 py-2 text-sm">
                                    <flux:button size="xs" :href="route('payables.payments.show', $pay)" wire:navigate>{{ __('View') }}</flux:button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-3 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No payments found') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $paymentPage->links('pagination::tailwind', ['paginator' => $paymentPage]) }}
        </div>
    @endif

    @if($tab === 'aging')
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <h3 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100 mb-4">{{ __('Aging Summary') }}</h3>
            <div class="flex flex-col gap-3 text-sm text-neutral-900 dark:text-neutral-100">
                <div class="flex flex-col gap-2 sm:flex-row sm:gap-3">
                    <div class="flex-1 rounded-md border border-neutral-200 bg-neutral-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                        <p class="text-xs text-neutral-600 dark:text-neutral-300 uppercase">{{ __('Current') }}</p>
                        <p class="text-lg font-semibold">{{ number_format($aging['current'] ?? 0, 2) }}</p>
                    </div>
                    <div class="flex-1 rounded-md border border-neutral-200 bg-neutral-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                        <p class="text-xs text-neutral-600 dark:text-neutral-300 uppercase">{{ __('1-30') }}</p>
                        <p class="text-lg font-semibold">{{ number_format($aging['1_30'] ?? 0, 2) }}</p>
                    </div>
                </div>
                <div class="flex flex-col gap-2 sm:flex-row sm:gap-3">
                    <div class="flex-1 rounded-md border border-neutral-200 bg-neutral-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                        <p class="text-xs text-neutral-600 dark:text-neutral-300 uppercase">{{ __('31-60') }}</p>
                        <p class="text-lg font-semibold">{{ number_format($aging['31_60'] ?? 0, 2) }}</p>
                    </div>
                    <div class="flex-1 rounded-md border border-neutral-200 bg-neutral-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                        <p class="text-xs text-neutral-600 dark:text-neutral-300 uppercase">{{ __('61-90') }}</p>
                        <p class="text-lg font-semibold">{{ number_format($aging['61_90'] ?? 0, 2) }}</p>
                    </div>
                </div>
                <div class="flex flex-col gap-2 sm:flex-row sm:gap-3">
                    <div class="flex-1 rounded-md border border-neutral-200 bg-neutral-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                        <p class="text-xs text-neutral-600 dark:text-neutral-300 uppercase">{{ __('90+') }}</p>
                        <p class="text-lg font-semibold">{{ number_format($aging['90_plus'] ?? 0, 2) }}</p>
                    </div>
                    <div class="flex-1"></div>
                </div>
            </div>

            <div class="mt-6 overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                    <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Invoice #') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Supplier') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Due Date') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Status') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Outstanding') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        @forelse($agingInvoices as $inv)
                            @php
                                $paid = (float)($inv->paid_sum ?? 0);
                                $outstanding = max((float)$inv->total_amount - $paid, 0);
                            @endphp
                            <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                                <td class="px-3 py-2 text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ $inv->invoice_number }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $inv->supplier->name ?? '—' }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $inv->due_date?->format('Y-m-d') }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $inv->status }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ number_format($outstanding, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-3 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No open invoices') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>

