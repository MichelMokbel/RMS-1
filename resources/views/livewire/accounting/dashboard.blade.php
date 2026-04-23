<?php

use App\Models\AccountingCompany;
use App\Models\ApInvoice;
use App\Models\ApPayment;
use App\Models\BankTransaction;
use App\Models\BudgetVersion;
use App\Models\JournalEntry;
use App\Models\Job;
use App\Models\Payment;
use App\Services\Accounting\AccountingReportService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public function with(AccountingReportService $reportService): array
    {
        $today = now()->startOfDay();
        $openInvoiceStatuses = ['draft', 'posted', 'partially_paid'];
        $activeCompanyIds = Schema::hasTable('accounting_companies')
            ? AccountingCompany::query()->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->all()
            : [];

        $stats = [
            'companies' => 0,
            'open_bills' => 0,
            'open_bills_amount' => 0.0,
            'overdue_bills' => 0,
            'month_inflow' => 0.0,
            'month_outflow' => 0.0,
            'month_net' => 0.0,
            'open_bank_items' => 0,
            'bank_exceptions' => 0,
            'draft_journals' => 0,
            'active_jobs' => 0,
            'active_budgets' => 0,
        ];

        if (Schema::hasTable('accounting_companies')) {
            $stats['companies'] = AccountingCompany::query()->where('is_active', true)->count();
        }

        if (Schema::hasTable('ap_invoices')) {
            $openBillsQuery = ApInvoice::query()->whereIn('status', $openInvoiceStatuses);

            $stats['open_bills'] = (int) $openBillsQuery->count();
            $stats['open_bills_amount'] = (float) $openBillsQuery->sum('total_amount');

            if (Schema::hasColumn('ap_invoices', 'due_date')) {
                $stats['overdue_bills'] = (int) ApInvoice::query()
                    ->whereIn('status', $openInvoiceStatuses)
                    ->whereNotNull('due_date')
                    ->whereDate('due_date', '<', $today->toDateString())
                    ->count();
            }
        }

        $monthStart = $today->copy()->startOfMonth();
        $monthEnd = $today->copy()->endOfMonth();

        if ($activeCompanyIds !== []) {
            foreach ($activeCompanyIds as $companyId) {
                $monthCash = $reportService->cashFlowForRange(
                    $companyId,
                    $monthStart->toDateString(),
                    $monthEnd->toDateString()
                );

                $stats['month_inflow'] += (float) $monthCash['inflow_total'];
                $stats['month_outflow'] += (float) $monthCash['outflow_total'];
            }
        }

        $stats['month_inflow'] = round($stats['month_inflow'], 2);
        $stats['month_outflow'] = round($stats['month_outflow'], 2);
        $stats['month_net'] = round($stats['month_inflow'] - $stats['month_outflow'], 2);

        if (Schema::hasTable('bank_transactions')) {
            $stats['open_bank_items'] = (int) BankTransaction::query()->where('status', 'open')->count();
            $stats['bank_exceptions'] = (int) BankTransaction::query()->where('status', 'exception')->count();
        }

        if (Schema::hasTable('journal_entries')) {
            $stats['draft_journals'] = (int) JournalEntry::query()->where('status', 'draft')->count();
        }

        if (Schema::hasTable('accounting_jobs')) {
            $stats['active_jobs'] = (int) Job::query()->where('status', 'active')->count();
        }

        if (Schema::hasTable('budget_versions')) {
            $stats['active_budgets'] = (int) BudgetVersion::query()->where('is_active', true)->count();
        }

        $trend = collect();
        $maxTrendAmount = 0.0;

        for ($i = 5; $i >= 0; $i--) {
            $month = $today->copy()->startOfMonth()->subMonths($i);
            $start = $month->toDateString();
            $end = $month->copy()->endOfMonth()->toDateString();

            $inflow = 0.0;
            $outflow = 0.0;

            foreach ($activeCompanyIds as $companyId) {
                $cashFlow = $reportService->cashFlowForRange($companyId, $start, $end);
                $inflow += (float) $cashFlow['inflow_total'];
                $outflow += (float) $cashFlow['outflow_total'];
            }

            $inflow = round($inflow, 2);
            $outflow = round($outflow, 2);
            $net = round($inflow - $outflow, 2);
            $maxTrendAmount = max($maxTrendAmount, $inflow, $outflow, abs($net));

            $trend->push([
                'label' => $month->format('M'),
                'inflow' => $inflow,
                'outflow' => $outflow,
                'net' => $net,
            ]);
        }

        $trend = $trend->map(function (array $row) use ($maxTrendAmount) {
            $base = $maxTrendAmount > 0 ? $maxTrendAmount : 1;

            return [
                ...$row,
                'inflow_pct' => round(($row['inflow'] / $base) * 100, 1),
                'outflow_pct' => round(($row['outflow'] / $base) * 100, 1),
                'net_pct' => round((abs($row['net']) / $base) * 100, 1),
                'net_direction' => $row['net'] >= 0 ? 'up' : 'down',
            ];
        });

        $arPaymentMix = collect();
        if (Schema::hasTable('payments')) {
            $arPaymentMix = Payment::query()
                ->selectRaw('method, SUM(amount_cents) AS total_cents')
                ->where('source', 'ar')
                ->whereDate('received_at', '>=', $today->copy()->subDays(59)->toDateString())
                ->groupBy('method')
                ->orderByDesc('total_cents')
                ->get()
                ->map(fn ($row) => [
                    'method' => strtoupper((string) ($row->method ?? 'unknown')),
                    'total' => (float) $row->total_cents / 100,
                ]);
        }

        $apPaymentMix = collect();
        if (Schema::hasTable('ap_payments')) {
            $apPaymentMix = ApPayment::query()
                ->selectRaw('payment_method, SUM(amount) AS total_amount')
                ->whereDate('payment_date', '>=', $today->copy()->subDays(59)->toDateString())
                ->groupBy('payment_method')
                ->orderByDesc('total_amount')
                ->get()
                ->map(fn ($row) => [
                    'method' => strtoupper((string) ($row->payment_method ?? 'unknown')),
                    'total' => (float) $row->total_amount,
                ]);
        }

        $normalizeMix = function (Collection $rows): Collection {
            $sum = (float) $rows->sum('total');
            $base = $sum > 0 ? $sum : 1;

            return $rows->map(fn (array $row) => [
                ...$row,
                'pct' => round(($row['total'] / $base) * 100, 1),
            ]);
        };

        $recentBankTransactions = Schema::hasTable('bank_transactions')
            ? BankTransaction::query()->with('bankAccount')->latest('transaction_date')->limit(10)->get()
            : collect();

        $upcomingBills = collect();
        if (Schema::hasTable('ap_invoices') && Schema::hasColumn('ap_invoices', 'due_date')) {
            $upcomingBills = ApInvoice::query()
                ->with('supplier')
                ->whereIn('status', $openInvoiceStatuses)
                ->whereNotNull('due_date')
                ->whereDate('due_date', '>=', $today->toDateString())
                ->whereDate('due_date', '<=', $today->copy()->addDays(7)->toDateString())
                ->orderBy('due_date')
                ->limit(8)
                ->get();
        }

        $topSupplierExposure = collect();
        if (Schema::hasTable('ap_invoices') && Schema::hasTable('suppliers')) {
            $topSupplierExposure = DB::table('ap_invoices')
                ->leftJoin('suppliers', 'suppliers.id', '=', 'ap_invoices.supplier_id')
                ->whereIn('ap_invoices.status', $openInvoiceStatuses)
                ->selectRaw("ap_invoices.supplier_id, COALESCE(suppliers.name, 'Unknown') AS supplier_name, COUNT(*) AS bill_count, ROUND(SUM(ap_invoices.total_amount), 2) AS total_due")
                ->groupBy('ap_invoices.supplier_id', 'suppliers.name')
                ->orderByDesc('total_due')
                ->limit(6)
                ->get();
        }

        $bankStatusMix = collect();
        if (Schema::hasTable('bank_transactions')) {
            $bankStatusMix = BankTransaction::query()
                ->selectRaw('status, COUNT(*) as total_rows')
                ->whereDate('transaction_date', '>=', $today->copy()->subDays(89)->toDateString())
                ->groupBy('status')
                ->orderByDesc('total_rows')
                ->get()
                ->map(fn ($row) => [
                    'status' => strtoupper((string) ($row->status ?? 'open')),
                    'total' => (int) $row->total_rows,
                ]);

            $statusSum = (int) $bankStatusMix->sum('total');
            $statusBase = $statusSum > 0 ? $statusSum : 1;
            $bankStatusMix = $bankStatusMix->map(fn (array $row) => [
                ...$row,
                'pct' => round(($row['total'] / $statusBase) * 100, 1),
            ]);
        }

        return [
            'stats' => $stats,
            'trend' => $trend,
            'arPaymentMix' => $normalizeMix($arPaymentMix),
            'apPaymentMix' => $normalizeMix($apPaymentMix),
            'bankStatusMix' => $bankStatusMix,
            'recentBankTransactions' => $recentBankTransactions,
            'upcomingBills' => $upcomingBills,
            'topSupplierExposure' => $topSupplierExposure,
        ];
    }
}; ?>

