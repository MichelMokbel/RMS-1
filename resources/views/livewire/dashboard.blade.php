<?php

use App\Models\Order;
use App\Models\MealSubscription;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ApInvoice;
use App\Models\ArInvoice;
use App\Models\PurchaseOrder;
use App\Support\Money\MinorUnits;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public function with(): array
    {
        $today = Carbon::today();
        $startOfMonth = Carbon::today()->startOfMonth();
        $endOfMonth = Carbon::today()->endOfMonth();
        $startOfLastMonth = Carbon::today()->subMonth()->startOfMonth();
        $endOfLastMonth = Carbon::today()->subMonth()->endOfMonth();

        // Orders metrics
        $todayOrders = Order::whereDate('scheduled_date', $today)->get();
        $todayOrdersCount = $todayOrders->count();
        $todayOrdersTotal = $todayOrders->sum('total_amount');

        $monthOrders = Order::whereDate('scheduled_date', '>=', $startOfMonth)
            ->whereDate('scheduled_date', '<=', $endOfMonth)
            ->whereIn('status', ['Confirmed', 'InProduction', 'Ready', 'Delivered'])
            ->get();
        $monthOrdersCount = $monthOrders->count();
        $monthOrdersTotal = $monthOrders->sum('total_amount');

        $lastMonthOrders = Order::whereDate('scheduled_date', '>=', $startOfLastMonth)
            ->whereDate('scheduled_date', '<=', $endOfLastMonth)
            ->whereIn('status', ['Confirmed', 'InProduction', 'Ready', 'Delivered'])
            ->get();
        $lastMonthOrdersTotal = $lastMonthOrders->sum('total_amount');

        // Subscriptions
        $activeSubscriptions = MealSubscription::where('status', 'active')->count();
        $pausedSubscriptions = MealSubscription::where('status', 'paused')->count();

        // Customers
        $totalCustomers = Schema::hasTable('customers') ? Customer::count() : 0;
        $newCustomersThisMonth = Schema::hasTable('customers') 
            ? Customer::whereDate('created_at', '>=', $startOfMonth)->count() 
            : 0;

        // Financial - Payables (ApInvoice: posted/partially_paid, outstanding = total_amount - allocations)
        $payablesDue = 0;
        $payablesOverdue = 0;
        if (Schema::hasTable('ap_invoices')) {
            $payablesDueInvoices = ApInvoice::with('allocations')
                ->whereIn('status', ['posted', 'partially_paid'])
                ->whereDate('due_date', '>=', $today)
                ->get();
            $payablesDue = $payablesDueInvoices->sum(fn ($inv) => (float) $inv->total_amount - $inv->allocations->sum('allocated_amount'));

            $payablesOverdueInvoices = ApInvoice::with('allocations')
                ->whereIn('status', ['posted', 'partially_paid'])
                ->whereDate('due_date', '<', $today)
                ->get();
            $payablesOverdue = $payablesOverdueInvoices->sum(fn ($inv) => (float) $inv->total_amount - $inv->allocations->sum('allocated_amount'));
        }

        // Financial - Receivables (ArInvoice: issued/partially_paid, balance_cents → convert to display units)
        $receivablesDue = 0;
        $receivablesOverdue = 0;
        if (Schema::hasTable('ar_invoices')) {
            $scale = MinorUnits::posScale();
            $receivablesDueCents = ArInvoice::whereIn('status', ['issued', 'partially_paid'])
                ->whereDate('due_date', '>=', $today)
                ->sum('balance_cents');
            $receivablesOverdueCents = ArInvoice::whereIn('status', ['issued', 'partially_paid'])
                ->whereDate('due_date', '<', $today)
                ->sum('balance_cents');
            $receivablesDue = $scale > 0 ? ($receivablesDueCents / $scale) : $receivablesDueCents;
            $receivablesOverdue = $scale > 0 ? ($receivablesOverdueCents / $scale) : $receivablesOverdueCents;
        }

        // Expenses this month
        $expensesThisMonth = 0;
        if (Schema::hasTable('expenses')) {
            $expensesThisMonth = Expense::whereDate('expense_date', '>=', $startOfMonth)
                ->whereDate('expense_date', '<=', $endOfMonth)
                ->sum('total_amount');
        }

        // Pending purchase orders
        $pendingPOs = 0;
        if (Schema::hasTable('purchase_orders')) {
            $pendingPOs = PurchaseOrder::whereIn('status', ['draft', 'sent'])->count();
        }

        // Revenue change percentage
        $revenueChange = 0;
        if ($lastMonthOrdersTotal > 0) {
            $revenueChange = round((($monthOrdersTotal - $lastMonthOrdersTotal) / $lastMonthOrdersTotal) * 100, 1);
        }

        return [
            'todayOrdersCount' => $todayOrdersCount,
            'todayOrdersTotal' => $todayOrdersTotal,
            'monthOrdersCount' => $monthOrdersCount,
            'monthOrdersTotal' => $monthOrdersTotal,
            'revenueChange' => $revenueChange,
            'activeSubscriptions' => $activeSubscriptions,
            'pausedSubscriptions' => $pausedSubscriptions,
            'totalCustomers' => $totalCustomers,
            'newCustomersThisMonth' => $newCustomersThisMonth,
            'payablesDue' => $payablesDue,
            'payablesOverdue' => $payablesOverdue,
            'receivablesDue' => $receivablesDue,
            'receivablesOverdue' => $receivablesOverdue,
            'expensesThisMonth' => $expensesThisMonth,
            'pendingPOs' => $pendingPOs,
        ];
    }
}; ?>

