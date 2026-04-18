<?php

use App\Models\ArInvoice;
use App\Models\Customer;
use App\Support\Money\MinorUnits;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public int $branch_id = 0;
    public ?int $customer_id = null;
    public string $customer_search = '';
    public ?string $date_from = null;
    public ?string $date_to = null;
    public bool $only_unpaid = false;

    public function mount(): void
    {
        $this->date_from = now()->startOfMonth()->toDateString();
        $this->date_to = now()->endOfMonth()->toDateString();
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
        $rows = $this->statementRows();

        $customers = collect();
        if (Schema::hasTable('customers') && $this->customer_id === null && trim($this->customer_search) !== '') {
            $customers = Customer::query()
                ->active()
                ->search($this->customer_search)
                ->orderBy('name')
                ->limit(25)
                ->get();
        }

        return [
            'rows' => $rows,
            'summary' => $this->statementSummary($rows),
            'aging' => $this->agingSummary(),
            'branches' => Schema::hasTable('branches') ? DB::table('branches')->where('is_active', 1)->orderBy('name')->get() : collect(),
            'customers' => $customers,
            'exportParams' => $this->exportParams(),
        ];
    }

    private function agingAsOf(Carbon $date): Carbon
    {
        $today = now()->startOfDay();
        $candidate = $date->copy()->startOfDay();

        return $candidate->greaterThan($today) ? $today : $candidate;
    }

    /**
     * @return Collection<int, array<string, int|string>>
     */
    private function statementRows(): Collection
    {
        if (! $this->customer_id) {
            return collect();
        }

        $dateFrom = $this->date_from ? now()->parse($this->date_from)->startOfDay() : now()->startOfMonth()->startOfDay();
        $dateTo   = $this->date_to   ? now()->parse($this->date_to)->endOfDay()     : now()->endOfMonth()->endOfDay();
        $asOf     = $this->agingAsOf($dateTo);

        // ── Invoices ──────────────────────────────────────────────────────────
        $invoices = ArInvoice::query()
            ->where('customer_id', $this->customer_id)
            ->where('type', 'invoice')
            ->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->when($this->only_unpaid, fn ($q) => $q->where('balance_cents', '>', 0))
            ->when($this->branch_id > 0, fn ($q) => $q->where('branch_id', $this->branch_id))
            ->whereDate('issue_date', '>=', $dateFrom)
            ->whereDate('issue_date', '<=', $dateTo)
            ->orderBy('issue_date')
            ->orderBy('id')
            ->get();

        // ── Payment receipts ──────────────────────────────────────────────────
        $payments = collect();
        if (Schema::hasTable('payments')) {
            $payments = DB::table('payments')
                ->where('customer_id', $this->customer_id)
                ->where('source', 'ar')
                ->whereNull('voided_at')
                ->when($this->branch_id > 0, fn ($q) => $q->where('branch_id', $this->branch_id))
                ->where('received_at', '>=', $dateFrom)
                ->where('received_at', '<=', $dateTo)
                ->orderBy('received_at')
                ->orderBy('id')
                ->get();
        }

        // ── Branch name lookup ────────────────────────────────────────────────
        $allBranchIds = $invoices->pluck('branch_id')
            ->merge($payments->pluck('branch_id'))
            ->filter()->unique()->values();

        $branchNames = (Schema::hasTable('branches') && $allBranchIds->isNotEmpty())
            ? DB::table('branches')->whereIn('id', $allBranchIds)->pluck('name', 'id')
            : collect();

        // ── Map invoice rows ──────────────────────────────────────────────────
        $invoiceRows = $invoices->map(function (ArInvoice $invoice) use ($asOf, $branchNames): array {
            $dueDate     = $invoice->due_date ?: $invoice->issue_date;
            $days        = $dueDate ? (int) floor((float) $dueDate->diffInDays($asOf, false)) : 0;
            $agingLabel  = $days <= 0 ? __('Not Due') : $days . ' ' . __('Days');
            $paymentType = strtolower((string) ($invoice->payment_type ?? 'credit'));

            // balance_cents is the authoritative remaining balance — updated by every
            // payment path (AR, POS, etc.).  Derive "paid" from it so the row is
            // self-consistent even for old invoices with no payments table records.
            $totalCents   = (int) ($invoice->total_cents ?? 0);
            $balanceCents = max(0, (int) ($invoice->balance_cents ?? 0));
            $paidCents    = max(0, $totalCents - $balanceCents);

            return [
                'row_type'      => 'invoice',
                'sort_date'     => $invoice->issue_date?->timestamp ?? 0,
                'document_no'   => $invoice->invoice_number ?: (string) $invoice->id,
                'document_type' => 'AR Invoice',
                'location'      => (string) ($branchNames[(int) $invoice->branch_id] ?? ('Branch '.$invoice->branch_id)),
                'type'          => $paymentType === 'credit' ? 'On Credit' : ucfirst((string) ($invoice->payment_type ?: 'Credit')),
                'date'          => $invoice->issue_date?->format('d-M-Y') ?? '-',
                'due_date'      => $dueDate?->format('d-M-Y') ?? '-',
                'reference_no'  => $invoice->lpo_reference ?: ($invoice->pos_reference ?: '-'),
                'amount_cents'  => $totalCents,
                'paid_cents'    => $paidCents,
                'balance_cents' => $balanceCents,
                'aging_label'   => $agingLabel,
                'payment_no'    => '-',
            ];
        });

        // ── Map payment rows ──────────────────────────────────────────────────
        $paymentRows = $payments->map(function (object $payment) use ($branchNames): array {
            $method     = ucwords(str_replace('_', ' ', (string) ($payment->method ?? '')));
            $receivedAt = $payment->received_at ? now()->parse($payment->received_at) : null;

            return [
                'row_type'      => 'payment',
                'sort_date'     => $receivedAt?->timestamp ?? 0,
                'document_no'   => $payment->reference ?: ('PMT-'.$payment->id),
                'document_type' => 'Payment Receipt',
                'location'      => (string) ($branchNames[(int) $payment->branch_id] ?? ('Branch '.$payment->branch_id)),
                'type'          => $method ?: 'Payment',
                'date'          => $receivedAt?->format('d-M-Y') ?? '-',
                'due_date'      => '-',
                'reference_no'  => $payment->reference ?: '-',
                'amount_cents'  => 0,
                'paid_cents'    => (int) $payment->amount_cents,
                'balance_cents' => 0,
                'aging_label'   => '-',
                'payment_no'    => $payment->reference ?: ('PMT-'.$payment->id),
            ];
        });

        // ── Merge, sort by date, re-number ────────────────────────────────────
        return $invoiceRows->merge($paymentRows)
            ->sortBy([['sort_date', 'asc'], ['row_type', 'asc']])
            ->values()
            ->map(function (array $row, int $index): array {
                $row['line_no'] = $index + 1;
                return $row;
            });
    }

    /**
     * @param  Collection<int, array<string, int|string>>  $rows
     * @return array{period_amount_cents:int,period_received_cents:int,period_balance_cents:int,previous_balance_cents:int,previous_advance_cents:int,total_outstanding_cents:int}
     */
    private function statementSummary(Collection $rows): array
    {
        $dateFrom = $this->date_from ? now()->parse($this->date_from)->startOfDay() : now()->startOfMonth()->startOfDay();

        // Use balance_cents as the authoritative outstanding per invoice.
        // Derive "received" as total − balance so it captures all payment channels
        // (POS, cash, AR) — not just records in the payments table.
        $periodAmount   = (int) $rows->where('row_type', 'invoice')->sum('amount_cents');
        $periodBalance  = (int) $rows->where('row_type', 'invoice')->sum('balance_cents');
        $periodReceived = $periodAmount - $periodBalance;

        // Previous period: unpaid invoice balance before the date range
        $previousInvoiceBalance = 0;
        // Previous period: advance payments made before the date range not yet allocated to any invoice
        $previousAdvance = 0;

        if ($this->customer_id) {
            $previousInvoiceBalance = (int) ArInvoice::query()
                ->where('customer_id', $this->customer_id)
                ->where('type', 'invoice')
                ->whereIn('status', ['issued', 'partially_paid', 'paid'])
                ->where('balance_cents', '>', 0)
                ->when($this->branch_id > 0, fn ($q) => $q->where('branch_id', $this->branch_id))
                ->whereDate('issue_date', '<', $dateFrom)
                ->sum('balance_cents');

            if (Schema::hasTable('payments') && Schema::hasTable('payment_allocations')) {
                // Total payments received before the period
                $prevPaidTotal = (int) DB::table('payments as p')
                    ->where('p.customer_id', $this->customer_id)
                    ->where('p.source', 'ar')
                    ->whereNull('p.voided_at')
                    ->where('p.received_at', '<', $dateFrom)
                    ->when($this->branch_id > 0, fn ($q) => $q->where('p.branch_id', $this->branch_id))
                    ->sum('p.amount_cents');

                // Total of those payments already allocated to invoices
                $prevAllocatedTotal = (int) DB::table('payment_allocations as pa')
                    ->join('payments as p', 'p.id', '=', 'pa.payment_id')
                    ->where('p.customer_id', $this->customer_id)
                    ->where('p.source', 'ar')
                    ->whereNull('p.voided_at')
                    ->whereNull('pa.voided_at')
                    ->where('p.received_at', '<', $dateFrom)
                    ->when($this->branch_id > 0, fn ($q) => $q->where('p.branch_id', $this->branch_id))
                    ->sum('pa.amount_cents');

                $previousAdvance = max(0, $prevPaidTotal - $prevAllocatedTotal);
            }
        }

        $previousBalance = max(0, $previousInvoiceBalance - $previousAdvance);

        return [
            'period_amount_cents'    => $periodAmount,
            'period_received_cents'  => $periodReceived,
            'period_balance_cents'   => $periodBalance,
            'previous_balance_cents' => $previousBalance,
            'previous_advance_cents' => $previousAdvance,
            'total_outstanding_cents' => $previousBalance + $periodBalance,
        ];
    }

    /**
     * @return array{not_due:int,bucket_1_30:int,bucket_31_60:int,bucket_61_90:int,bucket_over_90:int,total:int}
     */
    private function agingSummary(): array
    {
        if (! $this->customer_id) {
            return [
                'not_due' => 0,
                'bucket_1_30' => 0,
                'bucket_31_60' => 0,
                'bucket_61_90' => 0,
                'bucket_over_90' => 0,
                'total' => 0,
            ];
        }

        $resolvedDateTo = $this->date_to ? now()->parse($this->date_to)->endOfDay() : now()->endOfMonth()->endOfDay();
        $asOf = $this->agingAsOf($resolvedDateTo);

        $invoices = ArInvoice::query()
            ->where('customer_id', $this->customer_id)
            ->where('type', 'invoice')
            ->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->where('balance_cents', '>', 0)
            ->when($this->branch_id > 0, fn ($q) => $q->where('branch_id', $this->branch_id))
            ->whereDate('issue_date', '<=', $asOf)
            ->get();

        $aging = [
            'not_due' => 0,
            'bucket_1_30' => 0,
            'bucket_31_60' => 0,
            'bucket_61_90' => 0,
            'bucket_over_90' => 0,
            'total' => 0,
        ];

        foreach ($invoices as $invoice) {
            $balance = (int) ($invoice->balance_cents ?? 0);
            if ($balance <= 0) {
                continue;
            }

            $dueDate = $invoice->due_date ?: $invoice->issue_date;
            $days = $dueDate ? (int) floor((float) $dueDate->diffInDays($asOf, false)) : 0;

            if ($days <= 0) {
                $aging['not_due'] += $balance;
            } elseif ($days <= 30) {
                $aging['bucket_1_30'] += $balance;
            } elseif ($days <= 60) {
                $aging['bucket_31_60'] += $balance;
            } elseif ($days <= 90) {
                $aging['bucket_61_90'] += $balance;
            } else {
                $aging['bucket_over_90'] += $balance;
            }

            $aging['total'] += $balance;
        }

        return $aging;
    }

    public function exportParams(): array
    {
        return array_filter([
            'branch_id' => $this->branch_id ?: null,
            'customer_id' => $this->customer_id,
            'date_from' => $this->date_from,
            'date_to' => $this->date_to,
            'only_unpaid' => $this->only_unpaid ? 1 : null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    public function formatMoney(?int $cents): string
    {
        return MinorUnits::format((int) ($cents ?? 0));
    }
}; ?>

<div class="app-page space-y-6">
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
        <div class="flex flex-col gap-3 xl:flex-row xl:items-end xl:flex-nowrap">
            <div class="xl:w-56 xl:flex-none">
                <x-reports.branch-select name="branch_id" :branches="$branches" />
            </div>
            <div class="relative xl:min-w-0 xl:flex-1">
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
            <x-reports.date-range fromName="date_from" toName="date_to" class="flex-nowrap xl:flex-none" />
            <div class="pb-1 xl:flex-none">
                <flux:checkbox wire:model.live="only_unpaid" :label="__('Only unpaid')" />
            </div>
        </div>
    </div>

    <div class="app-table-shell">
        <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('#') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('No') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Document Type') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Location') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Type') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Date') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Due Date') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Reference No') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Amount') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Paid') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Balance') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Aging') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Payment No') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                @forelse ($rows as $row)
                    @php $isPayment = ($row['row_type'] === 'payment'); @endphp
                    <tr class="{{ $isPayment ? 'bg-emerald-50 dark:bg-emerald-950/30 hover:bg-emerald-100 dark:hover:bg-emerald-950/50' : 'hover:bg-neutral-50 dark:hover:bg-neutral-800/70' }}">
                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $row['line_no'] }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $row['document_no'] }}</td>
                        <td class="px-3 py-2 text-sm {{ $isPayment ? 'font-medium text-emerald-700 dark:text-emerald-400' : 'text-neutral-700 dark:text-neutral-200' }}">{{ $row['document_type'] }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $row['location'] }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $row['type'] }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $row['date'] }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $row['due_date'] }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $row['reference_no'] }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">
                            {{ $isPayment ? '-' : $this->formatMoney($row['amount_cents']) }}
                        </td>
                        <td class="px-3 py-2 text-sm text-right {{ $isPayment ? 'font-semibold text-emerald-700 dark:text-emerald-400' : 'text-neutral-900 dark:text-neutral-100' }}">
                            {{ $this->formatMoney($row['paid_cents']) }}
                        </td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">
                            {{ $isPayment ? '-' : $this->formatMoney($row['balance_cents']) }}
                        </td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $row['aging_label'] }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $row['payment_no'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="13" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('Select a customer to view statement.') }}</td></tr>
                @endforelse
            </tbody>
            @if ($rows->count() > 0)
                <tfoot class="bg-neutral-50 dark:bg-neutral-800/90 divide-y divide-neutral-200 dark:divide-neutral-700">
                    <tr>
                        <td colspan="8" class="px-3 py-2 text-right text-sm font-semibold text-neutral-700 dark:text-neutral-200">{{ __('Period Total Invoiced') }}</td>
                        <td class="px-3 py-2 text-sm text-right font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($summary['period_amount_cents']) }}</td>
                        <td class="px-3 py-2 text-sm text-right font-semibold text-emerald-700 dark:text-emerald-400">{{ $this->formatMoney($summary['period_received_cents']) }}</td>
                        <td class="px-3 py-2 text-sm text-right font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($summary['period_balance_cents']) }}</td>
                        <td colspan="2" class="px-3 py-2 text-xs text-neutral-500 dark:text-neutral-400">{{ __('Net = Invoiced − Received') }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>

    <div class="grid gap-4 md:grid-cols-3">
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Current Balance') }}</div>
            <div class="mt-1 text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($summary['period_balance_cents']) }}</div>
        </div>
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Previous Balance') }}</div>
            <div class="mt-1 text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($summary['previous_balance_cents']) }}</div>
        </div>
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Total Outstanding') }}</div>
            <div class="mt-1 text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($summary['total_outstanding_cents']) }}</div>
        </div>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Not in Due') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('1-30') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('31-60') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('61-90') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Over 90 Days') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Total') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($aging['not_due']) }}</td>
                    <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($aging['bucket_1_30']) }}</td>
                    <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($aging['bucket_31_60']) }}</td>
                    <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($aging['bucket_61_90']) }}</td>
                    <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($aging['bucket_over_90']) }}</td>
                    <td class="px-3 py-2 text-sm text-right font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($aging['total']) }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