<div class="app-page space-y-6">
    <div class="rounded-2xl border border-neutral-200 bg-gradient-to-br from-white via-neutral-50 to-emerald-50 p-5 shadow-sm dark:border-neutral-700 dark:from-neutral-900 dark:via-neutral-900 dark:to-emerald-950/30">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Accounting Command Center') }}</h1>
                <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Track liquidity, payables risk, reconciliation pressure, and settlement throughput in one place.') }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <flux:button :href="route('settings.accounting', ['tab' => 'chart'])" wire:navigate variant="ghost">{{ __('Chart of Accounts') }}</flux:button>
                <flux:button :href="route('accounting.banking')" wire:navigate variant="ghost">{{ __('Banking') }}</flux:button>
                <flux:button :href="route('payables.index')" wire:navigate variant="ghost">{{ __('Payables') }}</flux:button>
                <flux:button :href="route('accounting.ar-clearing')" wire:navigate variant="ghost">{{ __('AR Clearing') }}</flux:button>
                <flux:button :href="route('accounting.ap-cheque-clearance')" wire:navigate variant="ghost">{{ __('Cheque Clearance') }}</flux:button>
                <flux:button :href="route('accounting.jobs')" wire:navigate variant="ghost">{{ __('Jobs') }}</flux:button>
                <flux:button :href="route('reports.index', ['category' => 'accounting'])" wire:navigate>{{ __('Accounting Reports') }}</flux:button>
                <flux:button :href="route('accounting.period-close')" wire:navigate variant="ghost">{{ __('Period Close') }}</flux:button>
            </div>
        </div>
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-xl border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <p class="text-xs uppercase tracking-wide text-neutral-500">{{ __('Open AP Exposure') }}</p>
            <p class="mt-1 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format((float) $stats['open_bills_amount'], 2) }}</p>
            <p class="text-xs text-neutral-500">{{ __(':count open bills', ['count' => $stats['open_bills']]) }}</p>
        </div>
        <div class="rounded-xl border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <p class="text-xs uppercase tracking-wide text-neutral-500">{{ __('This Month Net Cash') }}</p>
            <p class="mt-1 text-2xl font-semibold {{ $stats['month_net'] >= 0 ? 'text-emerald-700 dark:text-emerald-300' : 'text-rose-700 dark:text-rose-300' }}">{{ number_format((float) $stats['month_net'], 2) }}</p>
            <p class="text-xs text-neutral-500">{{ __('Inflow :in / Outflow :out', ['in' => number_format((float) $stats['month_inflow'], 2), 'out' => number_format((float) $stats['month_outflow'], 2)]) }}</p>
        </div>
        <div class="rounded-xl border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <p class="text-xs uppercase tracking-wide text-neutral-500">{{ __('Bank Work Queue') }}</p>
            <p class="mt-1 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ $stats['open_bank_items'] }}</p>
            <p class="text-xs text-neutral-500">{{ __(':count exceptions in last activity', ['count' => $stats['bank_exceptions']]) }}</p>
        </div>
        <div class="rounded-xl border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <p class="text-xs uppercase tracking-wide text-neutral-500">{{ __('Control Pressure') }}</p>
            <p class="mt-1 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ $stats['overdue_bills'] }}</p>
            <p class="text-xs text-neutral-500">{{ __('Overdue bills · :draft draft journals', ['draft' => $stats['draft_journals']]) }}</p>
        </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="rounded-xl border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 xl:col-span-2">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('6-Month Cash Trend') }}</h2>
                <span class="text-xs text-neutral-500">{{ __('AR inflow vs AP outflow') }}</span>
            </div>
            <div class="space-y-3">
                @foreach($trend as $row)
                    <div>
                        <div class="mb-1 flex items-center justify-between text-xs">
                            <span class="font-medium text-neutral-700 dark:text-neutral-200">{{ $row['label'] }}</span>
                            <span class="text-neutral-500">{{ __('Net') }}: {{ number_format((float) $row['net'], 2) }}</span>
                        </div>
                        <div class="grid grid-cols-10 gap-2 items-center">
                            <div class="col-span-4">
                                <div class="h-2 rounded-full bg-emerald-100 dark:bg-emerald-900/40">
                                    <div class="h-2 rounded-full bg-emerald-500" style="width: {{ max(2, $row['inflow_pct']) }}%"></div>
                                </div>
                                <p class="mt-1 text-[11px] text-neutral-500">{{ __('Inflow') }} {{ number_format((float) $row['inflow'], 2) }}</p>
                            </div>
                            <div class="col-span-4">
                                <div class="h-2 rounded-full bg-rose-100 dark:bg-rose-900/40">
                                    <div class="h-2 rounded-full bg-rose-500" style="width: {{ max(2, $row['outflow_pct']) }}%"></div>
                                </div>
                                <p class="mt-1 text-[11px] text-neutral-500">{{ __('Outflow') }} {{ number_format((float) $row['outflow'], 2) }}</p>
                            </div>
                            <div class="col-span-2 text-right text-xs font-medium {{ $row['net_direction'] === 'up' ? 'text-emerald-700 dark:text-emerald-300' : 'text-rose-700 dark:text-rose-300' }}">
                                {{ $row['net_direction'] === 'up' ? '+' : '-' }}{{ number_format(abs((float) $row['net']), 2) }}
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="rounded-xl border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <h2 class="mb-4 text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Bank Status Mix (90 Days)') }}</h2>
            <div class="space-y-3">
                @forelse($bankStatusMix as $row)
                    <div>
                        <div class="mb-1 flex items-center justify-between text-xs">
                            <span class="font-medium text-neutral-700 dark:text-neutral-200">{{ $row['status'] }}</span>
                            <span class="text-neutral-500">{{ $row['total'] }}</span>
                        </div>
                        <div class="h-2 rounded-full bg-neutral-100 dark:bg-neutral-800">
                            <div class="h-2 rounded-full bg-primary-500" style="width: {{ max(2, $row['pct']) }}%"></div>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-neutral-500">{{ __('No recent bank status activity.') }}</p>
                @endforelse
            </div>
            <div class="mt-4 rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-xs text-neutral-600 dark:border-neutral-700 dark:bg-neutral-800/60 dark:text-neutral-300">
                {{ __('Active jobs: :jobs · Active budgets: :budgets · Draft journals: :draft', ['jobs' => $stats['active_jobs'], 'budgets' => $stats['active_budgets'], 'draft' => $stats['draft_journals']]) }}
            </div>
        </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        <div class="rounded-xl border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <h2 class="mb-4 text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Payment Method Mix (60 Days)') }}</h2>
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <h3 class="mb-2 text-xs uppercase tracking-wide text-neutral-500">{{ __('AR Receipts') }}</h3>
                    <div class="space-y-2">
                        @forelse($arPaymentMix as $row)
                            <div>
                                <div class="mb-1 flex items-center justify-between text-xs">
                                    <span class="font-medium text-neutral-700 dark:text-neutral-200">{{ $row['method'] }}</span>
                                    <span class="text-neutral-500">{{ number_format((float) $row['total'], 2) }}</span>
                                </div>
                                <div class="h-2 rounded-full bg-emerald-100 dark:bg-emerald-900/40">
                                    <div class="h-2 rounded-full bg-emerald-500" style="width: {{ max(2, $row['pct']) }}%"></div>
                                </div>
                            </div>
                        @empty
                            <p class="text-sm text-neutral-500">{{ __('No AR receipt data.') }}</p>
                        @endforelse
                    </div>
                </div>
                <div>
                    <h3 class="mb-2 text-xs uppercase tracking-wide text-neutral-500">{{ __('AP Payments') }}</h3>
                    <div class="space-y-2">
                        @forelse($apPaymentMix as $row)
                            <div>
                                <div class="mb-1 flex items-center justify-between text-xs">
                                    <span class="font-medium text-neutral-700 dark:text-neutral-200">{{ $row['method'] }}</span>
                                    <span class="text-neutral-500">{{ number_format((float) $row['total'], 2) }}</span>
                                </div>
                                <div class="h-2 rounded-full bg-amber-100 dark:bg-amber-900/40">
                                    <div class="h-2 rounded-full bg-amber-500" style="width: {{ max(2, $row['pct']) }}%"></div>
                                </div>
                            </div>
                        @empty
                            <p class="text-sm text-neutral-500">{{ __('No AP payment data.') }}</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Bills Due In 7 Days') }}</h2>
                <flux:button size="xs" :href="route('payables.index')" wire:navigate>{{ __('Open Payables') }}</flux:button>
            </div>
            <div class="app-table-shell">
                <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                    <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Due') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Supplier') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Invoice') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Amount') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        @forelse($upcomingBills as $bill)
                            <tr>
                                <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $bill->due_date?->format('Y-m-d') }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $bill->supplier?->name ?? '—' }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $bill->invoice_number }}</td>
                                <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $bill->total_amount, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-3 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No bills due in the next 7 days.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        <div class="rounded-xl border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Latest Bank Activity') }}</h2>
                <flux:button size="xs" :href="route('accounting.banking')" wire:navigate>{{ __('Open Banking') }}</flux:button>
            </div>
            <div class="app-table-shell">
                <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                    <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Date') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Type') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Reference') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Amount') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        @forelse($recentBankTransactions as $transaction)
                            <tr>
                                <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $transaction->transaction_date?->format('Y-m-d') }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ strtoupper((string) $transaction->transaction_type) }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $transaction->reference ?? $transaction->memo ?? '—' }}</td>
                                <td class="px-3 py-2 text-right text-sm font-medium {{ $transaction->direction === 'inflow' ? 'text-emerald-700 dark:text-emerald-300' : 'text-rose-700 dark:text-rose-300' }}">
                                    {{ $transaction->direction === 'inflow' ? '+' : '-' }}{{ number_format((float) $transaction->amount, 2) }}
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-3 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No bank activity available.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="rounded-xl border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Largest Supplier Exposure') }}</h2>
                <flux:button size="xs" :href="route('payables.index')" wire:navigate>{{ __('Manage AP') }}</flux:button>
            </div>
            <div class="app-table-shell">
                <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                    <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Supplier') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Bills') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Exposure') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        @forelse($topSupplierExposure as $supplier)
                            <tr>
                                <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $supplier->supplier_name }}</td>
                                <td class="px-3 py-2 text-right text-sm text-neutral-700 dark:text-neutral-200">{{ (int) $supplier->bill_count }}</td>
                                <td class="px-3 py-2 text-right text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ number_format((float) $supplier->total_due, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-3 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No supplier exposure data.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
