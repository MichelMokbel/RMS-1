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
        if (in_array($name, ['branch_id', 'customer_id', 'date_from', 'date_to'], true)) {
            $this->resetPage();
        }
    }

    public function with(): array
    {
        $baseQuery = $this->query();
        $invoices = (clone $baseQuery)->paginate(15);
        $totals = [
            'trade_revenue_cents' => (int) ((clone $baseQuery)->sum('subtotal_cents') ?? 0),
            'discount_cents' => (int) ((clone $baseQuery)->sum('discount_total_cents') ?? 0),
            'net_amount_cents' => (int) ((clone $baseQuery)->sum('total_cents') ?? 0),
            'cash_cents' => (int) ((clone $baseQuery)->whereRaw('LOWER(payment_type) = ?', ['cash'])->sum('total_cents') ?? 0),
            'card_cents' => (int) ((clone $baseQuery)->whereRaw('LOWER(payment_type) = ?', ['card'])->sum('total_cents') ?? 0),
            'credit_cents' => (int) ((clone $baseQuery)->whereRaw('LOWER(payment_type) = ?', ['credit'])->sum('total_cents') ?? 0),
        ];
        $totals['total_collection_cents'] = $totals['cash_cents'] + $totals['card_cents'] + $totals['credit_cents'];

        return [
            'invoices' => $invoices,
            'totals' => $totals,
            'branches' => Schema::hasTable('branches') ? DB::table('branches')->where('is_active', 1)->orderBy('name')->get() : collect(),
            'customers' => Schema::hasTable('customers') ? Customer::orderBy('name')->limit(200)->get() : collect(),
            'exportParams' => $this->exportParams(),
        ];
    }

    private function query()
    {
        return ArInvoice::query()
            ->with(['customer', 'salesPerson'])
            ->where('type', 'invoice')
            ->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->when($this->branch_id > 0, fn ($q) => $q->where('branch_id', $this->branch_id))
            ->when($this->customer_id, fn ($q) => $q->where('customer_id', $this->customer_id))
            ->when($this->date_from, fn ($q) => $q->whereDate('issue_date', '>=', $this->date_from))
            ->when($this->date_to, fn ($q) => $q->whereDate('issue_date', '<=', $this->date_to))
            ->orderByDesc('issue_date')
            ->orderByDesc('id');
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
            <div class="min-w-[220px]">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Customer') }}</label>
                <select wire:model.live="customer_id" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="">{{ __('All') }}</option>
                    @foreach ($customers as $c)
                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>
            <x-reports.date-range fromName="date_from" toName="date_to" />
        </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('S.I') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Date') }}</th>
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
                        $paymentType = strtolower((string) ($inv->payment_type ?? 'credit'));
                        $cashCents = $paymentType === 'cash' ? $netAmountCents : 0;
                        $cardCents = $paymentType === 'card' ? $netAmountCents : 0;
                        $creditCents = $paymentType === 'credit' ? $netAmountCents : 0;
                        $totalCollectionCents = $cashCents + $cardCents + $creditCents;
                    @endphp
                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $invoices->firstItem() + $loop->index }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $inv->issue_date?->format('Y-m-d') }}</td>
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
