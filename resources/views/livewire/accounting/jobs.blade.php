<?php

use App\Models\AccountingCompany;
use App\Models\Branch;
use App\Models\Job;
use App\Models\JobBudget;
use App\Models\JobCostCode;
use App\Models\JobPhase;
use App\Models\LedgerAccount;
use App\Services\Accounting\JobCostingService;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public string $workspace_tab = 'jobs';
    public ?int $company_id = null;
    public ?int $branch_id = null;
    public string $name = '';
    public string $code = '';
    public string $status = 'active';
    public ?string $start_date = null;
    public ?string $end_date = null;
    public string $estimated_revenue = '0.00';
    public string $estimated_cost = '0.00';
    public ?string $notes = null;
    public ?int $selected_job_id = null;

    public ?int $editing_phase_id = null;
    public string $phase_name = '';
    public string $phase_code = '';
    public string $phase_status = 'active';

    public ?int $editing_cost_code_id = null;
    public string $cost_code_name = '';
    public string $cost_code_code = '';
    public ?int $cost_code_default_account_id = null;
    public bool $cost_code_is_active = true;

    public ?int $editing_budget_id = null;
    public ?int $budget_phase_id = null;
    public ?int $budget_cost_code_id = null;
    public string $budget_amount = '0.00';

    public ?string $transaction_date = null;
    public string $transaction_amount = '0.00';
    public string $transaction_type = 'cost';
    public ?string $transaction_memo = null;
    public ?int $transaction_phase_id = null;
    public ?int $transaction_cost_code_id = null;

    public function mount(): void
    {
        $this->company_id = AccountingCompany::query()->where('is_default', true)->value('id');
        $this->branch_id = Branch::query()->orderBy('id')->value('id');
        $this->code = 'JOB-'.now()->format('YmdHis');
        $this->transaction_date = now()->toDateString();
        $requestedTab = (string) request()->query('tab', 'jobs');
        if (in_array($requestedTab, ['jobs', 'phases', 'cost_codes', 'budgets', 'transactions'], true)) {
            $this->workspace_tab = $requestedTab;
        }

        $this->selected_job_id = request()->integer('job') ?: Job::query()
            ->when($this->company_id, fn ($query) => $query->where('company_id', $this->company_id))
            ->orderBy('code')
            ->value('id');
    }

    public function updatedCompanyId(): void
    {
        $this->selected_job_id = Job::query()
            ->where('company_id', $this->company_id)
            ->orderBy('code')
            ->value('id');
        $this->branch_id = Branch::query()->orderBy('id')->value('id');
    }

    public function saveJob(JobCostingService $service): void
    {
        $job = $service->createJob($this->validate([
            'company_id' => ['nullable', 'integer', 'exists:accounting_companies,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'name' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'max:50'],
            'status' => ['required', 'in:active,on_hold,closed'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'estimated_revenue' => ['nullable', 'numeric', 'min:0'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]), (int) auth()->id());

        $this->selected_job_id = $job->id;
        $this->code = 'JOB-'.now()->format('YmdHis');
        $this->name = '';
        $this->estimated_revenue = '0.00';
        $this->estimated_cost = '0.00';
        $this->notes = null;

        session()->flash('status', __('Job created.'));
    }

    public function editPhase(int $phaseId): void
    {
        $phase = JobPhase::query()->findOrFail($phaseId);
        $this->selected_job_id = $phase->job_id;
        $this->editing_phase_id = $phase->id;
        $this->phase_name = $phase->name;
        $this->phase_code = $phase->code;
        $this->phase_status = $phase->status;
        $this->workspace_tab = 'phases';
    }

    public function savePhase(JobCostingService $service): void
    {
        $data = $this->validate([
            'selected_job_id' => ['required', 'integer', 'exists:accounting_jobs,id'],
            'phase_name' => ['required', 'string', 'max:120'],
            'phase_code' => ['required', 'string', 'max:50'],
            'phase_status' => ['required', 'in:active,on_hold,closed'],
        ]);

        $job = Job::query()->findOrFail($data['selected_job_id']);
        $phase = $this->editing_phase_id ? JobPhase::query()->findOrFail($this->editing_phase_id) : null;
        $service->savePhase($job, [
            'name' => $data['phase_name'],
            'code' => $data['phase_code'],
            'status' => $data['phase_status'],
        ], (int) auth()->id(), $phase);

        $this->editing_phase_id = null;
        $this->phase_name = '';
        $this->phase_code = '';
        $this->phase_status = 'active';
        session()->flash('status', __('Job phase saved.'));
    }

    public function closePhase(int $phaseId, JobCostingService $service): void
    {
        $service->closePhase(JobPhase::query()->findOrFail($phaseId), (int) auth()->id());
        session()->flash('status', __('Job phase closed.'));
    }

    public function editCostCode(int $costCodeId): void
    {
        $costCode = JobCostCode::query()->findOrFail($costCodeId);
        $this->editing_cost_code_id = $costCode->id;
        $this->cost_code_name = $costCode->name;
        $this->cost_code_code = $costCode->code;
        $this->cost_code_default_account_id = $costCode->default_account_id;
        $this->cost_code_is_active = (bool) $costCode->is_active;
        $this->workspace_tab = 'cost_codes';
    }

    public function saveCostCode(JobCostingService $service): void
    {
        $data = $this->validate([
            'company_id' => ['nullable', 'integer', 'exists:accounting_companies,id'],
            'cost_code_name' => ['required', 'string', 'max:120'],
            'cost_code_code' => ['required', 'string', 'max:50'],
            'cost_code_default_account_id' => ['nullable', 'integer', 'exists:ledger_accounts,id'],
            'cost_code_is_active' => ['boolean'],
        ]);

        $service->saveCostCode((int) ($data['company_id'] ?: $this->company_id), [
            'name' => $data['cost_code_name'],
            'code' => $data['cost_code_code'],
            'default_account_id' => $data['cost_code_default_account_id'],
            'is_active' => $data['cost_code_is_active'],
        ], (int) auth()->id(), $this->editing_cost_code_id ? JobCostCode::query()->findOrFail($this->editing_cost_code_id) : null);

        $this->editing_cost_code_id = null;
        $this->cost_code_name = '';
        $this->cost_code_code = '';
        $this->cost_code_default_account_id = null;
        $this->cost_code_is_active = true;
        session()->flash('status', __('Job cost code saved.'));
    }

    public function deactivateCostCode(int $costCodeId, JobCostingService $service): void
    {
        $service->deactivateCostCode(JobCostCode::query()->findOrFail($costCodeId), (int) auth()->id());
        session()->flash('status', __('Job cost code deactivated.'));
    }

    public function editBudget(int $budgetId): void
    {
        $budget = JobBudget::query()->findOrFail($budgetId);
        $this->selected_job_id = $budget->job_id;
        $this->editing_budget_id = $budget->id;
        $this->budget_phase_id = $budget->job_phase_id;
        $this->budget_cost_code_id = $budget->job_cost_code_id;
        $this->budget_amount = number_format((float) $budget->budget_amount, 2, '.', '');
        $this->workspace_tab = 'budgets';
    }

    public function saveBudgetEntry(JobCostingService $service): void
    {
        $data = $this->validate([
            'selected_job_id' => ['required', 'integer', 'exists:accounting_jobs,id'],
            'budget_phase_id' => ['nullable', 'integer', 'exists:accounting_job_phases,id'],
            'budget_cost_code_id' => ['nullable', 'integer', 'exists:accounting_job_cost_codes,id'],
            'budget_amount' => ['required', 'numeric', 'min:0'],
        ]);

        $job = Job::query()->findOrFail($data['selected_job_id']);
        $service->saveBudget($job, [
            'job_phase_id' => $data['budget_phase_id'],
            'job_cost_code_id' => $data['budget_cost_code_id'],
            'budget_amount' => $data['budget_amount'],
        ], (int) auth()->id(), $this->editing_budget_id ? JobBudget::query()->findOrFail($this->editing_budget_id) : null);

        $this->editing_budget_id = null;
        $this->budget_phase_id = null;
        $this->budget_cost_code_id = null;
        $this->budget_amount = '0.00';
        session()->flash('status', __('Job budget saved.'));
    }

    public function deleteBudgetEntry(int $budgetId, JobCostingService $service): void
    {
        $service->deleteBudget(JobBudget::query()->findOrFail($budgetId), (int) auth()->id());
        session()->flash('status', __('Job budget deleted.'));
    }

    public function recordTransaction(JobCostingService $service): void
    {
        $data = $this->validate([
            'selected_job_id' => ['required', 'integer', 'exists:accounting_jobs,id'],
            'transaction_date' => ['required', 'date'],
            'transaction_amount' => ['required', 'numeric', 'min:0'],
            'transaction_type' => ['required', 'in:cost,revenue,adjustment'],
            'transaction_memo' => ['nullable', 'string', 'max:255'],
            'transaction_phase_id' => ['nullable', 'integer', 'exists:accounting_job_phases,id'],
            'transaction_cost_code_id' => ['nullable', 'integer', 'exists:accounting_job_cost_codes,id'],
        ]);

        $job = Job::query()->findOrFail($data['selected_job_id']);
        $service->recordTransaction($job->load('phases', 'budgets'), [
            'transaction_date' => $data['transaction_date'],
            'amount' => $data['transaction_amount'],
            'transaction_type' => $data['transaction_type'],
            'memo' => $data['transaction_memo'],
            'job_phase_id' => $data['transaction_phase_id'],
            'job_cost_code_id' => $data['transaction_cost_code_id'],
        ], (int) auth()->id());

        $this->transaction_amount = '0.00';
        $this->transaction_memo = null;
        $this->transaction_phase_id = null;
        $this->transaction_cost_code_id = null;

        session()->flash('status', __('Job transaction recorded.'));
    }

    public function with(JobCostingService $service): array
    {
        $jobs = Job::query()
            ->with(['company', 'branch'])
            ->when($this->company_id, fn ($query) => $query->where('company_id', $this->company_id))
            ->withCount(['phases', 'transactions'])
            ->orderBy('code')
            ->get();

        $selectedJob = $this->selected_job_id
            ? Job::query()->with([
                'company',
                'branch',
                'phases',
                'budgets.phase',
                'budgets.costCode',
                'transactions.phase',
                'transactions.costCode',
            ])->find($this->selected_job_id)
            : null;

        return [
            'companies' => AccountingCompany::query()->orderBy('name')->get(),
            'branches' => Branch::query()->orderBy('name')->get(),
            'jobs' => $jobs,
            'costCodes' => JobCostCode::query()->when($this->company_id, fn ($query) => $query->where('company_id', $this->company_id))->orderBy('code')->get(),
            'accounts' => LedgerAccount::query()->where('allow_direct_posting', true)->orderBy('code')->get(),
            'selectedJob' => $selectedJob,
            'profitability' => $selectedJob ? $service->profitability($selectedJob) : null,
        ];
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Jobs') }}</h1>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Manage jobs, phases, cost codes, budgets, and sourced costing transactions in one workspace.') }}</p>
        </div>
        <flux:button :href="route('reports.accounting-job-profitability')" wire:navigate variant="ghost">{{ __('Open Reports') }}</flux:button>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid gap-6 xl:grid-cols-[360px,1fr]">
        <div class="space-y-6">
            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <h2 class="mb-3 text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Create Job') }}</h2>
                <form wire:submit="saveJob" class="space-y-4">
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Company') }}</label>
                            <select wire:model.live="company_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                @foreach ($companies as $company)
                                    <option value="{{ $company->id }}">{{ $company->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Branch') }}</label>
                            <select wire:model="branch_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                @foreach ($branches as $branch)
                                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <flux:input wire:model="code" :label="__('Job Code')" />
                    <flux:input wire:model="name" :label="__('Job Name')" />
                    <div>
                        <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Status') }}</label>
                        <select wire:model="status" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                            <option value="active">{{ __('Active') }}</option>
                            <option value="on_hold">{{ __('On Hold') }}</option>
                            <option value="closed">{{ __('Closed') }}</option>
                        </select>
                    </div>
                    <div class="grid gap-4 md:grid-cols-2">
                        <flux:input wire:model="start_date" type="date" :label="__('Start Date')" />
                        <flux:input wire:model="end_date" type="date" :label="__('End Date')" />
                    </div>
                    <div class="grid gap-4 md:grid-cols-2">
                        <flux:input wire:model="estimated_revenue" type="number" step="0.01" :label="__('Estimated Revenue')" />
                        <flux:input wire:model="estimated_cost" type="number" step="0.01" :label="__('Estimated Cost')" />
                    </div>
                    <flux:textarea wire:model="notes" :label="__('Notes')" rows="3" />
                    <div class="flex justify-end">
                        <flux:button type="submit">{{ __('Create Job') }}</flux:button>
                    </div>
                </form>
            </div>

            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <h2 class="mb-3 text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Select Job') }}</h2>
                <select wire:model.live="selected_job_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="">{{ __('Choose a job') }}</option>
                    @foreach ($jobs as $job)
                        <option value="{{ $job->id }}">{{ $job->code }} · {{ $job->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="space-y-6">
            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap gap-3">
                    @foreach (['jobs' => __('Jobs'), 'phases' => __('Phases'), 'cost_codes' => __('Cost Codes'), 'budgets' => __('Budgets'), 'transactions' => __('Transactions')] as $tabKey => $tabLabel)
                        <button type="button" wire:click="$set('workspace_tab', '{{ $tabKey }}')" class="rounded-md px-3 py-2 text-sm font-semibold {{ $workspace_tab === $tabKey ? 'bg-neutral-200 dark:bg-neutral-700' : 'bg-neutral-100 dark:bg-neutral-800' }}">
                            {{ $tabLabel }}
                        </button>
                    @endforeach
                </div>
            </div>

            @if ($workspace_tab === 'jobs')
                <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="app-table-shell">
                        <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                                <tr>
                                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Code') }}</th>
                                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Name') }}</th>
                                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Branch') }}</th>
                                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Status') }}</th>
                                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Cost') }}</th>
                                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Revenue') }}</th>
                                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Margin') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                                @forelse($jobs as $job)
                                    @php($summary = $job->id === ($profitability['job_id'] ?? null) ? $profitability : null)
                                    <tr class="{{ $selected_job_id === $job->id ? 'bg-neutral-50 dark:bg-neutral-800/70' : '' }}">
                                        <td class="px-3 py-2 text-sm font-medium text-neutral-900 dark:text-neutral-100">
                                            <button type="button" wire:click="$set('selected_job_id', {{ $job->id }})" class="hover:underline">{{ $job->code }}</button>
                                        </td>
                                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $job->name }}</td>
                                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $job->branch?->name ?? '—' }}</td>
                                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ Str::headline($job->status) }}</td>
                                        <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) ($summary['actual_cost'] ?? 0), 2) }}</td>
                                        <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) ($summary['actual_revenue'] ?? 0), 2) }}</td>
                                        <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) ($summary['actual_margin'] ?? 0), 2) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="px-3 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No jobs found.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @elseif ($workspace_tab === 'phases')
                <div class="grid gap-6 xl:grid-cols-[360px,1fr]">
                    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <h2 class="mb-3 text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ $editing_phase_id ? __('Edit Phase') : __('New Phase') }}</h2>
                        <form wire:submit="savePhase" class="space-y-4">
                            <flux:input wire:model="phase_name" :label="__('Phase Name')" />
                            <flux:input wire:model="phase_code" :label="__('Phase Code')" />
                            <div>
                                <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Status') }}</label>
                                <select wire:model="phase_status" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                    <option value="active">{{ __('Active') }}</option>
                                    <option value="on_hold">{{ __('On Hold') }}</option>
                                    <option value="closed">{{ __('Closed') }}</option>
                                </select>
                            </div>
                            <div class="flex justify-end">
                                <flux:button type="submit">{{ __('Save Phase') }}</flux:button>
                            </div>
                        </form>
                    </div>
                    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="app-table-shell">
                            <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                                <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                                    <tr>
                                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Code') }}</th>
                                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Name') }}</th>
                                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Status') }}</th>
                                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                                    @forelse ($selectedJob?->phases ?? [] as $phase)
                                        <tr>
                                            <td class="px-3 py-2 text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ $phase->code }}</td>
                                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $phase->name }}</td>
                                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ Str::headline($phase->status) }}</td>
                                            <td class="px-3 py-2 text-right">
                                                <div class="flex justify-end gap-2">
                                                    <flux:button type="button" wire:click="editPhase({{ $phase->id }})" size="xs" variant="ghost">{{ __('Edit') }}</flux:button>
                                                    @if($phase->status !== 'closed')
                                                        <flux:button type="button" wire:click="closePhase({{ $phase->id }})" size="xs" variant="ghost">{{ __('Close') }}</flux:button>
                                                    @endif
                                                </div>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="4" class="px-3 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('Select a job to manage phases.') }}</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @elseif ($workspace_tab === 'cost_codes')
                <div class="grid gap-6 xl:grid-cols-[360px,1fr]">
                    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <h2 class="mb-3 text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ $editing_cost_code_id ? __('Edit Cost Code') : __('New Cost Code') }}</h2>
                        <form wire:submit="saveCostCode" class="space-y-4">
                            <flux:input wire:model="cost_code_name" :label="__('Cost Code Name')" />
                            <flux:input wire:model="cost_code_code" :label="__('Code')" />
                            <div>
                                <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Default Account') }}</label>
                                <select wire:model="cost_code_default_account_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                    <option value="">{{ __('None') }}</option>
                                    @foreach ($accounts as $account)
                                        <option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <label class="inline-flex items-center gap-2 text-sm text-neutral-700 dark:text-neutral-200">
                                <input type="checkbox" wire:model="cost_code_is_active" class="rounded border-neutral-300 text-primary-600">
                                {{ __('Active') }}
                            </label>
                            <div class="flex justify-end">
                                <flux:button type="submit">{{ __('Save Cost Code') }}</flux:button>
                            </div>
                        </form>
                    </div>
                    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="app-table-shell">
                            <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                                <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                                    <tr>
                                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Code') }}</th>
                                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Name') }}</th>
                                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Default Account') }}</th>
                                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Status') }}</th>
                                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                                    @forelse ($costCodes as $costCode)
                                        <tr>
                                            <td class="px-3 py-2 text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ $costCode->code }}</td>
                                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $costCode->name }}</td>
                                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $costCode->defaultAccount?->code ?? '—' }}</td>
                                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $costCode->is_active ? __('Active') : __('Inactive') }}</td>
                                            <td class="px-3 py-2 text-right">
                                                <div class="flex justify-end gap-2">
                                                    <flux:button type="button" wire:click="editCostCode({{ $costCode->id }})" size="xs" variant="ghost">{{ __('Edit') }}</flux:button>
                                                    @if($costCode->is_active)
                                                        <flux:button type="button" wire:click="deactivateCostCode({{ $costCode->id }})" size="xs" variant="ghost">{{ __('Deactivate') }}</flux:button>
                                                    @endif
                                                </div>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="5" class="px-3 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No job cost codes found.') }}</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @elseif ($workspace_tab === 'budgets')
                <div class="grid gap-6 xl:grid-cols-[360px,1fr]">
                    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <h2 class="mb-3 text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ $editing_budget_id ? __('Edit Job Budget') : __('New Job Budget') }}</h2>
                        <form wire:submit="saveBudgetEntry" class="space-y-4">
                            <div>
                                <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Phase') }}</label>
                                <select wire:model="budget_phase_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                    <option value="">{{ __('Unassigned') }}</option>
                                    @foreach ($selectedJob?->phases ?? [] as $phase)
                                        <option value="{{ $phase->id }}">{{ $phase->code }} · {{ $phase->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Cost Code') }}</label>
                                <select wire:model="budget_cost_code_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                    <option value="">{{ __('Unassigned') }}</option>
                                    @foreach ($costCodes as $costCode)
                                        <option value="{{ $costCode->id }}">{{ $costCode->code }} · {{ $costCode->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <flux:input wire:model="budget_amount" type="number" step="0.01" :label="__('Budget Amount')" />
                            <div class="flex justify-end">
                                <flux:button type="submit">{{ __('Save Budget') }}</flux:button>
                            </div>
                        </form>
                    </div>
                    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="app-table-shell">
                            <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                                <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                                    <tr>
                                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Phase') }}</th>
                                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Cost Code') }}</th>
                                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Amount') }}</th>
                                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                                    @forelse ($selectedJob?->budgets ?? [] as $budget)
                                        <tr>
                                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $budget->phase?->name ?? __('Unassigned') }}</td>
                                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $budget->costCode?->code ?? __('Unassigned') }}</td>
                                            <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $budget->budget_amount, 2) }}</td>
                                            <td class="px-3 py-2 text-right">
                                                <div class="flex justify-end gap-2">
                                                    <flux:button type="button" wire:click="editBudget({{ $budget->id }})" size="xs" variant="ghost">{{ __('Edit') }}</flux:button>
                                                    <flux:button type="button" wire:click="deleteBudgetEntry({{ $budget->id }})" size="xs" variant="ghost">{{ __('Delete') }}</flux:button>
                                                </div>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="4" class="px-3 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('Select a job to manage budgets.') }}</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @elseif ($workspace_tab === 'transactions')
                <div class="grid gap-6 xl:grid-cols-[360px,1fr]">
                    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <h2 class="mb-3 text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Record Manual Transaction') }}</h2>
                        <form wire:submit="recordTransaction" class="space-y-4">
                            <div class="grid gap-4 md:grid-cols-2">
                                <flux:input wire:model="transaction_date" type="date" :label="__('Date')" />
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Type') }}</label>
                                    <select wire:model="transaction_type" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                        <option value="cost">{{ __('Cost') }}</option>
                                        <option value="revenue">{{ __('Revenue') }}</option>
                                        <option value="adjustment">{{ __('Adjustment') }}</option>
                                    </select>
                                </div>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Phase') }}</label>
                                <select wire:model="transaction_phase_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                    <option value="">{{ __('Unassigned') }}</option>
                                    @foreach ($selectedJob?->phases ?? [] as $phase)
                                        <option value="{{ $phase->id }}">{{ $phase->code }} · {{ $phase->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Cost Code') }}</label>
                                <select wire:model="transaction_cost_code_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                    <option value="">{{ __('Unassigned') }}</option>
                                    @foreach ($costCodes as $costCode)
                                        <option value="{{ $costCode->id }}">{{ $costCode->code }} · {{ $costCode->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <flux:input wire:model="transaction_amount" type="number" step="0.01" :label="__('Amount')" />
                            <flux:textarea wire:model="transaction_memo" :label="__('Memo')" rows="2" />
                            <div class="flex justify-end">
                                <flux:button type="submit">{{ __('Record Transaction') }}</flux:button>
                            </div>
                        </form>
                    </div>
                    <div class="space-y-6">
                        @if ($profitability)
                            <div class="grid gap-4 md:grid-cols-4">
                                <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                                    <p class="text-xs uppercase tracking-wide text-neutral-500">{{ __('Budget') }}</p>
                                    <p class="mt-2 text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format((float) $profitability['budget_total'], 2) }}</p>
                                </div>
                                <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                                    <p class="text-xs uppercase tracking-wide text-neutral-500">{{ __('Actual Cost') }}</p>
                                    <p class="mt-2 text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format((float) $profitability['actual_cost'], 2) }}</p>
                                </div>
                                <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                                    <p class="text-xs uppercase tracking-wide text-neutral-500">{{ __('Actual Revenue') }}</p>
                                    <p class="mt-2 text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format((float) $profitability['actual_revenue'], 2) }}</p>
                                </div>
                                <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                                    <p class="text-xs uppercase tracking-wide text-neutral-500">{{ __('Margin') }}</p>
                                    <p class="mt-2 text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format((float) $profitability['actual_margin'], 2) }}</p>
                                </div>
                            </div>

                            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                                <h2 class="mb-3 text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Phase Breakdown') }}</h2>
                                <div class="app-table-shell">
                                    <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                                        <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                                            <tr>
                                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Phase') }}</th>
                                                <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Cost') }}</th>
                                                <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Revenue') }}</th>
                                                <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Margin') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                                            @forelse ($profitability['phase_breakdown'] as $row)
                                                <tr>
                                                    <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $row['phase_name'] }}</td>
                                                    <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $row['cost_total'], 2) }}</td>
                                                    <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $row['revenue_total'], 2) }}</td>
                                                    <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $row['margin_total'], 2) }}</td>
                                                </tr>
                                            @empty
                                                <tr><td colspan="4" class="px-3 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No phase activity found.') }}</td></tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                                <h2 class="mb-3 text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Cost Code Breakdown') }}</h2>
                                <div class="app-table-shell">
                                    <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                                        <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                                            <tr>
                                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Cost Code') }}</th>
                                                <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Budget') }}</th>
                                                <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Cost') }}</th>
                                                <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Revenue') }}</th>
                                                <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Margin') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                                            @forelse ($profitability['cost_code_breakdown'] as $row)
                                                <tr>
                                                    <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $row['cost_code'] }}</td>
                                                    <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $row['budget_total'], 2) }}</td>
                                                    <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $row['cost_total'], 2) }}</td>
                                                    <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $row['revenue_total'], 2) }}</td>
                                                    <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $row['margin_total'], 2) }}</td>
                                                </tr>
                                            @empty
                                                <tr><td colspan="5" class="px-3 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No cost-code activity found.') }}</td></tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                                <h2 class="mb-3 text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Transactions') }}</h2>
                                <div class="app-table-shell">
                                    <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                                        <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                                            <tr>
                                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Date') }}</th>
                                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Type') }}</th>
                                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Phase') }}</th>
                                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Cost Code') }}</th>
                                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Source') }}</th>
                                                <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Amount') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                                            @forelse ($profitability['transactions'] as $transaction)
                                                <tr>
                                                    <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $transaction['transaction_date'] }}</td>
                                                    <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ Str::headline($transaction['transaction_type']) }}</td>
                                                    <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $transaction['phase_name'] ?? '—' }}</td>
                                                    <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $transaction['cost_code'] ?? '—' }}</td>
                                                    <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                                                        @if ($transaction['is_source_linked'])
                                                            @if($transaction['source_route'])
                                                                <a href="{{ route($transaction['source_route'], $transaction['source_route_params']) }}" class="rounded-full bg-neutral-100 px-2 py-1 text-xs font-medium hover:underline dark:bg-neutral-800">
                                                                    {{ class_basename((string) $transaction['source_type']) }} #{{ $transaction['source_id'] }}
                                                                </a>
                                                            @else
                                                                <span class="rounded-full bg-neutral-100 px-2 py-1 text-xs font-medium dark:bg-neutral-800">{{ class_basename((string) $transaction['source_type']) }} #{{ $transaction['source_id'] }}</span>
                                                            @endif
                                                        @else
                                                            {{ __('Manual') }}
                                                        @endif
                                                        @if($transaction['memo'])
                                                            <div class="mt-1 text-xs text-neutral-500">{{ $transaction['memo'] }}</div>
                                                        @endif
                                                    </td>
                                                    <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $transaction['amount'], 2) }}</td>
                                                </tr>
                                            @empty
                                                <tr><td colspan="6" class="px-3 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No job transactions found.') }}</td></tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @else
                            <div class="rounded-lg border border-dashed border-neutral-300 bg-white p-6 text-sm text-neutral-600 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                                {{ __('Select a job to review budgets, margins, and job transactions.') }}
                            </div>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