<div class="w-full max-w-7xl mx-auto px-4 py-8 space-y-8">
    {{-- Header --}}
    <header class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-zinc-50">{{ __('Dashboard') }}</h1>
            <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Welcome back! Here\'s what\'s happening today.') }}</p>
        </div>
        <time class="text-sm font-medium text-zinc-500 dark:text-zinc-400" datetime="{{ now()->toIso8601String() }}">
            {{ now()->format('l, F j, Y') }}
        </time>
    </header>

    {{-- Key Metrics --}}
    <section class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
        <a href="{{ route('orders.index') }}" wire:navigate class="group relative overflow-hidden rounded-2xl border border-zinc-200/80 bg-white p-6 shadow-sm transition hover:border-zinc-300 hover:shadow dark:border-zinc-700/80 dark:bg-zinc-900 dark:hover:border-zinc-600">
            <div class="flex items-start justify-between">
                <div class="flex size-11 items-center justify-center rounded-xl bg-sky-50 text-sky-600 dark:bg-sky-950 dark:text-sky-400">
                    <flux:icon name="clipboard-document-list" class="size-6" />
                </div>
                <span class="text-xs font-medium text-zinc-400 group-hover:text-primary-500 dark:text-zinc-500">{{ __('View all') }}</span>
            </div>
            <p class="mt-4 text-3xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">{{ $todayOrdersCount }}</p>
            <p class="mt-1 text-sm font-medium text-zinc-600 dark:text-zinc-400">{{ __('Today\'s Orders') }}</p>
            <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-500">{{ number_format($todayOrdersTotal, 3) }} {{ __('total') }}</p>
        </a>

        <div class="relative overflow-hidden rounded-2xl border border-zinc-200/80 bg-white p-6 shadow-sm dark:border-zinc-700/80 dark:bg-zinc-900">
            <div class="flex items-start justify-between">
                <div class="flex size-11 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 dark:bg-emerald-950 dark:text-emerald-400">
                    <flux:icon name="banknotes" class="size-6" />
                </div>
                @if ($revenueChange != 0)
                    <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $revenueChange > 0 ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-300' : 'bg-rose-100 text-rose-700 dark:bg-rose-900/50 dark:text-rose-300' }}">
                        {{ $revenueChange > 0 ? '+' : '' }}{{ $revenueChange }}%
                    </span>
                @endif
            </div>
            <p class="mt-4 text-3xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">{{ number_format($monthOrdersTotal, 3) }}</p>
            <p class="mt-1 text-sm font-medium text-zinc-600 dark:text-zinc-400">{{ __('This Month\'s Revenue') }}</p>
            <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-500">{{ $monthOrdersCount }} {{ __('orders') }}</p>
        </div>

        <a href="{{ route('subscriptions.index') }}" wire:navigate class="group relative overflow-hidden rounded-2xl border border-zinc-200/80 bg-white p-6 shadow-sm transition hover:border-zinc-300 hover:shadow dark:border-zinc-700/80 dark:bg-zinc-900 dark:hover:border-zinc-600">
            <div class="flex items-start justify-between">
                <div class="flex size-11 items-center justify-center rounded-xl bg-violet-50 text-violet-600 dark:bg-violet-950 dark:text-violet-400">
                    <flux:icon name="ticket" class="size-6" />
                </div>
                <span class="text-xs font-medium text-zinc-400 group-hover:text-primary-500 dark:text-zinc-500">{{ __('View all') }}</span>
            </div>
            <p class="mt-4 text-3xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">{{ $activeSubscriptions }}</p>
            <p class="mt-1 text-sm font-medium text-zinc-600 dark:text-zinc-400">{{ __('Active Subscriptions') }}</p>
            <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-500">{{ $pausedSubscriptions }} {{ __('paused') }}</p>
        </a>

        <a href="{{ route('customers.index') }}" wire:navigate class="group relative overflow-hidden rounded-2xl border border-zinc-200/80 bg-white p-6 shadow-sm transition hover:border-zinc-300 hover:shadow dark:border-zinc-700/80 dark:bg-zinc-900 dark:hover:border-zinc-600">
            <div class="flex items-start justify-between">
                <div class="flex size-11 items-center justify-center rounded-xl bg-amber-50 text-amber-600 dark:bg-amber-950 dark:text-amber-400">
                    <flux:icon name="users" class="size-6" />
                </div>
                <span class="text-xs font-medium text-zinc-400 group-hover:text-primary-500 dark:text-zinc-500">{{ __('View all') }}</span>
            </div>
            <p class="mt-4 text-3xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">{{ number_format($totalCustomers) }}</p>
            <p class="mt-1 text-sm font-medium text-zinc-600 dark:text-zinc-400">{{ __('Total Customers') }}</p>
            <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-500">+{{ $newCustomersThisMonth }} {{ __('this month') }}</p>
        </a>

        <a href="{{ route('payables.index') }}" wire:navigate class="group relative overflow-hidden rounded-2xl border border-zinc-200/80 bg-white p-6 shadow-sm transition hover:border-zinc-300 hover:shadow dark:border-zinc-700/80 dark:bg-zinc-900 dark:hover:border-zinc-600">
            <div class="flex items-start justify-between">
                <div class="flex size-11 items-center justify-center rounded-xl bg-rose-50 text-rose-600 dark:bg-rose-950 dark:text-rose-400">
                    <flux:icon name="document-text" class="size-6" />
                </div>
                <span class="text-xs font-medium text-zinc-400 group-hover:text-primary-500 dark:text-zinc-500">{{ __('View all') }}</span>
            </div>
            <p class="mt-4 text-3xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">{{ number_format($payablesDue + $payablesOverdue, 3) }}</p>
            <p class="mt-1 text-sm font-medium text-zinc-600 dark:text-zinc-400">{{ __('Payables Due') }}</p>
            <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-500">{{ __('Overdue') }}: {{ number_format($payablesOverdue, 3) }}</p>
        </a>

        <a href="{{ route('expenses.index') }}" wire:navigate class="group relative overflow-hidden rounded-2xl border border-zinc-200/80 bg-white p-6 shadow-sm transition hover:border-zinc-300 hover:shadow dark:border-zinc-700/80 dark:bg-zinc-900 dark:hover:border-zinc-600">
            <div class="flex items-start justify-between">
                <div class="flex size-11 items-center justify-center rounded-xl bg-orange-50 text-orange-600 dark:bg-orange-950 dark:text-orange-400">
                    <flux:icon name="currency-dollar" class="size-6" />
                </div>
                <span class="text-xs font-medium text-zinc-400 group-hover:text-primary-500 dark:text-zinc-500">{{ __('View all') }}</span>
            </div>
            <p class="mt-4 text-3xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">{{ number_format($expensesThisMonth, 3) }}</p>
            <p class="mt-1 text-sm font-medium text-zinc-600 dark:text-zinc-400">{{ __('Expenses This Month') }}</p>
            <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-500">{{ __('Pending POs') }}: {{ $pendingPOs }}</p>
        </a>
    </section>

    {{-- Financial Overview --}}
    <section>
        <div class="rounded-2xl border border-zinc-200/80 bg-white p-6 shadow-sm dark:border-zinc-700/80 dark:bg-zinc-900">
            <h2 class="text-base font-semibold text-zinc-900 dark:text-zinc-50">{{ __('Financial Overview') }}</h2>
            <div class="mt-5 space-y-3">
                <div class="flex items-center justify-between rounded-xl border border-zinc-100 bg-zinc-50/50 px-4 py-3 dark:border-zinc-800 dark:bg-zinc-800/30">
                    <div>
                        <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('Receivables Due') }}</p>
                        <p class="text-xs text-zinc-500 dark:text-zinc-500">{{ __('Overdue') }}: {{ number_format($receivablesOverdue, 3) }}</p>
                    </div>
                    <p class="text-lg font-semibold tabular-nums text-emerald-600 dark:text-emerald-400">{{ number_format($receivablesDue + $receivablesOverdue, 3) }}</p>
                </div>
                <div class="flex items-center justify-between rounded-xl border border-zinc-100 bg-zinc-50/50 px-4 py-3 dark:border-zinc-800 dark:bg-zinc-800/30">
                    <div>
                        <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('Payables Due') }}</p>
                        <p class="text-xs text-zinc-500 dark:text-zinc-500">{{ __('Overdue') }}: {{ number_format($payablesOverdue, 3) }}</p>
                    </div>
                    <p class="text-lg font-semibold tabular-nums text-rose-600 dark:text-rose-400">{{ number_format($payablesDue + $payablesOverdue, 3) }}</p>
                </div>
                <div class="flex items-center justify-between rounded-xl border border-zinc-100 bg-zinc-50/50 px-4 py-3 dark:border-zinc-800 dark:bg-zinc-800/30">
                    <div>
                        <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('Expenses This Month') }}</p>
                        <p class="text-xs text-zinc-500 dark:text-zinc-500">{{ __('Pending POs') }}: {{ $pendingPOs }}</p>
                    </div>
                    <p class="text-lg font-semibold tabular-nums text-zinc-700 dark:text-zinc-300">{{ number_format($expensesThisMonth, 3) }}</p>
                </div>
            </div>
        </div>
    </section>

</div>
