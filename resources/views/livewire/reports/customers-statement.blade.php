<?php

use App\Models\ArInvoice;
use App\Support\Money\MinorUnits;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public int $branch_id = 0;
    public ?string $date_from = null;
    public ?string $date_to = null;

    public function mount(): void
    {
        $this->date_from = now()->startOfMonth()->toDateString();
        $this->date_to = now()->endOfMonth()->toDateString();
    }

    public function with(): array
    {
        $sections = $this->sections();

        return [
            'sections' => $sections,
            'grandTotals' => $this->grandTotals($sections),
            'branches' => Schema::hasTable('branches') ? DB::table('branches')->where('is_active', 1)->orderBy('name')->get() : collect(),
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
     * @return Collection<int, array{customer_id:int,customer_name:string,customer_code:?string,rows:Collection<int,array<string,int|string>>,summary:array{period_amount_cents:int,period_received_cents:int,period_balance_cents:int}}>
     */
    private function sections(): Collection
    {
        $dateFrom = $this->date_from ? now()->parse($this->date_from)->startOfDay() : now()->startOfMonth()->startOfDay();
        $dateTo   = $this->date_to   ? now()->parse($this->date_to)->endOfDay()     : now()->endOfMonth()->endOfDay();

        $invoices = ArInvoice::query()
            ->with(['customer:id,name,customer_code'])
            ->where('type', 'invoice')
            ->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->when($this->branch_id > 0, fn ($q) => $q->where('branch_id', $this->branch_id))
            ->whereDate('issue_date', '>=', $dateFrom)
            ->whereDate('issue_date', '<=', $dateTo)
            ->orderBy('customer_id')
            ->orderBy('issue_date')
            ->orderBy('id')
            ->get();

        // Fetch all AR payments for customers that appear in the invoices list
        $customerIds = $invoices->pluck('customer_id')->filter()->unique()->values();

        $paymentsByCustomer = collect();
        if ($customerIds->isNotEmpty() && Schema::hasTable('payments')) {
            $paymentsByCustomer = DB::table('payments')
                ->whereIn('customer_id', $customerIds)
                ->where('source', 'ar')
                ->whereNull('voided_at')
                ->when($this->branch_id > 0, fn ($q) => $q->where('branch_id', $this->branch_id))
                ->where('received_at', '>=', $dateFrom)
                ->where('received_at', '<=', $dateTo)
                ->orderBy('customer_id')
                ->orderBy('received_at')
                ->get()
                ->groupBy('customer_id');
        }

        if ($invoices->isEmpty() && $paymentsByCustomer->isEmpty()) {
            return collect();
        }

        $allBranchIds = $invoices->pluck('branch_id')
            ->merge($paymentsByCustomer->flatten()->pluck('branch_id'))
            ->filter()->unique()->values();

        $branchNames = (Schema::hasTable('branches') && $allBranchIds->isNotEmpty())
            ? DB::table('branches')->whereIn('id', $allBranchIds)->pluck('name', 'id')
            : collect();

        $asOf = $this->agingAsOf($dateTo);

        return $invoices
            ->groupBy(fn (ArInvoice $invoice) => (int) $invoice->customer_id)
            ->map(function (Collection $group, int $customerId) use ($branchNames, $asOf, $paymentsByCustomer): array {
                $customer = $group->first()?->customer;

                $invoiceRows = $group->values()->map(function (ArInvoice $invoice) use ($branchNames, $asOf): array {
                    $dueDate = $invoice->due_date ?: $invoice->issue_date;
                    $days    = $dueDate ? max(0, (int) floor((float) $dueDate->diffInDays($asOf, false))) : 0;
                    $paymentType = strtolower((string) ($invoice->payment_type ?? 'credit'));

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
                        'amount_cents'  => (int) ($invoice->total_cents ?? 0),
                        'paid_cents'    => (int) ($invoice->paid_total_cents ?? 0),
                        'balance_cents' => (int) ($invoice->balance_cents ?? 0),
                        'aging_label'   => $days.' Days',
                        'payment_no'    => '-',
                    ];
                });

                $paymentRows = collect($paymentsByCustomer->get($customerId, []))->map(function (object $payment) use ($branchNames): array {
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

                $rows = $invoiceRows->merge($paymentRows)
                    ->sortBy([['sort_date', 'asc'], ['row_type', 'asc']])
                    ->values()
                    ->map(function (array $row, int $i): array { $row['line_no'] = $i + 1; return $row; });

                $periodAmount   = (int) $rows->where('row_type', 'invoice')->sum('amount_cents');
                $periodReceived = (int) $rows->where('row_type', 'payment')->sum('paid_cents');

                return [
                    'customer_id'   => $customerId,
                    'customer_name' => (string) ($customer?->name ?? '—'),
                    'customer_code' => $customer?->customer_code,
                    'rows'          => $rows,
                    'summary'       => [
                        'period_amount_cents'   => $periodAmount,
                        'period_received_cents' => $periodReceived,
                        'period_balance_cents'  => $periodAmount - $periodReceived,
                    ],
                ];
            })
            ->sortBy('customer_name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    /**
     * @param  Collection<int, array{summary:array{period_amount_cents:int,period_received_cents:int,period_balance_cents:int}}>  $sections
     * @return array{period_amount_cents:int,period_received_cents:int,period_balance_cents:int}
     */
    private function grandTotals(Collection $sections): array
    {
        return [
            'period_amount_cents'   => (int) $sections->sum(fn (array $s) => (int) ($s['summary']['period_amount_cents'] ?? 0)),
            'period_received_cents' => (int) $sections->sum(fn (array $s) => (int) ($s['summary']['period_received_cents'] ?? 0)),
            'period_balance_cents'  => (int) $sections->sum(fn (array $s) => (int) ($s['summary']['period_balance_cents'] ?? 0)),
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

<div class="app-page space-y-6">
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
        <div class="app-filter-grid">
            <x-reports.branch-select name="branch_id" :branches="$branches" />
            <x-reports.date-range fromName="date_from" toName="date_to" />
        </div>
    </div>

    @forelse ($sections as $section)
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-base font-semibold text-neutral-900 dark:text-neutral-100">{{ $section['customer_name'] }}</h2>
                <span class="text-xs text-neutral-500 dark:text-neutral-400">{{ __('Code') }}: {{ $section['customer_code'] ?: '-' }}</span>
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
                        @foreach ($section['rows'] as $row)
                            <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                                <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $row['line_no'] }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $row['document_no'] }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $row['document_type'] }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $row['location'] }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $row['type'] }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $row['date'] }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $row['due_date'] }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $row['reference_no'] }}</td>
                                <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($row['amount_cents']) }}</td>
                                <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($row['paid_cents']) }}</td>
                                <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($row['balance_cents']) }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $row['aging_label'] }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $row['payment_no'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-neutral-50 dark:bg-neutral-800/90">
                        <tr>
                            <td colspan="8" class="px-3 py-2 text-right text-sm font-semibold text-neutral-700 dark:text-neutral-200">{{ __('Total Amount') }}</td>
                            <td class="px-3 py-2 text-sm text-right font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($section['summary']['period_amount_cents']) }}</td>
                            <td class="px-3 py-2 text-sm text-right font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($section['summary']['period_paid_cents']) }}</td>
                            <td class="px-3 py-2 text-sm text-right font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($section['summary']['period_balance_cents']) }}</td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    @empty
        <div class="rounded-lg border border-neutral-200 bg-white p-6 text-center text-sm text-neutral-600 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
            {{ __('No customers found.') }}
        </div>
    @endforelse

    @if ($sections->count() > 0)
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <h2 class="text-base font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Grand Total') }}</h2>
            <div class="mt-3 grid gap-3 sm:grid-cols-3">
                <div>
                    <div class="text-xs text-neutral-500 dark:text-neutral-400">{{ __('Amount') }}</div>
                    <div class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($grandTotals['period_amount_cents']) }}</div>
                </div>
                <div>
                    <div class="text-xs text-neutral-500 dark:text-neutral-400">{{ __('Paid') }}</div>
                    <div class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($grandTotals['period_paid_cents']) }}</div>
                </div>
                <div>
                    <div class="text-xs text-neutral-500 dark:text-neutral-400">{{ __('Balance') }}</div>
                    <div class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($grandTotals['period_balance_cents']) }}</div>
                </div>
            </div>
        </div>
    @endif
</div>
