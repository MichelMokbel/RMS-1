<?php

use App\Models\ApInvoice;
use App\Models\ArInvoice;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\MealSubscription;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Services\Spend\SpendReportService;
use App\Support\Money\MinorUnits;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public int $branch_id = 0;
    public ?string $date_from = null;
    public ?string $date_to = null;

    public function mount(): void
    {
        $this->branch_id = max(0, (int) request()->integer('branch_id', $this->branch_id));
        $this->date_from = now()->startOfMonth()->toDateString();
        $this->date_to = now()->endOfMonth()->toDateString();
    }

    public function with(): array
    {
        $today = Carbon::today();
        [$from, $to] = $this->normalizedRange();
        [$previousFrom, $previousTo] = $this->previousRange($from, $to);

        $resolvedBranchId = property_exists($this, 'branch_id')
            ? (int) ($this->branch_id ?? 0)
            : (int) request()->integer('branch_id', 0);
        $branchId = $resolvedBranchId > 0 ? $resolvedBranchId : null;
        $scale = max(1, MinorUnits::posScale());
        $moneyDigits = MinorUnits::scaleDigits($scale);
        $currency = (string) config('pos.currency', 'QAR');

        $revenueInRangeCents = $this->arPaidRevenueCents($from, $to, $branchId);
        $previousRevenueCents = $this->arPaidRevenueCents($previousFrom, $previousTo, $branchId);

        $revenueInRange = $this->fromMinor($revenueInRangeCents, $scale, $moneyDigits);
        $previousRevenue = $this->fromMinor($previousRevenueCents, $scale, $moneyDigits);

        $revenueChangePct = null;
        if ($previousRevenue > 0) {
            $revenueChangePct = round((($revenueInRange - $previousRevenue) / $previousRevenue) * 100, 1);
        }

        $todayOrdersQuery = Order::query()
            ->whereDate('scheduled_date', $today)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId));
        $todayOrdersCount = (clone $todayOrdersQuery)->count();
        $todayOrdersTotal = (float) (clone $todayOrdersQuery)->sum('total_amount');

        $activeSubscriptions = MealSubscription::query()
            ->where('status', 'active')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->count();
        $pausedSubscriptions = MealSubscription::query()
            ->where('status', 'paused')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->count();

        $totalCustomers = Schema::hasTable('customers') ? Customer::count() : 0;
        $newCustomersInRange = Schema::hasTable('customers')
            ? Customer::whereDate('created_at', '>=', $from->toDateString())
                ->whereDate('created_at', '<=', $to->toDateString())
                ->count()
            : 0;

        $receivablesDue = 0.0;
        $receivablesOverdue = 0.0;
        if (Schema::hasTable('ar_invoices')) {
            $receivablesBase = ArInvoice::query()
                ->where('type', 'invoice')
                ->whereIn('status', ['issued', 'partially_paid'])
                ->where('balance_cents', '>', 0)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId));

            $receivablesDueCents = (clone $receivablesBase)
                ->where(function ($q) use ($today) {
                    $q->whereDate('due_date', '>=', $today->toDateString())
                        ->orWhereNull('due_date');
                })
                ->sum('balance_cents');

            $receivablesOverdueCents = (clone $receivablesBase)
                ->whereDate('due_date', '<', $today->toDateString())
                ->sum('balance_cents');

            $receivablesDue = $this->fromMinor((int) $receivablesDueCents, $scale, $moneyDigits);
            $receivablesOverdue = $this->fromMinor((int) $receivablesOverdueCents, $scale, $moneyDigits);
        }

        $payablesDue = 0.0;
        $payablesOverdue = 0.0;
        if (Schema::hasTable('ap_invoices')) {
            $payables = ApInvoice::query()
                ->whereIn('status', ['posted', 'partially_paid'])
                ->withSum('allocations as allocated_sum', 'allocated_amount')
                ->get(['id', 'due_date', 'total_amount']);

            $payablesDue = (float) $payables->sum(function (ApInvoice $invoice) use ($today) {
                $outstanding = max((float) $invoice->total_amount - (float) ($invoice->allocated_sum ?? 0), 0);
                if ($outstanding <= 0) {
                    return 0;
                }

                if ($invoice->due_date && $invoice->due_date->lt($today)) {
                    return 0;
                }

                return $outstanding;
            });

            $payablesOverdue = (float) $payables->sum(function (ApInvoice $invoice) use ($today) {
                $outstanding = max((float) $invoice->total_amount - (float) ($invoice->allocated_sum ?? 0), 0);
                if ($outstanding <= 0) {
                    return 0;
                }

                return ($invoice->due_date && $invoice->due_date->lt($today)) ? $outstanding : 0;
            });
        }

        $expensesInRange = app(SpendReportService::class)->totalForRange($from, $to);

        $pendingPOs = 0;
        if (Schema::hasTable('purchase_orders')) {
            $pendingPOs = PurchaseOrder::query()
                ->whereIn('status', [PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_PENDING])
                ->count();
        }

        $rangeLabels = $this->dailyLabels($from, $to);

        $revenueByDate = ArInvoice::query()
            ->where('type', 'invoice')
            ->whereIn('status', ['paid', 'partially_paid'])
            ->whereDate('issue_date', '>=', $from->toDateString())
            ->whereDate('issue_date', '<=', $to->toDateString())
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw('DATE(issue_date) as chart_date, SUM(paid_total_cents) as total_paid_cents')
            ->groupBy('chart_date')
            ->pluck('total_paid_cents', 'chart_date')
            ->all();

        $expensesByDate = ApInvoice::query()
            ->where('is_expense', true)
            ->whereNotIn('status', ['draft', 'void'])
            ->whereDate('invoice_date', '>=', $from->toDateString())
            ->whereDate('invoice_date', '<=', $to->toDateString())
            ->selectRaw('DATE(invoice_date) as chart_date, SUM(total_amount) as total_amount')
            ->groupBy('chart_date')
            ->pluck('total_amount', 'chart_date')
            ->all();

        $trendRevenue = [];
        $trendExpenses = [];
        foreach ($rangeLabels as $label) {
            $trendRevenue[] = $this->fromMinor((int) ($revenueByDate[$label] ?? 0), $scale, $moneyDigits);
            $trendExpenses[] = round((float) ($expensesByDate[$label] ?? 0), $moneyDigits);
        }

        $statusMixRaw = ArInvoice::query()
            ->where('type', 'invoice')
            ->whereIn('status', ['paid', 'partially_paid', 'issued'])
            ->whereDate('issue_date', '>=', $from->toDateString())
            ->whereDate('issue_date', '<=', $to->toDateString())
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw('status, SUM(total_cents) as total_cents')
            ->groupBy('status')
            ->pluck('total_cents', 'status')
            ->all();

        $invoiceStatusMix = [
            'paid' => $this->fromMinor((int) ($statusMixRaw['paid'] ?? 0), $scale, $moneyDigits),
            'partially_paid' => $this->fromMinor((int) ($statusMixRaw['partially_paid'] ?? 0), $scale, $moneyDigits),
            'issued' => $this->fromMinor((int) ($statusMixRaw['issued'] ?? 0), $scale, $moneyDigits),
        ];

        $chartPayload = [
            'currency' => $currency,
            'digits' => $moneyDigits,
            'trend' => [
                'categories' => array_map(fn (string $d) => Carbon::parse($d)->format('M d'), $rangeLabels),
                'series' => [
                    ['name' => 'Revenue (Paid Invoices)', 'data' => $trendRevenue],
                    ['name' => 'Expenses', 'data' => $trendExpenses],
                ],
            ],
            'donuts' => [
                'receivables' => [
                    'labels' => ['Current', 'Overdue'],
                    'series' => [round($receivablesDue, $moneyDigits), round($receivablesOverdue, $moneyDigits)],
                ],
                'payables' => [
                    'labels' => ['Current', 'Overdue'],
                    'series' => [round($payablesDue, $moneyDigits), round($payablesOverdue, $moneyDigits)],
                ],
                'invoiceStatusMix' => [
                    'labels' => ['Paid', 'Partially Paid', 'Issued'],
                    'series' => [
                        round($invoiceStatusMix['paid'], $moneyDigits),
                        round($invoiceStatusMix['partially_paid'], $moneyDigits),
                        round($invoiceStatusMix['issued'], $moneyDigits),
                    ],
                ],
            ],
        ];

        return [
            'moneyDigits' => $moneyDigits,
            'currency' => $currency,
            'branches' => Schema::hasTable('branches') ? Branch::query()->where('is_active', 1)->orderBy('name')->get() : collect(),
            'fromDateLabel' => $from->toDateString(),
            'toDateLabel' => $to->toDateString(),
            'previousFromLabel' => $previousFrom->toDateString(),
            'previousToLabel' => $previousTo->toDateString(),
            'todayOrdersCount' => $todayOrdersCount,
            'todayOrdersTotal' => $todayOrdersTotal,
            'activeSubscriptions' => $activeSubscriptions,
            'pausedSubscriptions' => $pausedSubscriptions,
            'totalCustomers' => $totalCustomers,
            'newCustomersInRange' => $newCustomersInRange,
            'revenueInRange' => $revenueInRange,
            'previousRevenue' => $previousRevenue,
            'revenueChangePct' => $revenueChangePct,
            'receivablesDue' => $receivablesDue,
            'receivablesOverdue' => $receivablesOverdue,
            'payablesDue' => $payablesDue,
            'payablesOverdue' => $payablesOverdue,
            'expensesInRange' => $expensesInRange,
            'pendingPOs' => $pendingPOs,
            'chartPayload' => $chartPayload,
        ];
    }

    private function normalizedRange(): array
    {
        $defaultFrom = Carbon::today()->startOfMonth();
        $defaultTo = Carbon::today()->endOfMonth();

        $from = $defaultFrom;
        $to = $defaultTo;

        if ($this->date_from) {
            try {
                $from = Carbon::parse($this->date_from)->startOfDay();
            } catch (\Throwable) {
                $from = $defaultFrom;
            }
        }

        if ($this->date_to) {
            try {
                $to = Carbon::parse($this->date_to)->endOfDay();
            } catch (\Throwable) {
                $to = $defaultTo;
            }
        }

        if ($to->lt($from)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        return [$from, $to];
    }

    private function previousRange(Carbon $from, Carbon $to): array
    {
        $days = max(1, $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1);
        $previousTo = $from->copy()->subDay()->endOfDay();
        $previousFrom = $previousTo->copy()->subDays($days - 1)->startOfDay();

        return [$previousFrom, $previousTo];
    }

    private function arPaidRevenueCents(Carbon $from, Carbon $to, ?int $branchId): int
    {
        return (int) ArInvoice::query()
            ->where('type', 'invoice')
            ->whereIn('status', ['paid', 'partially_paid'])
            ->whereDate('issue_date', '>=', $from->toDateString())
            ->whereDate('issue_date', '<=', $to->toDateString())
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->sum('paid_total_cents');
    }

    private function fromMinor(int $minorUnits, int $scale, int $digits): float
    {
        if ($scale <= 0) {
            return (float) $minorUnits;
        }

        return round($minorUnits / $scale, $digits);
    }

    private function dailyLabels(Carbon $from, Carbon $to): array
    {
        $labels = [];
        $cursor = $from->copy()->startOfDay();
        $limit = $to->copy()->startOfDay();

        while ($cursor->lte($limit)) {
            $labels[] = $cursor->toDateString();
            $cursor->addDay();
        }

        return $labels;
    }
}; ?>

