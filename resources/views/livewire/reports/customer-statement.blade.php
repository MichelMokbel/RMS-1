<?php

use App\Models\ArInvoice;
use App\Models\Customer;
use App\Models\Payment;
use App\Support\Money\MinorUnits;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public int $branch_id = 0;
    public ?int $customer_id = null;
    public ?string $date_from = null;
    public ?string $date_to = null;

    public function with(): array
    {
        return [
            'statement' => $this->buildStatement(),
            'branches' => Schema::hasTable('branches') ? DB::table('branches')->where('is_active', 1)->orderBy('name')->get() : collect(),
            'customers' => Schema::hasTable('customers') ? Customer::orderBy('name')->limit(200)->get() : collect(),
            'exportParams' => $this->exportParams(),
        ];
    }

    private function buildStatement(): array
    {
        if (! $this->customer_id) {
            return ['opening_cents' => 0, 'entries' => collect()];
        }

        $dateFrom = $this->date_from ? now()->parse($this->date_from)->startOfDay() : null;
        $dateTo = $this->date_to ? now()->parse($this->date_to)->endOfDay() : null;

        $invoiceBase = ArInvoice::query()
            ->where('customer_id', $this->customer_id)
            ->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->whereIn('type', ['invoice', 'credit_note'])
            ->when($this->branch_id > 0, fn ($q) => $q->where('branch_id', $this->branch_id));

        $paymentBase = Payment::query()
            ->where('source', 'ar')
            ->where('customer_id', $this->customer_id)
            ->when($this->branch_id > 0, fn ($q) => $q->where('branch_id', $this->branch_id));

        $openingInvoices = $dateFrom ? (int) $invoiceBase->clone()->whereDate('issue_date', '<', $dateFrom)->sum('total_cents') : 0;
        $openingPayments = $dateFrom ? (int) $paymentBase->clone()->whereDate('received_at', '<', $dateFrom)->sum('amount_cents') : 0;
        $opening = $openingInvoices - $openingPayments;

        $invoiceRange = $invoiceBase->clone()
            ->when($dateFrom, fn ($q) => $q->whereDate('issue_date', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('issue_date', '<=', $dateTo))
            ->get()
            ->map(function (ArInvoice $inv) {
                $amount = (int) ($inv->total_cents ?? 0);
                $debit = $amount > 0 ? $amount : 0;
                $credit = $amount < 0 ? abs($amount) : 0;

                return [
                    'date' => $inv->issue_date?->format('Y-m-d') ?? '',
                    'description' => $inv->type === 'credit_note'
                        ? __('Credit Note :no', ['no' => $inv->invoice_number ?: '#'.$inv->id])
                        : __('Invoice :no', ['no' => $inv->invoice_number ?: '#'.$inv->id]),
                    'debit_cents' => $debit,
                    'credit_cents' => $credit,
                ];
            });

        $paymentRange = $paymentBase->clone()
            ->when($dateFrom, fn ($q) => $q->whereDate('received_at', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('received_at', '<=', $dateTo))
            ->get()
            ->map(function (Payment $pay) {
                $amount = (int) ($pay->amount_cents ?? 0);
                return [
                    'date' => $pay->received_at?->format('Y-m-d') ?? '',
                    'description' => __('Payment #:id', ['id' => $pay->id]),
                    'debit_cents' => 0,
                    'credit_cents' => $amount,
                ];
            });

        $entries = $invoiceRange->merge($paymentRange)->sortBy('date')->values();

        return ['opening_cents' => $opening, 'entries' => $entries];
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
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Customer Statement') }}</h1>
        <div class="flex gap-2">
            <flux:button :href="route('reports.index')" wire:navigate variant="ghost">{{ __('Back to Reports') }}</flux:button>
            <flux:button href="{{ route('reports.customer-statement.print') . '?' . http_build_query($exportParams) }}" target="_blank" variant="ghost">{{ __('Print') }}</flux:button>
            <flux:button href="{{ route('reports.customer-statement.csv') . '?' . http_build_query($exportParams) }}" variant="ghost">{{ __('Export CSV') }}</flux:button>
            <flux:button href="{{ route('reports.customer-statement.pdf') . '?' . http_build_query($exportParams) }}" variant="ghost">{{ __('Export PDF') }}</flux:button>
        </div>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="flex flex-wrap items-end gap-3">
            <x-reports.branch-select name="branch_id" :branches="$branches" />
            <div class="min-w-[220px]">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Customer') }}</label>
                <select wire:model.live="customer_id" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="">{{ __('Select') }}</option>
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
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Date') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Description') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Debit') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Credit') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Balance') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                @php $balance = $statement['opening_cents']; @endphp
                <tr class="bg-neutral-50/60 dark:bg-neutral-800/50">
                    <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ __('Opening') }}</td>
                    <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">—</td>
                    <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">—</td>
                    <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">—</td>
                    <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($balance) }}</td>
                </tr>
                @forelse ($statement['entries'] as $entry)
                    @php $balance += (int) $entry['debit_cents'] - (int) $entry['credit_cents']; @endphp
                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $entry['date'] }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $entry['description'] }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ $this->formatMoney($entry['debit_cents']) }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ $this->formatMoney($entry['credit_cents']) }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($balance) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('Select a customer to view statement.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
