<?php

use App\Models\AccountingCompany;
use App\Models\ApInvoice;
use App\Models\ApPayment;
use App\Models\BankTransaction;
use App\Models\BudgetVersion;
use App\Models\JournalEntry;
use App\Models\Job;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public function with(): array
    {
        $stats = [
            'companies' => 0,
            'open_bills' => 0,
            'cash_outflow_month' => 0.0,
            'open_bank_items' => 0,
            'active_jobs' => 0,
            'active_budgets' => 0,
            'draft_journals' => 0,
        ];

        if (Schema::hasTable('accounting_companies')) {
            $stats['companies'] = AccountingCompany::query()->where('is_active', true)->count();
        }
        if (Schema::hasTable('ap_invoices')) {
            $stats['open_bills'] = ApInvoice::query()
                ->whereIn('status', ['draft', 'posted', 'partially_paid'])
                ->count();
        }
        if (Schema::hasTable('ap_payments')) {
            $stats['cash_outflow_month'] = (float) ApPayment::query()
                ->whereMonth('payment_date', now()->month)
                ->whereYear('payment_date', now()->year)
                ->sum('amount');
        }
        if (Schema::hasTable('bank_transactions')) {
            $stats['open_bank_items'] = BankTransaction::query()->where('status', 'open')->count();
        }
        if (Schema::hasTable('accounting_jobs')) {
            $stats['active_jobs'] = Job::query()->where('status', 'active')->count();
        }
        if (Schema::hasTable('budget_versions')) {
            $stats['active_budgets'] = BudgetVersion::query()->where('is_active', true)->count();
        }
        if (Schema::hasTable('journal_entries')) {
            $stats['draft_journals'] = JournalEntry::query()->where('status', 'draft')->count();
        }

        return [
            'stats' => $stats,
            'recentBills' => Schema::hasTable('ap_invoices')
                ? ApInvoice::query()->with('supplier')->latest('invoice_date')->limit(8)->get()
                : collect(),
            'recentBankTransactions' => Schema::hasTable('bank_transactions')
                ? BankTransaction::query()->with('bankAccount')->latest('transaction_date')->limit(8)->get()
                : collect(),
        ];
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Accounting') }}</h1>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Sage 50-style accounting workspace for payables, banking, budgets, jobs, and journals.') }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <flux:button :href="route('settings.accounting', ['tab' => 'chart'])" wire:navigate variant="ghost">{{ __('Chart of Accounts') }}</flux:button>
            <flux:button :href="route('accounting.banking')" wire:navigate variant="ghost">{{ __('Banking') }}</flux:button>
            <flux:button :href="route('accounting.jobs')" wire:navigate variant="ghost">{{ __('Jobs') }}</flux:button>
            <flux:button :href="route('reports.index', ['category' => 'accounting'])" wire:navigate variant="ghost">{{ __('Reports') }}</flux:button>
            <flux:button :href="route('accounting.period-close')" wire:navigate>{{ __('Period Close') }}</flux:button>
        </div>
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <p class="text-xs uppercase tracking-wide text-neutral-500">{{ __('Companies') }}</p>
            <p class="mt-2 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ $stats['companies'] }}</p>
        </div>
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <p class="text-xs uppercase tracking-wide text-neutral-500">{{ __('Open Bills') }}</p>
            <p class="mt-2 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ $stats['open_bills'] }}</p>
        </div>
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <p class="text-xs uppercase tracking-wide text-neutral-500">{{ __('Month Cash Outflow') }}</p>
            <p class="mt-2 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format((float) $stats['cash_outflow_month'], 2) }}</p>
        </div>
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <p class="text-xs uppercase tracking-wide text-neutral-500">{{ __('Open Bank Items') }}</p>
            <p class="mt-2 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ $stats['open_bank_items'] }}</p>
        </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Recent Bills') }}</h2>
                <flux:button size="xs" :href="route('payables.index')" wire:navigate>{{ __('Open Payables') }}</flux:button>
            </div>
            <div class="app-table-shell">
                <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                    <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Invoice') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Supplier') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Type') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Amount') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        @forelse($recentBills as $bill)
                            <tr>
                                <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $bill->invoice_number }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $bill->supplier?->name ?? '—' }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $bill->document_type ?? 'vendor_bill' }}</td>
                                <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $bill->total_amount, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-3 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No bills available.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Latest Bank Activity') }}</h2>
                <flux:button size="xs" :href="route('accounting.banking')" wire:navigate>{{ __('Open Banking') }}</flux:button>
            </div>
            <div class="app-table-shell">
                <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                    <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Date') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Account') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Reference') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Amount') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        @forelse($recentBankTransactions as $transaction)
                            <tr>
                                <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $transaction->transaction_date?->format('Y-m-d') }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $transaction->bankAccount?->name ?? '—' }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $transaction->reference ?? $transaction->transaction_type }}</td>
                                <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $transaction->amount, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-3 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No bank activity available.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