<div class="app-page py-8 space-y-8">
    <header class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-zinc-50">{{ __('Dashboard') }}</h1>
            <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Admin overview with invoice-driven finance KPIs.') }}</p>
        </div>
        <time class="text-sm font-medium text-zinc-500 dark:text-zinc-400" datetime="{{ now()->toIso8601String() }}">
            {{ now()->format('l, F j, Y') }}
        </time>
    </header>

    <section class="rounded-2xl border border-zinc-200/80 bg-white p-5 shadow-sm dark:border-zinc-700/80 dark:bg-zinc-900">
        <div class="app-filter-grid">
            <x-reports.branch-select name="branch_id" :branches="$branches" />
            <x-reports.date-range fromName="date_from" toName="date_to" />
        </div>
        <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">
            {{ __('Scope note: AR, orders, and subscriptions are branch-aware. AP/payables/expenses are global in current schema.') }}
        </p>
    </section>

    <section class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <article class="rounded-2xl border border-zinc-200/80 bg-white p-5 shadow-sm dark:border-zinc-700/80 dark:bg-zinc-900">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">{{ __('Revenue (Paid Invoices)') }}</p>
                    <p class="mt-3 text-3xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">{{ number_format($revenueInRange, $moneyDigits) }}</p>
                </div>
                @if ($revenueChangePct !== null)
                    <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $revenueChangePct >= 0 ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-300' : 'bg-rose-100 text-rose-700 dark:bg-rose-900/50 dark:text-rose-300' }}">
                        {{ $revenueChangePct >= 0 ? '+' : '' }}{{ $revenueChangePct }}%
                    </span>
                @endif
            </div>
            <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">{{ $fromDateLabel }} - {{ $toDateLabel }}</p>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Previous') }}: {{ number_format($previousRevenue, $moneyDigits) }} ({{ $previousFromLabel }} - {{ $previousToLabel }})</p>
            <p class="mt-3 inline-flex rounded-full bg-sky-50 px-2 py-1 text-[11px] font-medium text-sky-700 dark:bg-sky-900/30 dark:text-sky-300">{{ __('Branch-aware') }}</p>
        </article>

        <article class="rounded-2xl border border-zinc-200/80 bg-white p-5 shadow-sm dark:border-zinc-700/80 dark:bg-zinc-900">
            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">{{ __('Receivables Outstanding') }}</p>
            <p class="mt-3 text-3xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">{{ number_format($receivablesDue + $receivablesOverdue, $moneyDigits) }}</p>
            <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Current') }}: {{ number_format($receivablesDue, $moneyDigits) }}</p>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Overdue') }}: {{ number_format($receivablesOverdue, $moneyDigits) }}</p>
            <p class="mt-3 inline-flex rounded-full bg-sky-50 px-2 py-1 text-[11px] font-medium text-sky-700 dark:bg-sky-900/30 dark:text-sky-300">{{ __('Branch-aware') }}</p>
        </article>

        <article class="rounded-2xl border border-zinc-200/80 bg-white p-5 shadow-sm dark:border-zinc-700/80 dark:bg-zinc-900">
            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">{{ __('Payables Outstanding') }}</p>
            <p class="mt-3 text-3xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">{{ number_format($payablesDue + $payablesOverdue, $moneyDigits) }}</p>
            <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Current') }}: {{ number_format($payablesDue, $moneyDigits) }}</p>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Overdue') }}: {{ number_format($payablesOverdue, $moneyDigits) }}</p>
            <p class="mt-3 inline-flex rounded-full bg-amber-50 px-2 py-1 text-[11px] font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">{{ __('Global') }}</p>
        </article>

        <article class="rounded-2xl border border-zinc-200/80 bg-white p-5 shadow-sm dark:border-zinc-700/80 dark:bg-zinc-900">
            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">{{ __('Expenses (Range)') }}</p>
            <p class="mt-3 text-3xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">{{ number_format($expensesInRange, $moneyDigits) }}</p>
            <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">{{ $fromDateLabel }} - {{ $toDateLabel }}</p>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Pending POs') }}: {{ number_format($pendingPOs) }}</p>
            <p class="mt-3 inline-flex rounded-full bg-amber-50 px-2 py-1 text-[11px] font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">{{ __('Global') }}</p>
        </article>

        <article class="rounded-2xl border border-zinc-200/80 bg-white p-5 shadow-sm dark:border-zinc-700/80 dark:bg-zinc-900">
            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">{{ __('Today\'s Orders') }}</p>
            <p class="mt-3 text-3xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">{{ number_format($todayOrdersCount) }}</p>
            <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Total amount') }}: {{ number_format($todayOrdersTotal, $moneyDigits) }}</p>
            <p class="mt-3 inline-flex rounded-full bg-sky-50 px-2 py-1 text-[11px] font-medium text-sky-700 dark:bg-sky-900/30 dark:text-sky-300">{{ __('Branch-aware') }}</p>
        </article>

        <article class="rounded-2xl border border-zinc-200/80 bg-white p-5 shadow-sm dark:border-zinc-700/80 dark:bg-zinc-900">
            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">{{ __('Active Subscriptions') }}</p>
            <p class="mt-3 text-3xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">{{ number_format($activeSubscriptions) }}</p>
            <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Paused') }}: {{ number_format($pausedSubscriptions) }}</p>
            <p class="mt-3 inline-flex rounded-full bg-sky-50 px-2 py-1 text-[11px] font-medium text-sky-700 dark:bg-sky-900/30 dark:text-sky-300">{{ __('Branch-aware') }}</p>
        </article>

        <article class="rounded-2xl border border-zinc-200/80 bg-white p-5 shadow-sm dark:border-zinc-700/80 dark:bg-zinc-900">
            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">{{ __('Total Customers') }}</p>
            <p class="mt-3 text-3xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">{{ number_format($totalCustomers) }}</p>
            <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">+{{ number_format($newCustomersInRange) }} {{ __('in selected range') }}</p>
            <p class="mt-3 inline-flex rounded-full bg-amber-50 px-2 py-1 text-[11px] font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">{{ __('Global') }}</p>
        </article>

        <article class="rounded-2xl border border-zinc-200/80 bg-white p-5 shadow-sm dark:border-zinc-700/80 dark:bg-zinc-900">
            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">{{ __('Pending POs') }}</p>
            <p class="mt-3 text-3xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">{{ number_format($pendingPOs) }}</p>
            <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Draft + Pending purchase orders') }}</p>
            <p class="mt-3 inline-flex rounded-full bg-amber-50 px-2 py-1 text-[11px] font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">{{ __('Global') }}</p>
        </article>
    </section>

    <section id="dashboard-chart-root" data-dashboard-charts='@json($chartPayload)' class="space-y-8">
        <div class="rounded-2xl border border-zinc-200/80 bg-white p-6 shadow-sm dark:border-zinc-700/80 dark:bg-zinc-900">
            <h2 class="text-base font-semibold text-zinc-900 dark:text-zinc-50">{{ __('Revenue vs Expenses Trend') }}</h2>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Daily trend inside selected date range') }}</p>
            <div data-chart-target="trend" class="mt-6 h-[22rem]"></div>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="rounded-2xl border border-zinc-200/80 bg-white p-6 shadow-sm dark:border-zinc-700/80 dark:bg-zinc-900">
                <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-50">{{ __('Receivables Split') }}</h3>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    {{ __('Outstanding AR balance split into current (not overdue) vs overdue.') }}
                </p>
                <div data-chart-target="receivables" class="mt-5 min-h-[20rem]"></div>
                <div class="mt-3 grid grid-cols-2 gap-2 text-xs text-zinc-600 dark:text-zinc-300">
                    <p>{{ __('Current') }}: <span class="font-semibold tabular-nums">{{ number_format($receivablesDue, $moneyDigits) }} {{ $currency }}</span></p>
                    <p>{{ __('Overdue') }}: <span class="font-semibold tabular-nums">{{ number_format($receivablesOverdue, $moneyDigits) }} {{ $currency }}</span></p>
                </div>
            </div>
            <div class="rounded-2xl border border-zinc-200/80 bg-white p-6 shadow-sm dark:border-zinc-700/80 dark:bg-zinc-900">
                <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-50">{{ __('Payables Split') }}</h3>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    {{ __('Outstanding AP balance split into current (not overdue) vs overdue.') }}
                </p>
                <div data-chart-target="payables" class="mt-5 min-h-[20rem]"></div>
                <div class="mt-3 grid grid-cols-2 gap-2 text-xs text-zinc-600 dark:text-zinc-300">
                    <p>{{ __('Current') }}: <span class="font-semibold tabular-nums">{{ number_format($payablesDue, $moneyDigits) }} {{ $currency }}</span></p>
                    <p>{{ __('Overdue') }}: <span class="font-semibold tabular-nums">{{ number_format($payablesOverdue, $moneyDigits) }} {{ $currency }}</span></p>
                </div>
            </div>
            <div class="rounded-2xl border border-zinc-200/80 bg-white p-6 shadow-sm dark:border-zinc-700/80 dark:bg-zinc-900">
                <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-50">{{ __('Invoice Status Mix (Amount)') }}</h3>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    {{ __('Total invoice amount split by status in selected date range.') }}
                </p>
                <div data-chart-target="invoice-status" class="mt-5 min-h-[20rem]"></div>
            </div>
        </div>
    </section>
</div>
