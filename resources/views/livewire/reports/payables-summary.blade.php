<?php

use App\Models\ApInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public ?string $date_from = null;
    public ?string $date_to = null;

    public function with(): array
    {
        return [
            'summary' => $this->summary(),
            'exportParams' => $this->exportParams(),
        ];
    }

    private function summary(): array
    {
        $invoices = ApInvoice::query()
            ->whereIn('status', ['posted', 'partially_paid', 'paid'])
            ->when($this->date_from, fn ($q) => $q->whereDate('invoice_date', '>=', $this->date_from))
            ->when($this->date_to, fn ($q) => $q->whereDate('invoice_date', '<=', $this->date_to))
            ->get();

        $asOf = $this->date_to ? now()->parse($this->date_to) : now();
        $byStatus = $invoices->groupBy('status')->map(function ($group, $status) {
            return [
                'status' => $status,
                'count' => $group->count(),
                'total' => (float) $group->sum('total_amount'),
                'outstanding' => (float) $group->sum(fn ($inv) => max((float) $inv->total_amount - (float) $inv->paidAmount(), 0)),
            ];
        })->values();

        $overdue = $invoices->filter(fn ($inv) => $inv->outstandingAmount() > 0 && $inv->due_date && $inv->due_date->lessThan($asOf));

        return [
            'total' => (float) $invoices->sum('total_amount'),
            'outstanding' => (float) $invoices->sum(fn ($inv) => max((float) $inv->total_amount - (float) $inv->paidAmount(), 0)),
            'overdue' => (float) $overdue->sum(fn ($inv) => max((float) $inv->total_amount - (float) $inv->paidAmount(), 0)),
            'by_status' => $byStatus,
        ];
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

<div class="w-full max-w-6xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Payables Summary') }}</h1>
        <div class="flex gap-2">
            <flux:button :href="route('reports.index')" wire:navigate variant="ghost">{{ __('Back to Reports') }}</flux:button>
            <flux:button href="{{ route('reports.payables-summary.print') . '?' . http_build_query($exportParams) }}" target="_blank" variant="ghost">{{ __('Print') }}</flux:button>
            <flux:button href="{{ route('reports.payables-summary.csv') . '?' . http_build_query($exportParams) }}" variant="ghost">{{ __('Export CSV') }}</flux:button>
            <flux:button href="{{ route('reports.payables-summary.pdf') . '?' . http_build_query($exportParams) }}" variant="ghost">{{ __('Export PDF') }}</flux:button>
        </div>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="flex flex-wrap items-end gap-3">
            <x-reports.date-range fromName="date_from" toName="date_to" />
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="text-sm text-neutral-500">{{ __('Total') }}</div>
            <div class="text-lg font-semibold">{{ $this->formatMoney($summary['total']) }}</div>
        </div>
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="text-sm text-neutral-500">{{ __('Outstanding') }}</div>
            <div class="text-lg font-semibold">{{ $this->formatMoney($summary['outstanding']) }}</div>
        </div>
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="text-sm text-neutral-500">{{ __('Overdue') }}</div>
            <div class="text-lg font-semibold">{{ $this->formatMoney($summary['overdue']) }}</div>
        </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Status') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Count') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Total') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Outstanding') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                @forelse ($summary['by_status'] as $row)
                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $row['status'] }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ $row['count'] }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ $this->formatMoney($row['total']) }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ $this->formatMoney($row['outstanding']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No data found.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
