<?php

use App\Models\AccountingCompany;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Job;
use App\Models\Supplier;
use App\Services\Accounting\AccountingReportService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public ?int $company_id = null;
    public ?string $date_from = null;
    public ?string $date_to = null;
    public ?int $supplier_id = null;
    public ?int $branch_id = null;
    public ?int $department_id = null;
    public ?int $job_id = null;

    public function mount(): void
    {
        $this->company_id = AccountingCompany::query()->where('is_default', true)->value('id');
        $this->date_from = now()->startOfMonth()->toDateString();
        $this->date_to = now()->toDateString();
    }

    public function with(AccountingReportService $service): array
    {
        $routeName = request()->route()?->getName();
        $meta = $this->reportMeta($routeName);
        $report = $this->resolveReport($service, $meta['section']);

        return [
            'companies' => AccountingCompany::query()->orderBy('name')->get(),
            'suppliers' => Supplier::query()->orderBy('name')->get(),
            'branches' => Branch::query()->orderBy('name')->get(),
            'departments' => Department::query()->when($this->company_id, fn ($query) => $query->where('company_id', $this->company_id))->orderBy('name')->get(),
            'jobs' => Job::query()->when($this->company_id, fn ($query) => $query->where('company_id', $this->company_id))->orderBy('code')->get(),
            'report' => $report,
            'meta' => $meta,
        ];
    }

    private function resolveReport(AccountingReportService $service, string $section): array
    {
        return match ($section) {
            'trial_balance' => ['trial_balance' => $service->trialBalance((int) $this->company_id, (string) $this->date_to)],
            'profit_and_loss' => ['profit_and_loss' => $service->profitAndLoss((int) $this->company_id, (string) $this->date_to)],
            'balance_sheet' => ['balance_sheet' => $service->balanceSheet((int) $this->company_id, (string) $this->date_to)],
            'cash_flow' => ['cash_flow' => $service->cashFlow((int) $this->company_id, (string) $this->date_to)],
            'bank_reconciliation' => ['bank_reconciliation' => $service->bankReconciliationSummary((int) $this->company_id)],
            'budget_variance' => ['budget_variance' => $service->summary($this->company_id, $this->date_to)['budget_variance'] ?? null],
            'job_profitability' => ['job_profitability' => $service->summary($this->company_id, $this->date_to)['job_profitability'] ?? []],
            'ap_aging' => ['ap_aging' => $service->apAging((int) $this->company_id, [
                'supplier_id' => $this->supplier_id,
                'branch_id' => $this->branch_id,
                'department_id' => $this->department_id,
                'job_id' => $this->job_id,
                'date_to' => $this->date_to,
            ])],
            'vendor_ledger' => ['vendor_ledger' => $service->vendorLedger((int) $this->company_id, $this->supplier_id, $this->date_from, $this->date_to, [
                'branch_id' => $this->branch_id,
                'department_id' => $this->department_id,
                'job_id' => $this->job_id,
            ])],
            'expense_analysis' => ['expense_analysis' => $service->expenseAnalysis((int) $this->company_id, [
                'branch_id' => $this->branch_id,
                'department_id' => $this->department_id,
                'job_id' => $this->job_id,
                'supplier_id' => $this->supplier_id,
                'date_from' => $this->date_from,
                'date_to' => $this->date_to,
            ])],
            'inventory_valuation' => ['inventory_valuation' => $service->inventoryValuation((int) $this->company_id, (string) $this->date_to)],
            'purchase_accruals' => ['purchase_accruals' => $service->purchaseAccruals((int) $this->company_id, [
                'supplier_id' => $this->supplier_id,
                'date_to' => $this->date_to,
            ])],
            'multi_company_summary' => ['multi_company_summary' => $service->multiCompanySummary((string) $this->date_to)],
            default => $service->summary($this->company_id, $this->date_to),
        };
    }

    private function reportMeta(?string $routeName): array
    {
        return match ($routeName) {
            'reports.accounting-trial-balance' => [
                'title' => __('Trial Balance'),
                'description' => __('Review account-by-account debit and credit balances as of the selected date.'),
                'section' => 'trial_balance',
            ],
            'reports.accounting-profit-loss' => [
                'title' => __('Profit & Loss'),
                'description' => __('Review revenue, expenses, and net income through the selected date.'),
                'section' => 'profit_and_loss',
            ],
            'reports.accounting-balance-sheet' => [
                'title' => __('Balance Sheet'),
                'description' => __('Review assets, liabilities, and equity as of the selected date.'),
                'section' => 'balance_sheet',
            ],
            'reports.accounting-cash-flow' => [
                'title' => __('Cash Flow'),
                'description' => __('Review bank-driven inflow and outflow movement through the selected date.'),
                'section' => 'cash_flow',
            ],
            'reports.accounting-bank-reconciliation' => [
                'title' => __('Bank Reconciliation Summary'),
                'description' => __('Review the latest reconciliation runs, variances, and exception counts.'),
                'section' => 'bank_reconciliation',
            ],
            'reports.accounting-budget-variance' => [
                'title' => __('Budget Variance'),
                'description' => __('Compare the active budget to actual posted amounts by period.'),
                'section' => 'budget_variance',
            ],
            'reports.accounting-job-profitability' => [
                'title' => __('Job Profitability'),
                'description' => __('Review actual cost, revenue, and margin by job.'),
                'section' => 'job_profitability',
            ],
            'reports.accounting-ap-aging' => [
                'title' => __('AP Aging'),
                'description' => __('Review outstanding supplier balances by aging bucket as of the selected date.'),
                'section' => 'ap_aging',
            ],
            'reports.accounting-vendor-ledger' => [
                'title' => __('Vendor Ledger'),
                'description' => __('Review supplier invoice and payment movement with accounting context filters.'),
                'section' => 'vendor_ledger',
            ],
            'reports.accounting-expense-analysis' => [
                'title' => __('Expense Analysis'),
                'description' => __('Analyze posted expense activity by supplier and accounting dimensions.'),
                'section' => 'expense_analysis',
            ],
            'reports.accounting-inventory-valuation' => [
                'title' => __('Inventory Valuation'),
                'description' => __('Review inventory balances using accounting transaction history and current cost.'),
                'section' => 'inventory_valuation',
            ],
            'reports.accounting-purchase-accruals' => [
                'title' => __('Purchase Accruals / GRNI'),
                'description' => __('Review received stock that has not yet been matched to supplier invoices.'),
                'section' => 'purchase_accruals',
            ],
            'reports.accounting-multi-company-summary' => [
                'title' => __('Multi-Company Summary'),
                'description' => __('Review high-level finance totals across all active accounting companies.'),
                'section' => 'multi_company_summary',
            ],
            default => [
                'title' => __('Accounting Report'),
                'description' => __('Review accounting output for the selected company and date.'),
                'section' => 'trial_balance',
            ],
        };
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ $meta['title'] }}</h1>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ $meta['description'] }}</p>
        </div>
        <div class="flex gap-2">
            <flux:button :href="route('reports.index', ['category' => 'accounting'])" wire:navigate variant="ghost">{{ __('Back to Reports') }}</flux:button>
            <flux:button :href="route('accounting.dashboard')" wire:navigate variant="ghost">{{ __('Accounting') }}</flux:button>
            <flux:button :href="route('reports.accounting.export.csv', ['report' => str_replace('reports.accounting-', '', request()->route()?->getName() ?? 'trial-balance'), 'company_id' => $company_id, 'date_from' => $date_from, 'date_to' => $date_to, 'supplier_id' => $supplier_id, 'branch_id' => $branch_id, 'department_id' => $department_id, 'job_id' => $job_id])" variant="ghost">{{ __('Export CSV') }}</flux:button>
        </div>
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <div>
            <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Company') }}</label>
            <select wire:model.live="company_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                @foreach ($companies as $company)
                    <option value="{{ $company->id }}">{{ $company->name }}</option>
                @endforeach
            </select>
        </div>
        @if(in_array($meta['section'], ['vendor_ledger', 'expense_analysis'], true))
            <flux:input wire:model.live="date_from" type="date" :label="__('Date From')" />
        @endif
        <flux:input wire:model.live="date_to" type="date" :label="__('As Of')" />
        @if(in_array($meta['section'], ['ap_aging', 'vendor_ledger', 'expense_analysis', 'purchase_accruals'], true))
            <div>
                <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Supplier') }}</label>
                <select wire:model.live="supplier_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="">{{ __('All') }}</option>
                    @foreach ($suppliers as $supplier)
                        <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        @if(in_array($meta['section'], ['ap_aging', 'vendor_ledger', 'expense_analysis'], true))
            <div>
                <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Branch') }}</label>
                <select wire:model.live="branch_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="">{{ __('All') }}</option>
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Department') }}</label>
                <select wire:model.live="department_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="">{{ __('All') }}</option>
                    @foreach ($departments as $department)
                        <option value="{{ $department->id }}">{{ $department->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Job') }}</label>
                <select wire:model.live="job_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="">{{ __('All') }}</option>
                    @foreach ($jobs as $job)
                        <option value="{{ $job->id }}">{{ $job->code }} · {{ $job->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif
    </div>

    @if ($meta['section'] === 'ap_aging')
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="mb-3 text-sm text-neutral-600 dark:text-neutral-300">
                {{ __('Outstanding total') }}: {{ number_format((float) ($report['ap_aging']['totals']['total'] ?? 0), 2) }}
            </div>
            <div class="app-table-shell">
                <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                    <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Supplier') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Docs') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Current') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('1-30') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('31-60') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('61-90') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Over 90') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Total') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        @forelse ($report['ap_aging']['rows'] ?? [] as $row)
                            <tr>
                                <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">
                                    <a href="{{ route('payables.index', ['tab' => 'aging', 'supplier_id' => $row['supplier_id']]) }}" class="hover:underline">{{ $row['supplier_name'] }}</a>
                                </td>
                                <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ $row['document_count'] }}</td>
                                <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $row['current'], 2) }}</td>
                                <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $row['1_30'], 2) }}</td>
                                <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $row['31_60'], 2) }}</td>
                                <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $row['61_90'], 2) }}</td>
                                <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $row['over_90'], 2) }}</td>
                                <td class="px-3 py-2 text-right text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ number_format((float) $row['total'], 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-3 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No AP aging rows found.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @elseif ($meta['section'] === 'trial_balance')
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Trial Balance') }}</h2>
                <div class="text-xs text-neutral-500">
                    {{ __('Total Debits') }}: {{ number_format((float) ($report['trial_balance']['totals']['debit_total'] ?? 0), 2) }}
                    ·
                    {{ __('Total Credits') }}: {{ number_format((float) ($report['trial_balance']['totals']['credit_total'] ?? 0), 2) }}
                </div>
            </div>
            <div class="app-table-shell">
                <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                    <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Account') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Debit') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Credit') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        @forelse ($report['trial_balance']['entries'] ?? [] as $entry)
                            <tr>
                                <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $entry['code'] }} · {{ $entry['name'] }}</td>
                                <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $entry['debit_balance'], 2) }}</td>
                                <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $entry['credit_balance'], 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-3 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No trial balance entries found.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @elseif ($meta['section'] === 'profit_and_loss')
        <div class="space-y-4">
            <div class="grid gap-4 md:grid-cols-3">
                <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <p class="text-xs uppercase tracking-wide text-neutral-500">{{ __('Revenue') }}</p>
                    <p class="mt-2 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format((float) ($report['profit_and_loss']['revenue_total'] ?? 0), 2) }}</p>
                </div>
                <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <p class="text-xs uppercase tracking-wide text-neutral-500">{{ __('Expenses') }}</p>
                    <p class="mt-2 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format((float) ($report['profit_and_loss']['expense_total'] ?? 0), 2) }}</p>
                </div>
                <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <p class="text-xs uppercase tracking-wide text-neutral-500">{{ __('Net Income') }}</p>
                    <p class="mt-2 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format((float) ($report['profit_and_loss']['net_income'] ?? 0), 2) }}</p>
                </div>
            </div>
            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="app-table-shell">
                    <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                        <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Account') }}</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Type') }}</th>
                                <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Amount') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                            @forelse ($report['profit_and_loss']['rows'] ?? [] as $row)
                                <tr>
                                    <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $row['code'] }} · {{ $row['name'] }}</td>
                                    <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ ucfirst($row['type']) }}</td>
                                    <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $row['amount'], 2) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="px-3 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No profit and loss entries found.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @elseif ($meta['section'] === 'balance_sheet')
        <div class="space-y-4">
            <div class="grid gap-4 md:grid-cols-3">
                <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <p class="text-xs uppercase tracking-wide text-neutral-500">{{ __('Assets') }}</p>
                    <p class="mt-2 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format((float) ($report['balance_sheet']['asset_total'] ?? 0), 2) }}</p>
                </div>
                <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <p class="text-xs uppercase tracking-wide text-neutral-500">{{ __('Liabilities') }}</p>
                    <p class="mt-2 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format((float) ($report['balance_sheet']['liability_total'] ?? 0), 2) }}</p>
                </div>
                <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <p class="text-xs uppercase tracking-wide text-neutral-500">{{ __('Equity') }}</p>
                    <p class="mt-2 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format((float) ($report['balance_sheet']['equity_total'] ?? 0), 2) }}</p>
                </div>
            </div>
            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="app-table-shell">
                    <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                        <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Account') }}</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Type') }}</th>
                                <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Amount') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                            @forelse ($report['balance_sheet']['rows'] ?? [] as $row)
                                <tr>
                                    <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $row['code'] }} · {{ $row['name'] }}</td>
                                    <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ ucfirst($row['type']) }}</td>
                                    <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $row['amount'], 2) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="px-3 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No balance sheet entries found.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @elseif ($meta['section'] === 'cash_flow')
        <div class="grid gap-4 md:grid-cols-3">
            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <p class="text-xs uppercase tracking-wide text-neutral-500">{{ __('Inflows') }}</p>
                <p class="mt-2 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format((float) ($report['cash_flow']['inflow_total'] ?? 0), 2) }}</p>
            </div>
            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <p class="text-xs uppercase tracking-wide text-neutral-500">{{ __('Outflows') }}</p>
                <p class="mt-2 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format((float) ($report['cash_flow']['outflow_total'] ?? 0), 2) }}</p>
            </div>
            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <p class="text-xs uppercase tracking-wide text-neutral-500">{{ __('Net Cash Flow') }}</p>
                <p class="mt-2 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format((float) ($report['cash_flow']['net_cash_flow'] ?? 0), 2) }}</p>
            </div>
        </div>
    @elseif ($meta['section'] === 'bank_reconciliation')
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="app-table-shell">
                <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                    <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Bank Account') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Statement Date') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Variance') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Exceptions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        @forelse ($report['bank_reconciliation']['runs'] ?? [] as $run)
                            <tr>
                                <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $run['bank_account_name'] ?? '—' }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $run['statement_date'] }}</td>
                                <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $run['variance_amount'], 2) }}</td>
                                <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ $run['exception_count'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-3 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No bank reconciliation runs found.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @elseif ($meta['section'] === 'budget_variance')
        <div class="space-y-4">
            @if (! empty($report['budget_variance']))
                <div class="grid gap-4 md:grid-cols-3">
                    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <p class="text-xs uppercase tracking-wide text-neutral-500">{{ __('Budget Total') }}</p>
                        <p class="mt-2 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format((float) ($report['budget_variance']['summary']['budget_total'] ?? 0), 2) }}</p>
                    </div>
                    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <p class="text-xs uppercase tracking-wide text-neutral-500">{{ __('Actual Total') }}</p>
                        <p class="mt-2 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format((float) ($report['budget_variance']['summary']['actual_total'] ?? 0), 2) }}</p>
                    </div>
                    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <p class="text-xs uppercase tracking-wide text-neutral-500">{{ __('Variance') }}</p>
                        <p class="mt-2 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format((float) ($report['budget_variance']['summary']['variance_total'] ?? 0), 2) }}</p>
                    </div>
                </div>
                <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="app-table-shell">
                        <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                                <tr>
                                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Period') }}</th>
                                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Budget') }}</th>
                                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Actual') }}</th>
                                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Variance') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                                @foreach ($report['budget_variance']['period_totals'] ?? [] as $row)
                                    <tr>
                                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $row['period_number'] }}</td>
                                        <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $row['budget_amount'], 2) }}</td>
                                        <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $row['actual_amount'], 2) }}</td>
                                        <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $row['variance_amount'], 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @else
                <div class="rounded-lg border border-dashed border-neutral-300 bg-white p-6 text-sm text-neutral-600 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                    {{ __('No active budget variance is available for the selected company.') }}
                </div>
            @endif
        </div>
    @elseif ($meta['section'] === 'job_profitability')
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="app-table-shell">
                <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                    <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Job') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Status') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Cost') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Revenue') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Margin') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        @forelse ($report['job_profitability'] ?? [] as $row)
                            <tr>
                                <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">
                                    <a href="{{ route('accounting.jobs', ['job' => $row['job_id'], 'tab' => 'transactions']) }}" class="hover:underline">{{ $row['job_code'] }} · {{ $row['job_name'] }}</a>
                                </td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ ucfirst(str_replace('_', ' ', $row['status'])) }}</td>
                                <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $row['actual_cost'], 2) }}</td>
                                <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $row['actual_revenue'], 2) }}</td>
                                <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $row['actual_margin'], 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-3 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No job profitability data available.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @elseif ($meta['section'] === 'vendor_ledger')
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="app-table-shell">
                <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                    <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Date') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Supplier') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Reference') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Description') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Debit') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Credit') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        @forelse ($report['vendor_ledger']['rows'] ?? [] as $row)
                            <tr>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $row['date'] ?? '—' }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $row['supplier_name'] ?? '—' }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                                    @if(($row['source_type'] ?? null) === 'invoice')
                                        <a href="{{ route('payables.invoices.show', $row['source_id']) }}" class="hover:underline">{{ $row['reference'] ?? '—' }}</a>
                                    @elseif(($row['source_type'] ?? null) === 'payment')
                                        <a href="{{ route('payables.payments.show', $row['source_id']) }}" class="hover:underline">{{ $row['reference'] ?? '—' }}</a>
                                    @else
                                        {{ $row['reference'] ?? '—' }}
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $row['description'] ?? '—' }}</td>
                                <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) ($row['debit'] ?? 0), 2) }}</td>
                                <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) ($row['credit'] ?? 0), 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-3 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No vendor ledger rows found.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @elseif ($meta['section'] === 'expense_analysis')
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="app-table-shell">
                <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                    <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Supplier') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Branch') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Department') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Job') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Documents') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Amount') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        @forelse ($report['expense_analysis']['rows'] ?? [] as $row)
                            <tr>
                                <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $row['supplier_name'] ?? $row['supplier'] ?? '—' }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $row['branch_name'] ?? '—' }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $row['department_name'] ?? '—' }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $row['job_name'] ?? '—' }}</td>
                                <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">
                                    <a href="{{ route('payables.index', ['document_type' => 'expense', 'supplier_id' => $row['supplier_id'], 'branch_id' => $row['branch_id'], 'department_id' => $row['department_id'], 'job_id' => $row['job_id']]) }}" class="hover:underline">{{ $row['count'] ?? 0 }}</a>
                                </td>
                                <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) ($row['amount'] ?? 0), 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-3 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No expense analysis rows found.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @elseif ($meta['section'] === 'inventory_valuation')
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="mb-3 text-sm text-neutral-600 dark:text-neutral-300">{{ __('Total valuation') }}: {{ number_format((float) ($report['inventory_valuation']['total'] ?? 0), 2) }}</div>
            <div class="app-table-shell">
                <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                    <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Item') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Branch') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Quantity') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Unit Cost') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Valuation') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        @forelse ($report['inventory_valuation']['rows'] ?? [] as $row)
                            <tr>
                                <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $row['item_name'] }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $row['branch_name'] ?? '—' }}</td>
                                <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $row['quantity'], 3) }}</td>
                                <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $row['unit_cost'], 4) }}</td>
                                <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $row['valuation_amount'], 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-3 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No inventory valuation rows found.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @elseif ($meta['section'] === 'purchase_accruals')
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="mb-3 text-sm text-neutral-600 dark:text-neutral-300">{{ __('Total accrual') }}: {{ number_format((float) ($report['purchase_accruals']['total'] ?? 0), 2) }}</div>
            <div class="app-table-shell">
                <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                    <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('PO') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Supplier') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Item') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Remaining Qty') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Accrual Value') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        @forelse ($report['purchase_accruals']['rows'] ?? [] as $row)
                            <tr>
                                <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">
                                    <a href="{{ route('purchase-orders.show', $row['purchase_order_id']) }}" class="hover:underline">{{ $row['po_number'] }}</a>
                                </td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $row['supplier_name'] }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $row['item_name'] }}</td>
                                <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $row['remaining_quantity'], 3) }}</td>
                                <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $row['accrual_value'], 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-3 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No purchase accrual rows found.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @elseif ($meta['section'] === 'multi_company_summary')
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="app-table-shell">
                <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                    <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Company') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Revenue') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Expenses') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Net Income') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Assets') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Liabilities') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Equity') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        @forelse ($report['multi_company_summary']['rows'] ?? [] as $row)
                            <tr>
                                <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $row['company_name'] }}</td>
                                <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $row['revenue_total'], 2) }}</td>
                                <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $row['expense_total'], 2) }}</td>
                                <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $row['net_income'], 2) }}</td>
                                <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $row['asset_total'], 2) }}</td>
                                <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $row['liability_total'], 2) }}</td>
                                <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $row['equity_total'], 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-3 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No company summary rows found.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
