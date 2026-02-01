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
    public ?string $date_from = null;
    public ?string $date_to = null;

    public function with(): array
    {
        return [
            'rows' => $this->query(),
            'branches' => Schema::hasTable('branches') ? DB::table('branches')->where('is_active', 1)->orderBy('name')->get() : collect(),
            'exportParams' => $this->exportParams(),
        ];
    }

    private function query()
    {
        $dateFrom = $this->date_from ? now()->parse($this->date_from)->startOfDay() : null;
        $dateTo = $this->date_to ? now()->parse($this->date_to)->endOfDay() : null;

        $invoiceBase = ArInvoice::query()
            ->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->whereIn('type', ['invoice', 'credit_note'])
            ->when($this->branch_id > 0, fn ($q) => $q->where('branch_id', $this->branch_id));

        $paymentBase = Payment::query()
            ->where('source', 'ar')
            ->when($this->branch_id > 0, fn ($q) => $q->where('branch_id', $this->branch_id));

        $invoiceBefore = $dateFrom
            ? $invoiceBase->clone()
                ->whereDate('issue_date', '<', $dateFrom)
                ->selectRaw('customer_id, SUM(total_cents) as total')
                ->groupBy('customer_id')
                ->pluck('total', 'customer_id')
            : collect();

        $invoiceRange = $invoiceBase->clone()
            ->when($dateFrom, fn ($q) => $q->whereDate('issue_date', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('issue_date', '<=', $dateTo))
            ->selectRaw('customer_id, SUM(total_cents) as total')
            ->groupBy('customer_id')
            ->pluck('total', 'customer_id');

        $paymentBefore = $dateFrom
            ? $paymentBase->clone()
                ->whereDate('received_at', '<', $dateFrom)
                ->selectRaw('customer_id, SUM(amount_cents) as total')
                ->groupBy('customer_id')
                ->pluck('total', 'customer_id')
            : collect();

        $paymentRange = $paymentBase->clone()
            ->when($dateFrom, fn ($q) => $q->whereDate('received_at', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('received_at', '<=', $dateTo))
            ->selectRaw('customer_id, SUM(amount_cents) as total')
            ->groupBy('customer_id')
            ->pluck('total', 'customer_id');

        $customerIds = collect()
            ->merge($invoiceBefore->keys())
            ->merge($invoiceRange->keys())
            ->merge($paymentBefore->keys())
            ->merge($paymentRange->keys())
            ->unique()
            ->values();

        $customers = Customer::whereIn('id', $customerIds)->get()->keyBy('id');

        return $customerIds->map(function ($customerId) use ($customers, $invoiceBefore, $invoiceRange, $paymentBefore, $paymentRange) {
            $opening = (int) ($invoiceBefore[$customerId] ?? 0) - (int) ($paymentBefore[$customerId] ?? 0);
            $invoices = (int) ($invoiceRange[$customerId] ?? 0);
            $payments = (int) ($paymentRange[$customerId] ?? 0);
            $closing = $opening + $invoices - $payments;

            return [
                'customer_id' => (int) $customerId,
                'customer_name' => $customers[$customerId]->name ?? '—',
                'opening' => $opening,
                'invoices' => $invoices,
                'payments' => $payments,
                'closing' => $closing,
            ];
        });
    }

    public function exportParams(): array
    {
        return array_filter([
            'branch_id' => $this->branch_id ?: null,
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
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('All Customers Statement') }}</h1>
        <div class="flex gap-2">
            <flux:button :href="route('reports.index')" wire:navigate variant="ghost">{{ __('Back to Reports') }}</flux:button>
            <flux:button href="{{ route('reports.customers-statement.print') . '?' . http_build_query($exportParams) }}" target="_blank" variant="ghost">{{ __('Print') }}</flux:button>
            <flux:button href="{{ route('reports.customers-statement.csv') . '?' . http_build_query($exportParams) }}" variant="ghost">{{ __('Export CSV') }}</flux:button>
            <flux:button href="{{ route('reports.customers-statement.pdf') . '?' . http_build_query($exportParams) }}" variant="ghost">{{ __('Export PDF') }}</flux:button>
        </div>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="flex flex-wrap items-end gap-3">
            <x-reports.branch-select name="branch_id" :branches="$branches" />
            <x-reports.date-range fromName="date_from" toName="date_to" />
        </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Customer') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Opening') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Invoices') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Payments') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Closing') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                @forelse ($rows as $row)
                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $row['customer_name'] }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ $this->formatMoney($row['opening']) }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ $this->formatMoney($row['invoices']) }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ $this->formatMoney($row['payments']) }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($row['closing']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No customers found.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
