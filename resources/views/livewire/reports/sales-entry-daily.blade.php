<?php

use App\Models\ArInvoice;
use App\Models\Customer;
use App\Support\Money\MinorUnits;
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
    public ?string $date_from = null;
    public ?string $date_to = null;

    protected $paginationTheme = 'tailwind';

    public function mount(): void
    {
        $today = now()->toDateString();
        $this->date_from = $today;
        $this->date_to = $today;
    }

    public function updating($name): void
    {
        if (in_array($name, ['branch_id', 'customer_id', 'customer_search', 'date_from', 'date_to'], true)) {
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
        $baseQuery = $this->query();
        $invoices = (clone $baseQuery)->paginate(15);
        $allInvoices = (clone $baseQuery)->get();
        $totals = [
            'trade_revenue_cents' => (int) $allInvoices->sum(fn ($inv) => (int) ($inv->subtotal_cents ?? 0)),
            'discount_cents' => (int) $allInvoices->sum(fn ($inv) => (int) ($inv->discount_total_cents ?? 0)),
            'net_amount_cents' => (int) $allInvoices->sum(fn ($inv) => (int) ($inv->total_cents ?? 0)),
            'cash_cents' => 0,
            'card_cents' => 0,
            'credit_cents' => 0,
        ];

        foreach ($allInvoices as $inv) {
            $breakdown = $this->paymentBreakdownCents($inv);
            $totals['cash_cents'] += $breakdown['cash_cents'];
            $totals['card_cents'] += $breakdown['card_cents'];
            $totals['credit_cents'] += $breakdown['credit_cents'];
        }
        $totals['total_collection_cents'] = $totals['cash_cents'] + $totals['card_cents'] + $totals['credit_cents'];

        $customers = collect();
        if (Schema::hasTable('customers') && $this->customer_id === null && trim($this->customer_search) !== '') {
            $customers = Customer::query()
                ->search($this->customer_search)
                ->orderBy('name')
                ->limit(25)
                ->get();
        }

        return [
            'invoices' => $invoices,
            'totals' => $totals,
            'branches' => Schema::hasTable('branches') ? DB::table('branches')->where('is_active', 1)->orderBy('name')->get() : collect(),
            'customers' => $customers,
            'exportParams' => $this->exportParams(),
        ];
    }

    private function query()
    {
        return ArInvoice::query()
            ->with(['customer', 'salesPerson', 'paymentAllocations.payment'])
            ->where('type', 'invoice')
            ->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->when($this->branch_id > 0, fn ($q) => $q->where('branch_id', $this->branch_id))
            ->when($this->customer_id, fn ($q) => $q->where('customer_id', $this->customer_id))
            ->when($this->date_from, fn ($q) => $q->whereDate('issue_date', '>=', $this->date_from))
            ->when($this->date_to, fn ($q) => $q->whereDate('issue_date', '<=', $this->date_to))
            ->orderByDesc('issue_date')
            ->orderByDesc('id');
    }

    /**
     * @return array{cash_cents:int,card_cents:int,credit_cents:int}
     */
    public function paymentBreakdownCents(ArInvoice $invoice): array
    {
        $netAmountCents = (int) ($invoice->total_cents ?? 0);
        $paymentType = strtolower((string) ($invoice->payment_type ?? 'credit'));
        $cashCents = 0;
        $cardCents = 0;
        $hasAllocations = false;

        foreach ($invoice->paymentAllocations as $allocation) {
            $amount = max(0, (int) ($allocation->amount_cents ?? 0));
            if ($amount <= 0) {
                continue;
            }

            $hasAllocations = true;
            $method = strtolower((string) ($allocation->payment?->method ?? ''));
            if ($method === 'cash') {
                $cashCents += $amount;
                continue;
            }

            // Non-cash settled methods are grouped under card in this report.
            $cardCents += $amount;
        }

        if (! $hasAllocations) {
            return [
                'cash_cents' => $paymentType === 'cash' ? $netAmountCents : 0,
                'card_cents' => in_array($paymentType, ['card', 'mixed'], true) ? $netAmountCents : 0,
                'credit_cents' => $paymentType === 'credit' ? $netAmountCents : 0,
            ];
        }

        return [
            'cash_cents' => $cashCents,
            'card_cents' => $cardCents,
            'credit_cents' => max(0, $netAmountCents - $cashCents - $cardCents),
        ];
    }

    public function exportParams(): array
    {
        return array_filter([
            'branch_id' => $this->branch_id ?: null,
            'customer_id' => $this->customer_id,
            'date_from' => $this->date_from,
            'date_to' => $this->date_to,
        ], fn ($v) => $v !== null && $v !== '');
    }

    public function formatMoney(?int $cents): string
    {
        return MinorUnits::format((int) ($cents ?? 0));
    }
}; ?>

