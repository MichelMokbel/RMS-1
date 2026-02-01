<?php

use App\Models\ApInvoice;
use App\Models\ApPayment;
use App\Models\Supplier;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public ?int $supplier_id = null;
    public ?string $date_from = null;
    public ?string $date_to = null;

    public function with(): array
    {
        return [
            'statement' => $this->buildStatement(),
            'suppliers' => Schema::hasTable('suppliers') ? Supplier::orderBy('name')->get() : collect(),
            'exportParams' => $this->exportParams(),
        ];
    }

    private function buildStatement(): array
    {
        if (! $this->supplier_id) {
            return ['opening' => 0.0, 'entries' => collect()];
        }

        $dateFrom = $this->date_from ? now()->parse($this->date_from)->startOfDay() : null;
        $dateTo = $this->date_to ? now()->parse($this->date_to)->endOfDay() : null;

        $invoiceBase = ApInvoice::query()
            ->where('supplier_id', $this->supplier_id)
            ->whereIn('status', ['posted', 'partially_paid', 'paid']);

        $paymentBase = ApPayment::query()
            ->where('supplier_id', $this->supplier_id);

        $openingInvoices = $dateFrom ? (float) $invoiceBase->clone()->whereDate('invoice_date', '<', $dateFrom)->sum('total_amount') : 0.0;
        $openingPayments = $dateFrom ? (float) $paymentBase->clone()->whereDate('payment_date', '<', $dateFrom)->sum('amount') : 0.0;
        $opening = $openingInvoices - $openingPayments;

        $invoiceRange = $invoiceBase->clone()
            ->when($dateFrom, fn ($q) => $q->whereDate('invoice_date', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('invoice_date', '<=', $dateTo))
            ->get()
            ->map(function (ApInvoice $inv) {
                $amount = (float) ($inv->total_amount ?? 0);
                return [
                    'date' => $inv->invoice_date?->format('Y-m-d') ?? '',
                    'description' => __('Invoice :no', ['no' => $inv->invoice_number]),
                    'debit' => 0.0,
                    'credit' => $amount,
                ];
            });

        $paymentRange = $paymentBase->clone()
            ->when($dateFrom, fn ($q) => $q->whereDate('payment_date', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('payment_date', '<=', $dateTo))
            ->get()
            ->map(function (ApPayment $pay) {
                $amount = (float) ($pay->amount ?? 0);
                return [
                    'date' => $pay->payment_date?->format('Y-m-d') ?? '',
                    'description' => __('Payment #:id', ['id' => $pay->id]),
                    'debit' => $amount,
                    'credit' => 0.0,
                ];
            });

        $entries = $invoiceRange->merge($paymentRange)->sortBy('date')->values();

        return ['opening' => $opening, 'entries' => $entries];
    }

    public function exportParams(): array
    {
        return array_filter([
            'supplier_id' => $this->supplier_id,
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
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Supplier Statement') }}</h1>
        <div class="flex gap-2">
            <flux:button :href="route('reports.index')" wire:navigate variant="ghost">{{ __('Back to Reports') }}</flux:button>
            <flux:button href="{{ route('reports.supplier-statement.print') . '?' . http_build_query($exportParams) }}" target="_blank" variant="ghost">{{ __('Print') }}</flux:button>
            <flux:button href="{{ route('reports.supplier-statement.csv') . '?' . http_build_query($exportParams) }}" variant="ghost">{{ __('Export CSV') }}</flux:button>
            <flux:button href="{{ route('reports.supplier-statement.pdf') . '?' . http_build_query($exportParams) }}" variant="ghost">{{ __('Export PDF') }}</flux:button>
        </div>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="flex flex-wrap items-end gap-3">
            <div class="min-w-[220px]">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Supplier') }}</label>
                <select wire:model.live="supplier_id" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="">{{ __('Select') }}</option>
                    @foreach ($suppliers as $s)
                        <option value="{{ $s->id }}">{{ $s->name }}</option>
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
                @php $balance = $statement['opening']; @endphp
                <tr class="bg-neutral-50/60 dark:bg-neutral-800/50">
                    <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ __('Opening') }}</td>
                    <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">—</td>
                    <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">—</td>
                    <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">—</td>
                    <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($balance) }}</td>
                </tr>
                @forelse ($statement['entries'] as $entry)
                    @php $balance += (float) $entry['credit'] - (float) $entry['debit']; @endphp
                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $entry['date'] }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $entry['description'] }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ $this->formatMoney($entry['debit']) }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ $this->formatMoney($entry['credit']) }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($balance) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('Select a supplier to view statement.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
