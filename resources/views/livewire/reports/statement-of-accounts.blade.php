<?php

use App\Models\ArInvoice;
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
            'summary' => $this->summary(),
            'branches' => Schema::hasTable('branches') ? DB::table('branches')->where('is_active', 1)->orderBy('name')->get() : collect(),
            'exportParams' => $this->exportParams(),
        ];
    }

    private function summary(): array
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

        $openingInvoices = $dateFrom ? (int) $invoiceBase->clone()->whereDate('issue_date', '<', $dateFrom)->sum('total_cents') : 0;
        $openingPayments = $dateFrom ? (int) $paymentBase->clone()->whereDate('received_at', '<', $dateFrom)->sum('amount_cents') : 0;
        $opening = $openingInvoices - $openingPayments;

        $periodInvoices = (int) $invoiceBase->clone()
            ->when($dateFrom, fn ($q) => $q->whereDate('issue_date', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('issue_date', '<=', $dateTo))
            ->sum('total_cents');

        $periodPayments = (int) $paymentBase->clone()
            ->when($dateFrom, fn ($q) => $q->whereDate('received_at', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('received_at', '<=', $dateTo))
            ->sum('amount_cents');

        $closing = $opening + $periodInvoices - $periodPayments;

        return [
            'opening' => $opening,
            'invoices' => $periodInvoices,
            'payments' => $periodPayments,
            'closing' => $closing,
        ];
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

<div class="w-full max-w-5xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Statement of Accounts') }}</h1>
        <div class="flex gap-2">
            <flux:button :href="route('reports.index')" wire:navigate variant="ghost">{{ __('Back to Reports') }}</flux:button>
            <flux:button href="{{ route('reports.statement-of-accounts.print') . '?' . http_build_query($exportParams) }}" target="_blank" variant="ghost">{{ __('Print') }}</flux:button>
            <flux:button href="{{ route('reports.statement-of-accounts.csv') . '?' . http_build_query($exportParams) }}" variant="ghost">{{ __('Export CSV') }}</flux:button>
            <flux:button href="{{ route('reports.statement-of-accounts.pdf') . '?' . http_build_query($exportParams) }}" variant="ghost">{{ __('Export PDF') }}</flux:button>
        </div>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="flex flex-wrap items-end gap-3">
            <x-reports.branch-select name="branch_id" :branches="$branches" />
            <x-reports.date-range fromName="date_from" toName="date_to" />
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="text-sm text-neutral-500">{{ __('Opening') }}</div>
            <div class="text-lg font-semibold">{{ $this->formatMoney($summary['opening']) }}</div>
        </div>
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="text-sm text-neutral-500">{{ __('Invoices') }}</div>
            <div class="text-lg font-semibold">{{ $this->formatMoney($summary['invoices']) }}</div>
        </div>
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="text-sm text-neutral-500">{{ __('Payments') }}</div>
            <div class="text-lg font-semibold">{{ $this->formatMoney($summary['payments']) }}</div>
        </div>
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="text-sm text-neutral-500">{{ __('Closing') }}</div>
            <div class="text-lg font-semibold">{{ $this->formatMoney($summary['closing']) }}</div>
        </div>
    </div>
</div>
