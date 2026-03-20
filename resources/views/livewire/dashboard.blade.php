<?php

use App\Models\ApInvoice;
use App\Models\ArInvoice;
use App\Models\Branch;
use App\Models\CompanyFoodProject;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\MealPlanRequest;
use App\Models\MealSubscription;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\Recipe;
use App\Models\User;
use App\Services\Spend\SpendReportService;
use App\Support\Money\MinorUnits;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public int $branch_id = 0;
    public ?string $date_from = null;
    public ?string $date_to = null;

    public function mount(): void
    {
        /** @var User $user */
        $user = Auth::user();
        $branches = $this->availableBranches($user);
        $allowedBranchIds = $branches->pluck('id')->map(fn ($id) => (int) $id)->all();

        $requestedBranchId = max(0, (int) request()->integer('branch_id', 0));

        if ($requestedBranchId > 0 && in_array($requestedBranchId, $allowedBranchIds, true)) {
            $this->branch_id = $requestedBranchId;
        } elseif (count($allowedBranchIds) === 1) {
            $this->branch_id = $allowedBranchIds[0];
        }

        $this->date_from = now()->startOfMonth()->toDateString();
        $this->date_to = now()->endOfMonth()->toDateString();
    }

    public function with(): array
    {
        /** @var User $user */
        $user = Auth::user();

        $branches = $this->availableBranches($user);
        $allowedBranchIds = $branches->pluck('id')->map(fn ($id) => (int) $id)->all();
        $selectedBranchId = in_array((int) $this->branch_id, $allowedBranchIds, true) ? (int) $this->branch_id : 0;

        [$from, $to] = $this->normalizedRange();
        [$previousFrom, $previousTo] = $this->previousRange($from, $to);
        $today = Carbon::today();

        $scale = max(1, MinorUnits::posScale());
        $moneyDigits = MinorUnits::scaleDigits($scale);
        $currency = (string) config('pos.currency', 'QAR');

        $canViewSales = $this->canViewSales($user);
        $canViewSupplyChain = $this->canViewSupplyChain($user);
        $canViewFinance = $this->canViewFinance($user);
        $canViewPrograms = $this->canViewPrograms($user);
        $canViewCatalog = $this->canViewCatalog($user);
        $canViewReports = $this->canViewReports($user);
        $canViewOrderMetrics = $user->hasAnyRole(['admin', 'manager', 'cashier']) || $user->can('orders.access');
        $canViewReceivablesMetrics = $user->hasAnyRole(['admin', 'manager']) || $user->can('receivables.access');
        $applyBranchScope = function (Builder $query, string $column = 'branch_id') use ($user, $selectedBranchId, $allowedBranchIds): Builder {
            if ($selectedBranchId > 0) {
                return $query->where($column, $selectedBranchId);
            }

            if ($user->isAdmin()) {
                return $query;
            }

            if ($allowedBranchIds === []) {
                return $query->whereRaw('1 = 0');
            }

            return $query->whereIn($column, $allowedBranchIds);
        };

        $revenueInRange = 0.0;
        $previousRevenue = 0.0;
        $revenueChangePct = null;
        $receivablesDue = 0.0;
        $receivablesOverdue = 0.0;
        $todayOrdersCount = 0;
        $todayOrdersTotal = 0.0;
        $totalCustomers = 0;
        $newCustomersInRange = 0;
        $activeSubscriptions = 0;
        $pausedSubscriptions = 0;
        $pendingMealPlanRequests = 0;
        $activeCompanyFoodProjects = 0;
        $inventoryItemsCount = 0;
        $pendingPOs = 0;
        $payablesDue = 0.0;
        $payablesOverdue = 0.0;
        $expensesInRange = 0.0;
        $menuItemsCount = 0;
        $activeMenuItemsCount = 0;
        $recipesCount = 0;
        $draftRecipesCount = 0;

        if ($canViewSales) {
            $revenueInRangeCents = $this->arPaidRevenueCents($from, $to, $applyBranchScope);
            $previousRevenueCents = $this->arPaidRevenueCents($previousFrom, $previousTo, $applyBranchScope);

            $revenueInRange = $this->fromMinor($revenueInRangeCents, $scale, $moneyDigits);
            $previousRevenue = $this->fromMinor($previousRevenueCents, $scale, $moneyDigits);

            if ($previousRevenue > 0) {
                $revenueChangePct = round((($revenueInRange - $previousRevenue) / $previousRevenue) * 100, 1);
            }

            $todayOrdersQuery = Order::query()->whereDate('scheduled_date', $today);
            $applyBranchScope($todayOrdersQuery);
            $todayOrdersCount = (clone $todayOrdersQuery)->count();
            $todayOrdersTotal = (float) (clone $todayOrdersQuery)->sum('total_amount');

            if (Schema::hasTable('customers')) {
                $totalCustomers = Customer::count();
                $newCustomersInRange = Customer::query()
                    ->whereDate('created_at', '>=', $from->toDateString())
                    ->whereDate('created_at', '<=', $to->toDateString())
                    ->count();
            }

            if (Schema::hasTable('ar_invoices')) {
                $receivablesBase = ArInvoice::query()
                    ->where('type', 'invoice')
                    ->whereIn('status', ['issued', 'partially_paid'])
                    ->where('balance_cents', '>', 0);
                $applyBranchScope($receivablesBase);

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
        }

        if ($canViewPrograms) {
            $subscriptionsQuery = MealSubscription::query();
            $applyBranchScope($subscriptionsQuery);

            $activeSubscriptions = (clone $subscriptionsQuery)->where('status', 'active')->count();
            $pausedSubscriptions = (clone $subscriptionsQuery)->where('status', 'paused')->count();

            if (Schema::hasTable('meal_plan_requests')) {
                $pendingMealPlanRequests = MealPlanRequest::query()
                    ->whereIn('status', ['new', 'contacted'])
                    ->count();
            }

            if (Schema::hasTable('company_food_projects')) {
                $activeCompanyFoodProjects = CompanyFoodProject::query()
                    ->where('is_active', true)
                    ->count();
            }
        }

        if ($canViewSupplyChain) {
            if (Schema::hasTable('inventory_items')) {
                $inventoryItemsCount = InventoryItem::query()->count();
            }

            if (Schema::hasTable('purchase_orders')) {
                $pendingPOs = PurchaseOrder::query()
                    ->whereIn('status', [PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_PENDING])
                    ->count();
            }
        }

        if ($canViewFinance) {
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
        }

        if ($canViewCatalog) {
            if (Schema::hasTable('menu_items')) {
                $menuItemsCount = MenuItem::query()->count();
                $activeMenuItemsCount = MenuItem::query()->active()->count();
            }

            if (Schema::hasTable('recipes')) {
                $recipesCount = Recipe::query()->count();
                $draftRecipesCount = Recipe::query()->where('status', 'draft')->count();
            }
        }

        $kpiCards = [];

        $revenueCard = $this->card(
            __('Revenue (Paid Invoices)'),
            number_format($revenueInRange, $moneyDigits),
            [
                __(':from - :to', ['from' => $from->toDateString(), 'to' => $to->toDateString()]),
                __('Previous: :value', ['value' => number_format($previousRevenue, $moneyDigits)]),
            ],
            __('Branch-aware'),
            true,
            $revenueChangePct !== null ? ($revenueChangePct >= 0 ? '+'.$revenueChangePct.'%' : $revenueChangePct.'%') : null,
            $revenueChangePct !== null && $revenueChangePct < 0 ? 'danger' : 'success'
        );

        $receivablesCard = $this->card(
            __('Receivables Outstanding'),
            number_format($receivablesDue + $receivablesOverdue, $moneyDigits),
            [
                __('Current: :current', ['current' => number_format($receivablesDue, $moneyDigits)]),
                __('Overdue: :overdue', ['overdue' => number_format($receivablesOverdue, $moneyDigits)]),
            ],
            __('Branch-aware'),
            true
        );

        $payablesCard = $this->card(
            __('Payables Outstanding'),
            number_format($payablesDue + $payablesOverdue, $moneyDigits),
            [
                __('Current: :current', ['current' => number_format($payablesDue, $moneyDigits)]),
                __('Overdue: :overdue', ['overdue' => number_format($payablesOverdue, $moneyDigits)]),
            ],
            __('Global'),
            false
        );

        $expensesCard = $this->card(
            __('Expenses (Range)'),
            number_format($expensesInRange, $moneyDigits),
            [
                __(':from - :to', ['from' => $from->toDateString(), 'to' => $to->toDateString()]),
                __('Pending POs: :count', ['count' => number_format($pendingPOs)]),
            ],
            __('Global'),
            false
        );

        $todayOrdersCard = $this->card(
            __('Today\'s Orders'),
            number_format($todayOrdersCount),
            [
                __('Total amount: :value', ['value' => number_format($todayOrdersTotal, $moneyDigits)]),
            ],
            __('Branch-aware'),
            true
        );

        $subscriptionsCard = $this->card(
            __('Active Subscriptions'),
            number_format($activeSubscriptions),
            [
                __('Paused: :count', ['count' => number_format($pausedSubscriptions)]),
            ],
            __('Branch-aware'),
            true
        );

        $customersCard = $this->card(
            __('Total Customers'),
            number_format($totalCustomers),
            [
                __('+:count in selected range', ['count' => number_format($newCustomersInRange)]),
            ],
            __('Global'),
            false
        );

        $pendingPOsCard = $this->card(
            __('Pending POs'),
            number_format($pendingPOs),
            [
                __('Draft + Pending purchase orders'),
            ],
            __('Global'),
            false
        );

        $mealPlanRequestsCard = $this->card(
            __('Pending Meal Plan Requests'),
            number_format($pendingMealPlanRequests),
            [
                __('Statuses: New + Contacted'),
            ],
            __('Global'),
            false
        );

        $companyFoodCard = $this->card(
            __('Active Company Food Projects'),
            number_format($activeCompanyFoodProjects),
            [
                __('Backoffice projects currently marked active'),
            ],
            __('Global'),
            false
        );

        $inventoryItemsCard = $this->card(
            __('Inventory Items'),
            number_format($inventoryItemsCount),
            [
                __('Current item master count'),
            ],
            __('Global'),
            false
        );

        $menuItemsCard = $this->card(
            __('Menu Items'),
            number_format($menuItemsCount),
            [
                __('Active: :count', ['count' => number_format($activeMenuItemsCount)]),
            ],
            __('Global'),
            false
        );

        $recipesCard = $this->card(
            __('Recipes'),
            number_format($recipesCount),
            [
                __('Draft: :count', ['count' => number_format($draftRecipesCount)]),
            ],
            __('Global'),
            false
        );

        if ($user->hasAnyRole(['admin', 'manager'])) {
            if ($canViewReceivablesMetrics) {
                $kpiCards[] = $revenueCard;
                $kpiCards[] = $receivablesCard;
            }
            if ($canViewFinance) {
                $kpiCards[] = $payablesCard;
                $kpiCards[] = $expensesCard;
            }
            if ($canViewOrderMetrics) {
                $kpiCards[] = $todayOrdersCard;
            }
            if ($canViewPrograms) {
                $kpiCards[] = $subscriptionsCard;
            }
            if ($canViewReceivablesMetrics) {
                $kpiCards[] = $customersCard;
            }
            if ($canViewSupplyChain) {
                $kpiCards[] = $pendingPOsCard;
            }
        } elseif ($canViewFinance && $canViewReceivablesMetrics) {
            $kpiCards = [$revenueCard, $receivablesCard, $payablesCard, $expensesCard];
        } elseif ($canViewReceivablesMetrics) {
            $kpiCards = [$revenueCard, $receivablesCard, $todayOrdersCard, $customersCard];
        } elseif ($canViewPrograms) {
            $kpiCards = array_values(array_filter([
                $canViewOrderMetrics ? $todayOrdersCard : null,
                $subscriptionsCard,
                $mealPlanRequestsCard,
                $companyFoodCard,
            ]));
        } elseif ($canViewSupplyChain) {
            $kpiCards = [$pendingPOsCard, $inventoryItemsCard];
        } elseif ($canViewCatalog) {
            $kpiCards = [$menuItemsCard, $recipesCard];
        }

        $canCreatePurchaseOrders = $user->hasRole('admin')
            || $user->hasRole('manager')
            || $user->can('catalog.access')
            || $user->can('finance.access')
            || $user->can('operations.access');
        $canManageReceivables = $user->hasRole('admin') || $user->hasRole('manager') || $user->can('receivables.access');

        $quickActions = collect([
            $this->action(__('Create PO'), route('purchase-orders.create'), $canCreatePurchaseOrders),
            $this->action(__('Create Invoice'), route('invoices.create'), $canManageReceivables),
            $this->action(__('Add Customer Payment'), route('receivables.payments.create'), $canManageReceivables),
        ])->filter()->values()->all();

        $showCharts = $user->isAdmin() || $canViewFinance || $canViewReports;
        $chartPayload = null;

        if ($showCharts) {
            $rangeLabels = $this->dailyLabels($from, $to);

            $revenueByDateQuery = ArInvoice::query()
                ->where('type', 'invoice')
                ->whereIn('status', ['paid', 'partially_paid'])
                ->whereDate('issue_date', '>=', $from->toDateString())
                ->whereDate('issue_date', '<=', $to->toDateString());
            $applyBranchScope($revenueByDateQuery);

            $revenueByDate = $revenueByDateQuery
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

            $statusMixQuery = ArInvoice::query()
                ->where('type', 'invoice')
                ->whereIn('status', ['paid', 'partially_paid', 'issued'])
                ->whereDate('issue_date', '>=', $from->toDateString())
                ->whereDate('issue_date', '<=', $to->toDateString());
            $applyBranchScope($statusMixQuery);

            $statusMixRaw = $statusMixQuery
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
        }

        return [
            'user' => $user,
            'branches' => $branches,
            'moneyDigits' => $moneyDigits,
            'currency' => $currency,
            'fromDateLabel' => $from->toDateString(),
            'toDateLabel' => $to->toDateString(),
            'hasBranchScope' => $selectedBranchId > 0 || (! $user->isAdmin() && $allowedBranchIds !== []),
            'kpiCards' => $kpiCards,
            'quickActions' => $quickActions,
            'showFilters' => $branches->isNotEmpty() && ($canViewSales || $canViewPrograms || $canViewSupplyChain || $showCharts),
            'showCharts' => $showCharts,
            'chartPayload' => $chartPayload,
        ];
    }

    private function action(string $label, string $url, bool $visible): ?array
    {
        if (! $visible) {
            return null;
        }

        return [
            'label' => $label,
            'url' => $url,
        ];
    }

    private function card(
        string $label,
        string $value,
        array $metaLines,
        string $scopeLabel,
        bool $branchAware,
        ?string $badge = null,
        string $badgeTone = 'neutral'
    ): array {
        return [
            'label' => $label,
            'value' => $value,
            'meta_lines' => $metaLines,
            'scope_label' => $scopeLabel,
            'scope_tone' => $branchAware ? 'branch' : 'global',
            'badge' => $badge,
            'badge_tone' => $badgeTone,
        ];
    }

    private function availableBranches(User $user): Collection
    {
        if (! Schema::hasTable('branches')) {
            return collect();
        }

        $allowedBranchIds = $user->allowedBranchIds();

        return Branch::query()
            ->when(Schema::hasColumn('branches', 'is_active'), fn ($q) => $q->where('is_active', 1))
            ->when(! $user->isAdmin(), fn ($q) => $allowedBranchIds === [] ? $q->whereRaw('1 = 0') : $q->whereIn('id', $allowedBranchIds))
            ->orderBy('name')
            ->get();
    }

    private function canViewSales(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager', 'cashier'])
            || $user->can('orders.access')
            || $user->can('receivables.access');
    }

    private function canViewPrograms(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager']) || $user->can('operations.access');
    }

    private function canViewSupplyChain(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager', 'kitchen', 'cashier']) || $user->can('operations.access');
    }

    private function canViewFinance(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager', 'staff']) || $user->can('finance.access');
    }

    private function canViewCatalog(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager', 'cashier']) || $user->can('catalog.access');
    }

    private function canViewReports(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager', 'staff']) || $user->can('reports.access');
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

    private function arPaidRevenueCents(Carbon $from, Carbon $to, \Closure $applyBranchScope): int
    {
        $query = ArInvoice::query()
            ->where('type', 'invoice')
            ->whereIn('status', ['paid', 'partially_paid'])
            ->whereDate('issue_date', '>=', $from->toDateString())
            ->whereDate('issue_date', '<=', $to->toDateString());

        $applyBranchScope($query);

        return (int) $query->sum('paid_total_cents');
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

<div class="app-page space-y-8 py-8">
    <header class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-zinc-50">{{ __('Dashboard') }}</h1>
            <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('A role-aware overview of the areas you can access in RMS-1.') }}
            </p>
        </div>
        <time class="text-sm font-medium text-zinc-500 dark:text-zinc-400" datetime="{{ now()->toIso8601String() }}">
            {{ now()->format('l, F j, Y') }}
        </time>
    </header>

    @if ($quickActions !== [])
        <section class="space-y-4">
            <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-50">{{ __('Quick Actions') }}</h2>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($quickActions as $action)
                    <a
                        href="{{ $action['url'] }}"
                        wire:navigate
                        class="group rounded-2xl border border-zinc-200/80 bg-white px-4 py-4 shadow-sm transition hover:-translate-y-0.5 hover:border-zinc-300 hover:shadow-md dark:border-zinc-700/80 dark:bg-zinc-900 dark:hover:border-zinc-600"
                    >
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-sm font-semibold text-zinc-900 dark:text-zinc-50">{{ $action['label'] }}</p>
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Open workflow') }}</p>
                            </div>
                            <span class="rounded-full bg-zinc-100 px-2.5 py-1 text-xs font-medium text-zinc-600 transition group-hover:bg-zinc-900 group-hover:text-white dark:bg-zinc-800 dark:text-zinc-300 dark:group-hover:bg-zinc-100 dark:group-hover:text-zinc-900">
                                {{ __('Go') }}
                            </span>
                        </div>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    @if ($showFilters)
        <section class="rounded-2xl border border-zinc-200/80 bg-white p-5 shadow-sm dark:border-zinc-700/80 dark:bg-zinc-900">
            <div class="app-filter-grid">
                <x-reports.branch-select name="branch_id" :branches="$branches" />
                <x-reports.date-range fromName="date_from" toName="date_to" />
            </div>
            <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">
                {{ $hasBranchScope ? __('Branch-aware data is limited to the branches available to your account.') : __('Selected range controls the dashboard summaries and charts.') }}
            </p>
        </section>
    @endif

    @if ($kpiCards !== [])
        <section class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($kpiCards as $card)
                <article class="rounded-2xl border border-zinc-200/80 bg-white p-5 shadow-sm dark:border-zinc-700/80 dark:bg-zinc-900">
                    <div class="flex items-start justify-between gap-3">
                        <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">{{ $card['label'] }}</p>
                        @if (! empty($card['badge']))
                            <span @class([
                                'rounded-full px-2.5 py-1 text-xs font-semibold',
                                'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' => $card['badge_tone'] === 'success',
                                'bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-300' => $card['badge_tone'] === 'danger',
                                'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200' => ! in_array($card['badge_tone'], ['success', 'danger'], true),
                            ])>
                                {{ $card['badge'] }}
                            </span>
                        @endif
                    </div>

                    <p class="mt-3 text-3xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">{{ $card['value'] }}</p>

                    <div class="mt-4 space-y-1.5 text-sm text-zinc-500 dark:text-zinc-400">
                        @foreach ($card['meta_lines'] as $line)
                            <p>{{ $line }}</p>
                        @endforeach
                    </div>

                    <div class="mt-5">
                        <span @class([
                            'inline-flex rounded-full px-3 py-1 text-xs font-semibold',
                            'bg-sky-50 text-sky-700 dark:bg-sky-950/40 dark:text-sky-300' => $card['scope_tone'] === 'branch',
                            'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300' => $card['scope_tone'] === 'global',
                        ])>
                            {{ $card['scope_label'] }}
                        </span>
                    </div>
                </article>
            @endforeach
        </section>
    @endif

    @if ($showCharts && $chartPayload)
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
                </div>

                <div class="rounded-2xl border border-zinc-200/80 bg-white p-6 shadow-sm dark:border-zinc-700/80 dark:bg-zinc-900">
                    <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-50">{{ __('Payables Split') }}</h3>
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                        {{ __('Outstanding AP balance split into current (not overdue) vs overdue.') }}
                    </p>
                    <div data-chart-target="payables" class="mt-5 min-h-[20rem]"></div>
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
    @endif
</div>
