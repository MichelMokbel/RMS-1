<?php

use App\Models\AccountingCompany;
use App\Models\Branch;
use App\Models\BudgetVersion;
use App\Models\Department;
use App\Models\FiscalYear;
use App\Models\Job;
use App\Models\LedgerAccount;
use App\Services\Accounting\BudgetService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.app')] class extends Component {
    use WithFileUploads;

    public ?int $selected_budget_id = null;
    public ?int $company_id = null;
    public ?int $fiscal_year_id = null;
    public string $name = '';
    public string $status = 'draft';
    public bool $is_active = false;
    public array $budget_lines = [];
    public $import_file = null;

    public function mount(): void
    {
        $this->company_id = AccountingCompany::query()->where('is_default', true)->value('id');
        $this->fiscal_year_id = FiscalYear::query()
            ->where('company_id', $this->company_id)
            ->orderByDesc('start_date')
            ->value('id');
        $this->name = __('Operating Budget').' '.now()->format('Y');
        $this->resetBudgetLines();

        $existing = BudgetVersion::query()
            ->when($this->company_id, fn ($query) => $query->where('company_id', $this->company_id))
            ->latest('created_at')
            ->first();

        if ($existing) {
            $this->loadBudget($existing->id);
        }
    }

    public function updatedCompanyId(): void
    {
        $this->fiscal_year_id = FiscalYear::query()
            ->where('company_id', $this->company_id)
            ->orderByDesc('start_date')
            ->value('id');

        $this->selected_budget_id = null;
        $this->name = __('Operating Budget').' '.now()->format('Y');
        $this->status = 'draft';
        $this->is_active = false;
        $this->resetBudgetLines();
    }

    public function resetBudgetLines(): void
    {
        $this->budget_lines = [[
            'account_id' => LedgerAccount::query()->where('type', 'expense')->orderBy('code')->value('id'),
            'department_id' => null,
            'branch_id' => null,
            'job_id' => null,
            'period_amounts' => array_fill(0, 12, 0),
        ]];
    }

    public function addBudgetLine(): void
    {
        $this->budget_lines[] = [
            'account_id' => LedgerAccount::query()->where('type', 'expense')->orderBy('code')->value('id'),
            'department_id' => null,
            'branch_id' => null,
            'job_id' => null,
            'period_amounts' => array_fill(0, 12, 0),
        ];
    }

    public function removeBudgetLine(int $index): void
    {
        unset($this->budget_lines[$index]);
        $this->budget_lines = array_values($this->budget_lines);

        if ($this->budget_lines === []) {
            $this->resetBudgetLines();
        }
    }

    public function loadBudget(int $budgetId): void
    {
        $budget = BudgetVersion::query()
            ->with(['fiscalYear.periods', 'lines.account'])
            ->findOrFail($budgetId);

        $this->selected_budget_id = $budget->id;
        $this->company_id = $budget->company_id;
        $this->fiscal_year_id = $budget->fiscal_year_id;
        $this->name = $budget->name;
        $this->status = $budget->status;
        $this->is_active = (bool) $budget->is_active;
        $this->budget_lines = $this->groupBudgetLinesForEditor($budget);
    }

    public function newBudget(): void
    {
        $this->selected_budget_id = null;
        $this->status = 'draft';
        $this->is_active = false;
        $this->name = __('Operating Budget').' '.now()->format('Y');
        $this->resetBudgetLines();
    }

    public function saveBudget(BudgetService $service): void
    {
        $rules = [
            'company_id' => ['nullable', 'integer', 'exists:accounting_companies,id'],
            'fiscal_year_id' => ['required', 'integer', 'exists:fiscal_years,id'],
            'name' => ['required', 'string', 'max:100'],
            'status' => ['required', 'in:draft,active,archived,locked'],
            'is_active' => ['boolean'],
            'budget_lines' => ['required', 'array', 'min:1'],
            'budget_lines.*.account_id' => ['required', 'integer', 'exists:ledger_accounts,id'],
            'budget_lines.*.department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'budget_lines.*.branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'budget_lines.*.job_id' => ['nullable', 'integer', 'exists:accounting_jobs,id'],
            'budget_lines.*.period_amounts' => ['required', 'array', 'min:1'],
            'budget_lines.*.period_amounts.*' => ['nullable', 'numeric'],
        ];

        $data = $this->validate($rules);

        $version = $this->selected_budget_id
            ? BudgetVersion::query()->findOrFail($this->selected_budget_id)
            : null;

        $budget = $service->saveVersion([
            'company_id' => $data['company_id'],
            'fiscal_year_id' => $data['fiscal_year_id'],
            'name' => $data['name'],
            'status' => $data['status'],
            'is_active' => $data['is_active'],
            'lines' => collect($data['budget_lines'])->map(fn (array $line) => [
                'account_id' => $line['account_id'],
                'department_id' => $line['department_id'] ?: null,
                'branch_id' => $line['branch_id'] ?: null,
                'job_id' => $line['job_id'] ?: null,
                'period_amounts' => array_map(fn ($amount) => round((float) $amount, 2), $line['period_amounts']),
            ])->all(),
        ], (int) auth()->id(), $version);

        $this->loadBudget($budget->id);
        session()->flash('status', __('Budget version saved.'));
    }

    public function activateBudget(int $budgetId, BudgetService $service): void
    {
        $service->activate(BudgetVersion::query()->findOrFail($budgetId), (int) auth()->id());
        $this->loadBudget($budgetId);
        session()->flash('status', __('Budget version activated.'));
    }

    public function archiveBudget(int $budgetId, BudgetService $service): void
    {
        $service->archive(BudgetVersion::query()->findOrFail($budgetId), (int) auth()->id());
        $this->loadBudget($budgetId);
        session()->flash('status', __('Budget version archived.'));
    }

    public function lockBudget(int $budgetId, BudgetService $service): void
    {
        $service->lock(BudgetVersion::query()->findOrFail($budgetId), (int) auth()->id());
        $this->loadBudget($budgetId);
        session()->flash('status', __('Budget version locked.'));
    }

    public function exportSelectedBudget(BudgetService $service)
    {
        abort_unless($this->selected_budget_id, 404);

        $version = BudgetVersion::query()->findOrFail($this->selected_budget_id);
        $rows = $service->exportRows($version);
        $filename = 'budget-'.$version->id.'-'.now()->format('YmdHis').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['account_id', 'account_code', 'account_name', 'department_id', 'branch_id', 'job_id', 'period_number', 'amount']);

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row['account_id'],
                    $row['account_code'],
                    $row['account_name'],
                    $row['department_id'],
                    $row['branch_id'],
                    $row['job_id'],
                    $row['period_number'],
                    $row['amount'],
                ]);
            }

            fclose($handle);
        }, $filename);
    }

    public function importBudget(BudgetService $service): void
    {
        $this->validate([
            'selected_budget_id' => ['required', 'integer', 'exists:budget_versions,id'],
            'import_file' => ['required', 'file', 'mimes:csv,txt'],
        ]);

        $budget = BudgetVersion::query()->findOrFail($this->selected_budget_id);
        $service->importCsv($budget, $this->import_file, (int) auth()->id());
        $this->import_file = null;
        $this->loadBudget($budget->id);
        session()->flash('status', __('Budget lines imported.'));
    }

    private function groupBudgetLinesForEditor(BudgetVersion $budget): array
    {
        $budget->loadMissing(['lines']);
        $grouped = $budget->lines
            ->groupBy(fn ($line) => implode(':', [
                $line->account_id,
                $line->department_id ?: 0,
                $line->branch_id ?: 0,
                $line->job_id ?: 0,
            ]));

        return $grouped->map(function (Collection $rows) {
            $first = $rows->first();
            $periodAmounts = array_fill(0, 12, 0);

            foreach ($rows as $row) {
                $index = max(((int) $row->period_number) - 1, 0);
                $periodAmounts[$index] = round((float) $row->amount, 2);
            }

            return [
                'account_id' => $first->account_id,
                'department_id' => $first->department_id,
                'branch_id' => $first->branch_id,
                'job_id' => $first->job_id,
                'period_amounts' => $periodAmounts,
            ];
        })->values()->all();
    }

    public function with(BudgetService $service): array
    {
        $budgets = Schema::hasTable('budget_versions')
            ? BudgetVersion::query()
                ->with(['company', 'fiscalYear'])
                ->withCount('lines')
                ->when($this->company_id, fn ($query) => $query->where('company_id', $this->company_id))
                ->latest('created_at')
                ->get()
            : collect();

        $selectedBudget = $this->selected_budget_id
            ? BudgetVersion::query()->with(['company', 'fiscalYear', 'lines.account', 'lines.department', 'lines.branch', 'lines.job'])->find($this->selected_budget_id)
            : null;

        return [
            'companies' => AccountingCompany::query()->orderBy('name')->get(),
            'fiscalYears' => FiscalYear::query()
                ->when($this->company_id, fn ($query) => $query->where('company_id', $this->company_id))
                ->orderByDesc('start_date')
                ->get(),
            'periods' => $this->fiscal_year_id
                ? \App\Models\AccountingPeriod::query()->where('fiscal_year_id', $this->fiscal_year_id)->orderBy('period_number')->get()
                : collect(range(1, 12))->map(fn ($number) => (object) ['period_number' => $number]),
            'accounts' => LedgerAccount::query()->where('allow_direct_posting', true)->orderBy('code')->get(),
            'departments' => Department::query()->when($this->company_id, fn ($query) => $query->where('company_id', $this->company_id))->orderBy('name')->get(),
            'branches' => Branch::query()->orderBy('name')->get(),
            'jobs' => Job::query()->when($this->company_id, fn ($query) => $query->where('company_id', $this->company_id))->orderBy('code')->get(),
            'budgets' => $budgets,
            'selectedBudget' => $selectedBudget,
            'selectedVariance' => $selectedBudget ? $service->variance($selectedBudget->load('fiscalYear')) : null,
        ];
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Budgets') }}</h1>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Maintain budget versions, edit monthly worksheet amounts, and compare them to posted actuals.') }}</p>
        </div>
        <flux:button :href="route('reports.accounting-budget-variance')" wire:navigate variant="ghost">{{ __('Open Reports') }}</flux:button>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid gap-6 xl:grid-cols-[380px,1fr]">
        <div class="space-y-6">
            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ $selected_budget_id ? __('Edit Budget Version') : __('New Budget Version') }}</h2>
                    <flux:button type="button" wire:click="newBudget" size="sm" variant="ghost">{{ __('New') }}</flux:button>
                </div>
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
                        <select wire:model.live="fiscal_year_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
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
                                <option value="locked">{{ __('Locked') }}</option>
                            </select>
                        </div>
                        <label class="mt-7 inline-flex items-center gap-2 text-sm text-neutral-700 dark:text-neutral-200">
                            <input type="checkbox" wire:model="is_active" class="rounded border-neutral-300 text-primary-600">
                            {{ __('Make active version') }}
                        </label>
                    </div>
                    <div class="flex justify-end">
                        <flux:button type="submit">{{ $selected_budget_id ? __('Save Budget') : __('Create Budget') }}</flux:button>
                    </div>
                </form>
            </div>

            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Versions') }}</h2>
                    @if ($selected_budget_id)
                        <flux:button type="button" wire:click="exportSelectedBudget" size="sm" variant="ghost">{{ __('Export CSV') }}</flux:button>
                    @endif
                </div>
                <div class="space-y-3">
                    @foreach ($budgets as $budget)
                        <button type="button" wire:click="loadBudget({{ $budget->id }})" class="w-full rounded-lg border px-3 py-3 text-left {{ $selected_budget_id === $budget->id ? 'border-primary-400 bg-primary-50 dark:border-primary-700 dark:bg-primary-950/30' : 'border-neutral-200 bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-800/70' }}">
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="font-semibold text-neutral-900 dark:text-neutral-100">{{ $budget->name }}</div>
                                    <div class="text-xs text-neutral-500">{{ $budget->fiscalYear?->name ?? '—' }} · {{ $budget->lines_count }} {{ __('lines') }}</div>
                                </div>
                                <span class="text-xs font-medium text-neutral-600 dark:text-neutral-300">{{ $budget->is_active ? __('Active') : Str::headline($budget->status) }}</span>
                            </div>
                        </button>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="space-y-6">
            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Budget Worksheet') }}</h2>
                    <div class="flex gap-2">
                        <flux:button type="button" wire:click="addBudgetLine" size="sm" variant="ghost">{{ __('Add Line') }}</flux:button>
                        @if ($selected_budget_id)
                            <flux:button type="button" wire:click="activateBudget({{ $selected_budget_id }})" size="sm" variant="ghost">{{ __('Activate') }}</flux:button>
                            <flux:button type="button" wire:click="archiveBudget({{ $selected_budget_id }})" size="sm" variant="ghost">{{ __('Archive') }}</flux:button>
                            <flux:button type="button" wire:click="lockBudget({{ $selected_budget_id }})" size="sm" variant="ghost">{{ __('Lock') }}</flux:button>
                        @endif
                    </div>
                </div>

                <div class="space-y-4">
                    @foreach ($budget_lines as $index => $line)
                        <div class="rounded-lg border border-neutral-200 p-4 dark:border-neutral-700">
                            <div class="grid gap-4 xl:grid-cols-4">
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Account') }}</label>
                                    <select wire:model="budget_lines.{{ $index }}.account_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                        @foreach ($accounts as $account)
                                            <option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Department') }}</label>
                                    <select wire:model="budget_lines.{{ $index }}.department_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                        <option value="">{{ __('Any') }}</option>
                                        @foreach ($departments as $department)
                                            <option value="{{ $department->id }}">{{ $department->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Branch') }}</label>
                                    <select wire:model="budget_lines.{{ $index }}.branch_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                        <option value="">{{ __('Any') }}</option>
                                        @foreach ($branches as $branch)
                                            <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Job') }}</label>
                                    <select wire:model="budget_lines.{{ $index }}.job_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                        <option value="">{{ __('Any') }}</option>
                                        @foreach ($jobs as $job)
                                            <option value="{{ $job->id }}">{{ $job->code }} · {{ $job->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="mt-4 grid gap-3 md:grid-cols-3 xl:grid-cols-6">
                                @foreach ($periods as $periodIndex => $period)
                                    <flux:input
                                        wire:model="budget_lines.{{ $index }}.period_amounts.{{ $periodIndex }}"
                                        type="number"
                                        step="0.01"
                                        :label="__('P:period', ['period' => $period->period_number])"
                                    />
                                @endforeach
                            </div>
                            <div class="mt-3 flex justify-end">
                                <flux:button type="button" wire:click="removeBudgetLine({{ $index }})" size="sm" variant="ghost">{{ __('Remove Line') }}</flux:button>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            @if ($selected_budget_id)
                <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="mb-3 flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Import Budget Lines') }}</h2>
                        <span class="text-xs text-neutral-500">{{ __('CSV columns: account_id, department_id, branch_id, job_id, period_number, amount') }}</span>
                    </div>
                    <form wire:submit="importBudget" class="flex flex-col gap-3 md:flex-row md:items-end">
                        <div class="flex-1">
                            <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('CSV File') }}</label>
                            <input wire:model="import_file" type="file" accept=".csv,.txt" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" />
                        </div>
                        <flux:button type="submit">{{ __('Import CSV') }}</flux:button>
                    </form>
                </div>
            @endif

            @if ($selectedBudget && $selectedVariance)
                <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="mb-3 flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Selected Version Variance') }}</h2>
                        <span class="text-xs text-neutral-500">{{ $selectedBudget->name }}</span>
                    </div>
                    <div class="grid gap-4 md:grid-cols-3">
                        <div class="rounded-md border border-neutral-200 p-3 dark:border-neutral-700">
                            <p class="text-xs uppercase tracking-wide text-neutral-500">{{ __('Budget Total') }}</p>
                            <p class="mt-2 text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format((float) $selectedVariance['summary']['budget_total'], 2) }}</p>
                        </div>
                        <div class="rounded-md border border-neutral-200 p-3 dark:border-neutral-700">
                            <p class="text-xs uppercase tracking-wide text-neutral-500">{{ __('Actual Total') }}</p>
                            <p class="mt-2 text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format((float) $selectedVariance['summary']['actual_total'], 2) }}</p>
                        </div>
                        <div class="rounded-md border border-neutral-200 p-3 dark:border-neutral-700">
                            <p class="text-xs uppercase tracking-wide text-neutral-500">{{ __('Variance') }}</p>
                            <p class="mt-2 text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format((float) $selectedVariance['summary']['variance_total'], 2) }}</p>
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
                                @foreach ($selectedVariance['period_totals'] as $row)
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
