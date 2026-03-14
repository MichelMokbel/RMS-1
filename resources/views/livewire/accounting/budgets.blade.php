<?php

use App\Models\AccountingCompany;
use App\Models\Branch;
use App\Models\BudgetVersion;
use App\Models\Department;
use App\Models\FiscalYear;
use App\Models\Job;
use App\Models\LedgerAccount;
use App\Services\Accounting\BudgetService;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public ?int $company_id = null;
    public ?int $fiscal_year_id = null;
    public string $name = '';
    public string $status = 'draft';
    public bool $is_active = false;
    public ?int $account_id = null;
    public ?int $department_id = null;
    public ?int $job_id = null;
    public ?int $branch_id = null;
    public string $annual_amount = '0.00';

    public function mount(): void
    {
        $this->company_id = AccountingCompany::query()->where('is_default', true)->value('id');
        $this->fiscal_year_id = FiscalYear::query()->where('company_id', $this->company_id)->where('status', 'open')->value('id');
        $this->account_id = LedgerAccount::query()->where('type', 'expense')->orderBy('code')->value('id');
        $this->name = __('Operating Budget').' '.now()->format('Y');
    }

    public function saveBudget(BudgetService $service): void
    {
        $data = $this->validate([
            'company_id' => ['nullable', 'integer', 'exists:accounting_companies,id'],
            'fiscal_year_id' => ['required', 'integer', 'exists:fiscal_years,id'],
            'name' => ['required', 'string', 'max:100'],
            'status' => ['required', 'in:draft,active,archived'],
            'is_active' => ['boolean'],
            'account_id' => ['required', 'integer', 'exists:ledger_accounts,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'job_id' => ['nullable', 'integer', 'exists:accounting_jobs,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'annual_amount' => ['required', 'numeric', 'min:0'],
        ]);

        $service->createVersion([
            'company_id' => $data['company_id'],
            'fiscal_year_id' => $data['fiscal_year_id'],
            'name' => $data['name'],
            'status' => $data['status'],
            'is_active' => $data['is_active'],
            'lines' => [[
                'account_id' => $data['account_id'],
                'department_id' => $data['department_id'],
                'job_id' => $data['job_id'],
                'branch_id' => $data['branch_id'],
                'annual_amount' => $data['annual_amount'],
            ]],
        ], (int) auth()->id());

        session()->flash('status', __('Budget version created.'));
    }

    public function with(BudgetService $service): array
    {
        $budgets = Schema::hasTable('budget_versions')
            ? BudgetVersion::query()->with(['company', 'fiscalYear'])->withCount('lines')->latest('created_at')->limit(100)->get()
            : collect();

        $activeBudget = $budgets->firstWhere('is_active', true) ?: $budgets->first();

        return [
            'companies' => AccountingCompany::query()->orderBy('name')->get(),
            'fiscalYears' => FiscalYear::query()->when($this->company_id, fn ($query) => $query->where('company_id', $this->company_id))->orderByDesc('start_date')->get(),
            'accounts' => LedgerAccount::query()->where('allow_direct_posting', true)->orderBy('code')->get(),
            'departments' => Department::query()->when($this->company_id, fn ($query) => $query->where('company_id', $this->company_id))->orderBy('name')->get(),
            'branches' => Branch::query()->orderBy('name')->get(),
            'jobs' => Job::query()->when($this->company_id, fn ($query) => $query->where('company_id', $this->company_id))->orderBy('code')->get(),
            'budgets' => $budgets,
            'activeVariance' => $activeBudget ? $service->variance($activeBudget->load('fiscalYear')) : null,
        ];
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Budgets') }}</h1>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Create annual budget versions and compare them to posted actuals by period.') }}</p>
        </div>
        <flux:button :href="route('accounting.reports')" wire:navigate variant="ghost">{{ __('Open Reports') }}</flux:button>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid gap-6 xl:grid-cols-[360px,1fr]">
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <h2 class="mb-3 text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Create Budget Version') }}</h2>
            <form wire:submit="saveBudget" class="space-y-4">
                <div>
                    <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Company') }}</label>
                    <select wire:model.live="company_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        @foreach ($companies as $company)
                            <option value="{{ $company->id }}">{{ $company->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Fiscal Year') }}</label>
                    <select wire:model="fiscal_year_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        @foreach ($fiscalYears as $year)
                            <option value="{{ $year->id }}">{{ $year->name }}</option>
                        @endforeach
                    </select>
                </div>
                <flux:input wire:model="name" :label="__('Version Name')" />
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Status') }}</label>
                        <select wire:model="status" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                            <option value="draft">{{ __('Draft') }}</option>
                            <option value="active">{{ __('Active') }}</option>
                            <option value="archived">{{ __('Archived') }}</option>
                        </select>
                    </div>
                    <label class="mt-7 inline-flex items-center gap-2 text-sm text-neutral-700 dark:text-neutral-200">
                        <input type="checkbox" wire:model="is_active" class="rounded border-neutral-300 text-primary-600">
                        {{ __('Make active version') }}
                    </label>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Account') }}</label>
                    <select wire:model="account_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</option>
                        @endforeach
                    </select>
                </div>
                <flux:input wire:model="annual_amount" type="number" step="0.01" :label="__('Annual Amount')" />
                <div class="grid gap-4 md:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Department') }}</label>
                        <select wire:model="department_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                            <option value="">{{ __('Any') }}</option>
                            @foreach ($departments as $department)
                                <option value="{{ $department->id }}">{{ $department->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Branch') }}</label>
                        <select wire:model="branch_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                            <option value="">{{ __('Any') }}</option>
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Job') }}</label>
                        <select wire:model="job_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                            <option value="">{{ __('Any') }}</option>
                            @foreach ($jobs as $job)
                                <option value="{{ $job->id }}">{{ $job->code }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="flex justify-end">
                    <flux:button type="submit">{{ __('Create Budget') }}</flux:button>
                </div>
            </form>
        </div>

        <div class="space-y-6">
            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <h2 class="mb-3 text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Budget Versions') }}</h2>
                <div class="app-table-shell">
                    <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                        <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Name') }}</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Year') }}</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Status') }}</th>
                                <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Lines') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                            @forelse($budgets as $budget)
                                <tr>
                                    <td class="px-3 py-2 text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ $budget->name }}</td>
                                    <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $budget->fiscalYear?->name ?? '—' }}</td>
                                    <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $budget->is_active ? __('Active') : $budget->status }}</td>
                                    <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ $budget->lines_count }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-3 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No budgets found.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            @if ($activeVariance)
                <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="mb-3 flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Active Budget Variance') }}</h2>
                        <span class="text-xs text-neutral-500">{{ __('Budget') }} #{{ $activeVariance['budget_version_id'] }}</span>
                    </div>
                    <div class="grid gap-4 md:grid-cols-3">
                        <div class="rounded-md border border-neutral-200 p-3 dark:border-neutral-700">
                            <p class="text-xs uppercase tracking-wide text-neutral-500">{{ __('Budget Total') }}</p>
                            <p class="mt-2 text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format((float) $activeVariance['summary']['budget_total'], 2) }}</p>
                        </div>
                        <div class="rounded-md border border-neutral-200 p-3 dark:border-neutral-700">
                            <p class="text-xs uppercase tracking-wide text-neutral-500">{{ __('Actual Total') }}</p>
                            <p class="mt-2 text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format((float) $activeVariance['summary']['actual_total'], 2) }}</p>
                        </div>
                        <div class="rounded-md border border-neutral-200 p-3 dark:border-neutral-700">
                            <p class="text-xs uppercase tracking-wide text-neutral-500">{{ __('Variance') }}</p>
                            <p class="mt-2 text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format((float) $activeVariance['summary']['variance_total'], 2) }}</p>
                        </div>
                    </div>
                    <div class="mt-4 app-table-shell">
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
                                @foreach ($activeVariance['period_totals'] as $row)
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
            @endif
        </div>
    </div>
</div>