<div class="w-full max-w-7xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Sales Entry Report (Daily)') }}</h1>
        <div class="flex gap-2">
            <flux:button :href="route('reports.index')" wire:navigate variant="ghost">{{ __('Back to Reports') }}</flux:button>
            <flux:button href="{{ route('reports.sales-entry-daily.print') . '?' . http_build_query($exportParams) }}" target="_blank" variant="ghost">{{ __('Print') }}</flux:button>
            <flux:button href="{{ route('reports.sales-entry-daily.csv') . '?' . http_build_query($exportParams) }}" variant="ghost">{{ __('Export CSV') }}</flux:button>
            <flux:button href="{{ route('reports.sales-entry-daily.pdf') . '?' . http_build_query($exportParams) }}" variant="ghost">{{ __('Export PDF') }}</flux:button>
        </div>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="flex flex-wrap items-end gap-3">
            <x-reports.branch-select name="branch_id" :branches="$branches" />
            <div class="min-w-[220px] relative">
                <flux:input wire:model.live.debounce.300ms="customer_search" :label="__('Customer')" placeholder="{{ __('Search by name/phone/code') }}" />
                @if($customer_id === null && trim($customer_search) !== '')
                    <div class="absolute z-10 mt-1 w-full overflow-hidden rounded-md border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="max-h-64 overflow-auto">
                            @forelse ($customers as $c)
                                <button type="button" class="w-full px-3 py-2 text-left text-sm text-neutral-800 hover:bg-neutral-50 dark:text-neutral-100 dark:hover:bg-neutral-800/80" wire:click="selectCustomer({{ $c->id }})">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="font-medium">{{ $c->name }}</span>
                                        @if($c->customer_code)
                                            <span class="text-xs text-neutral-500 dark:text-neutral-400">{{ $c->customer_code }}</span>
                                        @endif
                                    </div>
                                    @if($c->phone)
                                        <div class="text-xs text-neutral-500 dark:text-neutral-400">{{ $c->phone }}</div>
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

    <div class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('S.I') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Date & Time') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Invoice Number') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('POS Ref') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Customer') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Sales Person') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Total Trade Revenue') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Discount') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Net Amount') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Cash') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Card') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Credit') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Total Collection') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                @forelse ($invoices as $inv)
                    @php
                        $tradeRevenueCents = (int) ($inv->subtotal_cents ?? 0);
                        $discountCents = (int) ($inv->discount_total_cents ?? 0);
                        $netAmountCents = (int) ($inv->total_cents ?? 0);
                        $paymentBreakdown = $this->paymentBreakdownCents($inv);
                        $cashCents = (int) ($paymentBreakdown['cash_cents'] ?? 0);
                        $cardCents = (int) ($paymentBreakdown['card_cents'] ?? 0);
                        $creditCents = (int) ($paymentBreakdown['credit_cents'] ?? 0);
                        $totalCollectionCents = $cashCents + $cardCents + $creditCents;
                    @endphp
                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $invoices->firstItem() + $loop->index }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ ($inv->created_at ?? $inv->issue_date)?->format('Y-m-d H:i:s') }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $inv->invoice_number ?: ('#'.$inv->id) }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $inv->pos_reference ?? '-' }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $inv->customer?->name ?? '—' }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $inv->salesPerson?->username ?: ($inv->salesPerson?->name ?? '-') }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($tradeRevenueCents) }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($discountCents) }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($netAmountCents) }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($cashCents) }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($cardCents) }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($creditCents) }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($totalCollectionCents) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="13" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No invoices found.') }}</td></tr>
                @endforelse
                @if ($invoices->count() > 0)
                    <tr class="bg-neutral-50 dark:bg-neutral-800/70">
                        <td colspan="6" class="px-3 py-2 text-sm text-right font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Total') }}</td>
                        <td class="px-3 py-2 text-sm text-right font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($totals['trade_revenue_cents'] ?? 0) }}</td>
                        <td class="px-3 py-2 text-sm text-right font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($totals['discount_cents'] ?? 0) }}</td>
                        <td class="px-3 py-2 text-sm text-right font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($totals['net_amount_cents'] ?? 0) }}</td>
                        <td class="px-3 py-2 text-sm text-right font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($totals['cash_cents'] ?? 0) }}</td>
                        <td class="px-3 py-2 text-sm text-right font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($totals['card_cents'] ?? 0) }}</td>
                        <td class="px-3 py-2 text-sm text-right font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($totals['credit_cents'] ?? 0) }}</td>
                        <td class="px-3 py-2 text-sm text-right font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($totals['total_collection_cents'] ?? 0) }}</td>
                    </tr>
                @endif
            </tbody>
        </table>
    </div>

    <div>{{ $invoices->links() }}</div>
</div>
