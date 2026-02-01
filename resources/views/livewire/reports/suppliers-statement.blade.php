<?php

use App\Models\ApInvoice;
use App\Models\ApPayment;
use App\Models\Supplier;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public ?string $date_from = null;
    public ?string $date_to = null;

    public function with(): array
    {
        return [
            'rows' => $this->query(),
            'exportParams' => $this->exportParams(),
        ];
    }

    private function query()
    {
        $dateFrom = $this->date_from ? now()->parse($this->date_from)->startOfDay() : null;
        $dateTo = $this->date_to ? now()->parse($this->date_to)->endOfDay() : null;

        $invoiceBase = ApInvoice::query()
            ->whereIn('status', ['posted', 'partially_paid', 'paid']);

        $paymentBase = ApPayment::query();

        $invoiceBefore = $dateFrom
            ? $invoiceBase->clone()
                ->whereDate('invoice_date', '<', $dateFrom)
                ->selectRaw('supplier_id, SUM(total_amount) as total')
                ->groupBy('supplier_id')
                ->pluck('total', 'supplier_id')
            : collect();

        $invoiceRange = $invoiceBase->clone()
            ->when($dateFrom, fn ($q) => $q->whereDate('invoice_date', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('invoice_date', '<=', $dateTo))
            ->selectRaw('supplier_id, SUM(total_amount) as total')
            ->groupBy('supplier_id')
            ->pluck('total', 'supplier_id');

        $paymentBefore = $dateFrom
            ? $paymentBase->clone()
                ->whereDate('payment_date', '<', $dateFrom)
                ->selectRaw('supplier_id, SUM(amount) as total')
                ->groupBy('supplier_id')
                ->pluck('total', 'supplier_id')
            : collect();

        $paymentRange = $paymentBase->clone()
            ->when($dateFrom, fn ($q) => $q->whereDate('payment_date', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('payment_date', '<=', $dateTo))
            ->selectRaw('supplier_id, SUM(amount) as total')
            ->groupBy('supplier_id')
            ->pluck('total', 'supplier_id');

        $supplierIds = collect()
            ->merge($invoiceBefore->keys())
            ->merge($invoiceRange->keys())
            ->merge($paymentBefore->keys())
            ->merge($paymentRange->keys())
            ->unique()
            ->values();

        $suppliers = Supplier::whereIn('id', $supplierIds)->get()->keyBy('id');

        return $supplierIds->map(function ($supplierId) use ($suppliers, $invoiceBefore, $invoiceRange, $paymentBefore, $paymentRange) {
            $opening = (float) ($invoiceBefore[$supplierId] ?? 0) - (float) ($paymentBefore[$supplierId] ?? 0);
            $invoices = (float) ($invoiceRange[$supplierId] ?? 0);
            $payments = (float) ($paymentRange[$supplierId] ?? 0);
            $closing = $opening + $invoices - $payments;

            return [
                'supplier_id' => (int) $supplierId,
                'supplier_name' => $suppliers[$supplierId]->name ?? '—',
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
            'date_from' => $this->date_from,
            'date_to' => $this->date_to,
        ], fn ($v) => $v !== null && $v !== '');
    }

    public function formatMoney(?float $amount): string
    {
        return number_format((float) ($amount ?? 0), 2, '.', '');
    }
}; ?>

<div class="w-full max-w-7xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('All Suppliers Statement') }}</h1>
        <div class="flex gap-2">
            <flux:button :href="route('reports.index')" wire:navigate variant="ghost">{{ __('Back to Reports') }}</flux:button>
            <flux:button href="{{ route('reports.suppliers-statement.print') . '?' . http_build_query($exportParams) }}" target="_blank" variant="ghost">{{ __('Print') }}</flux:button>
            <flux:button href="{{ route('reports.suppliers-statement.csv') . '?' . http_build_query($exportParams) }}" variant="ghost">{{ __('Export CSV') }}</flux:button>
            <flux:button href="{{ route('reports.suppliers-statement.pdf') . '?' . http_build_query($exportParams) }}" variant="ghost">{{ __('Export PDF') }}</flux:button>
        </div>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="flex flex-wrap items-end gap-3">
            <x-reports.date-range fromName="date_from" toName="date_to" />
        </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Supplier') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Opening') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Invoices') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Payments') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Closing') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                @forelse ($rows as $row)
                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $row['supplier_name'] }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ $this->formatMoney($row['opening']) }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ $this->formatMoney($row['invoices']) }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ $this->formatMoney($row['payments']) }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($row['closing']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No suppliers found.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
