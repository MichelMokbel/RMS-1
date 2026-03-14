<?php

use App\Models\AccountingCompany;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Job;
use App\Services\Accounting\AccountingAuditLogService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;

new class extends Component {
    public string $tab = 'companies';

    public ?int $company_editing_id = null;
    public string $company_name = '';
    public string $company_code = '';
    public string $company_base_currency = 'QAR';
    public bool $company_is_active = true;
    public bool $company_is_default = false;

    public ?int $branch_editing_id = null;
    public ?int $branch_company_id = null;
    public string $branch_name = '';
    public string $branch_code = '';
    public bool $branch_is_active = true;

    public ?int $department_editing_id = null;
    public ?int $department_company_id = null;
    public string $department_name = '';
    public string $department_code = '';
    public bool $department_is_active = true;

    public ?int $job_editing_id = null;
    public ?int $job_company_id = null;
    public ?int $job_branch_id = null;
    public string $job_name = '';
    public string $job_code = '';
    public string $job_status = 'active';
    public ?string $job_start_date = null;
    public ?string $job_end_date = null;
    public float $job_estimated_revenue = 0.0;
    public float $job_estimated_cost = 0.0;
    public ?string $job_notes = null;

    public function mount(): void
    {
        $this->authorizeFinanceSettings();
        $requestedTab = (string) request()->query('tab', 'companies');
        $this->tab = array_key_exists($requestedTab, $this->availableTabs()) ? $requestedTab : 'companies';
        $this->resetCompanyForm();
        $this->resetBranchForm();
        $this->resetDepartmentForm();
        $this->resetJobForm();
    }

    public function with(): array
    {
        $this->authorizeFinanceSettings();

        $companies = AccountingCompany::query()
            ->orderByDesc('is_default')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        $branches = Branch::query()
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        $departments = Department::query()
            ->with('company')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        $jobs = Job::query()
            ->orderByRaw("case when status = 'active' then 0 when status = 'on_hold' then 1 else 2 end")
            ->orderBy('code')
            ->get();

        return [
            'tabs' => $this->availableTabs(),
            'companies' => $companies,
            'companyNames' => $companies->pluck('name', 'id'),
            'branches' => $branches,
            'branchNames' => $branches->pluck('name', 'id'),
            'departments' => $departments,
            'jobs' => $jobs,
        ];
    }

    public function setTab(string $tab): void
    {
        $this->tab = array_key_exists($tab, $this->availableTabs()) ? $tab : 'companies';
        $this->resetErrorBag();
        $this->resetValidation();
    }

    public function saveCompany(AccountingAuditLogService $auditLog): void
    {
        $this->authorizeFinanceSettings();

        $data = $this->validate([
            'company_name' => ['required', 'string', 'max:150'],
            'company_code' => ['required', 'string', 'max:50', Rule::unique('accounting_companies', 'code')->ignore($this->company_editing_id)],
            'company_base_currency' => ['required', 'string', 'max:10'],
            'company_is_active' => ['required', 'boolean'],
            'company_is_default' => ['required', 'boolean'],
        ]);

        $company = DB::transaction(function () use ($data) {
            $attributes = [
                'name' => trim($data['company_name']),
                'code' => strtoupper(trim($data['company_code'])),
                'base_currency' => strtoupper(trim($data['company_base_currency'])),
                'is_active' => (bool) $data['company_is_active'],
                'is_default' => (bool) $data['company_is_default'],
            ];

            $hasOtherDefault = AccountingCompany::query()
                ->when($this->company_editing_id, fn ($query) => $query->whereKeyNot($this->company_editing_id))
                ->where('is_default', true)
                ->exists();

            if (! $hasOtherDefault && ! $this->company_editing_id) {
                $attributes['is_default'] = true;
            }

            if ($attributes['is_default']) {
                $attributes['is_active'] = true;
                AccountingCompany::query()->update(['is_default' => false]);
            }

            $company = $this->company_editing_id
                ? tap(AccountingCompany::query()->findOrFail($this->company_editing_id))->update($attributes)
                : AccountingCompany::query()->create($attributes);

            if (! AccountingCompany::query()->where('is_default', true)->exists()) {
                $company->forceFill(['is_default' => true, 'is_active' => true])->save();
            }

            return $company->fresh();
        });

        $auditLog->log(
            $this->company_editing_id ? 'settings.company.updated' : 'settings.company.created',
            (int) Auth::id(),
            $company,
            ['code' => $company->code, 'is_default' => (bool) $company->is_default],
            (int) $company->id
        );

        session()->flash('status', $this->company_editing_id ? __('Company updated.') : __('Company created.'));
        $this->resetCompanyForm();
        $this->resetBranchForm();
        $this->resetDepartmentForm();
        $this->resetJobForm();
    }

    public function editCompany(int $id): void
    {
        $this->authorizeFinanceSettings();

        $company = AccountingCompany::query()->findOrFail($id);
        $this->company_editing_id = (int) $company->id;
        $this->company_name = (string) $company->name;
        $this->company_code = (string) $company->code;
        $this->company_base_currency = (string) $company->base_currency;
        $this->company_is_active = (bool) $company->is_active;
        $this->company_is_default = (bool) $company->is_default;
    }

    public function toggleCompanyActive(int $id, AccountingAuditLogService $auditLog): void
    {
        $this->authorizeFinanceSettings();

        $company = AccountingCompany::query()->findOrFail($id);
        $next = $company->is_default ? true : ! $company->is_active;
        $company->update(['is_active' => $next]);

        $auditLog->log('settings.company.status_changed', (int) Auth::id(), $company, ['is_active' => $next], (int) $company->id);
    }

    public function makeDefaultCompany(int $id, AccountingAuditLogService $auditLog): void
    {
        $this->authorizeFinanceSettings();

        DB::transaction(function () use ($id) {
            AccountingCompany::query()->update(['is_default' => false]);
            AccountingCompany::query()->whereKey($id)->update(['is_default' => true, 'is_active' => true]);
        });

        $company = AccountingCompany::query()->findOrFail($id);
        $auditLog->log('settings.company.default_changed', (int) Auth::id(), $company, ['is_default' => true], (int) $company->id);
    }

    public function saveBranch(AccountingAuditLogService $auditLog): void
    {
        $this->authorizeFinanceSettings();

        $data = $this->validate([
            'branch_company_id' => ['required', 'integer', 'exists:accounting_companies,id'],
            'branch_name' => ['required', 'string', 'max:100'],
            'branch_code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('branches', 'code')
                    ->where(fn ($query) => $query->where('company_id', $this->branch_company_id))
                    ->ignore($this->branch_editing_id),
            ],
            'branch_is_active' => ['required', 'boolean'],
        ]);

        $attributes = [
            'company_id' => $data['branch_company_id'],
            'name' => trim($data['branch_name']),
            'code' => strtoupper(trim($data['branch_code'])),
            'is_active' => (bool) $data['branch_is_active'],
        ];

        $branch = $this->branch_editing_id
            ? tap(Branch::query()->findOrFail($this->branch_editing_id))->update($attributes)
            : Branch::query()->create($attributes);

        $branch = $branch->fresh();

        $auditLog->log(
            $this->branch_editing_id ? 'settings.branch.updated' : 'settings.branch.created',
            (int) Auth::id(),
            $branch,
            ['code' => $branch->code, 'is_active' => (bool) $branch->is_active],
            (int) $branch->company_id
        );

        session()->flash('status', $this->branch_editing_id ? __('Branch updated.') : __('Branch created.'));
        $this->resetBranchForm();
    }

    public function editBranch(int $id): void
    {
        $this->authorizeFinanceSettings();

        $branch = Branch::query()->findOrFail($id);
        $this->branch_editing_id = (int) $branch->id;
        $this->branch_company_id = $branch->company_id ? (int) $branch->company_id : $this->defaultCompanyId();
        $this->branch_name = (string) $branch->name;
        $this->branch_code = (string) ($branch->code ?? '');
        $this->branch_is_active = (bool) $branch->is_active;
    }

    public function toggleBranchActive(int $id, AccountingAuditLogService $auditLog): void
    {
        $this->authorizeFinanceSettings();

        $branch = Branch::query()->findOrFail($id);
        $branch->update(['is_active' => ! $branch->is_active]);

        $auditLog->log('settings.branch.status_changed', (int) Auth::id(), $branch, ['is_active' => (bool) $branch->fresh()->is_active], (int) $branch->company_id);
    }

    public function saveDepartment(AccountingAuditLogService $auditLog): void
    {
        $this->authorizeFinanceSettings();

        $data = $this->validate([
            'department_company_id' => ['required', 'integer', 'exists:accounting_companies,id'],
            'department_name' => ['required', 'string', 'max:120'],
            'department_code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('departments', 'code')
                    ->where(fn ($query) => $query->where('company_id', $this->department_company_id))
                    ->ignore($this->department_editing_id),
            ],
            'department_is_active' => ['required', 'boolean'],
        ]);

        $attributes = [
            'company_id' => $data['department_company_id'],
            'name' => trim($data['department_name']),
            'code' => strtoupper(trim($data['department_code'])),
            'is_active' => (bool) $data['department_is_active'],
        ];

        $department = $this->department_editing_id
            ? tap(Department::query()->findOrFail($this->department_editing_id))->update($attributes)
            : Department::query()->create($attributes);

        $department = $department->fresh();

        $auditLog->log(
            $this->department_editing_id ? 'settings.department.updated' : 'settings.department.created',
            (int) Auth::id(),
            $department,
            ['code' => $department->code, 'is_active' => (bool) $department->is_active],
            (int) $department->company_id
        );

        session()->flash('status', $this->department_editing_id ? __('Department updated.') : __('Department created.'));
        $this->resetDepartmentForm();
    }

    public function editDepartment(int $id): void
    {
        $this->authorizeFinanceSettings();

        $department = Department::query()->findOrFail($id);
        $this->department_editing_id = (int) $department->id;
        $this->department_company_id = (int) $department->company_id;
        $this->department_name = (string) $department->name;
        $this->department_code = (string) $department->code;
        $this->department_is_active = (bool) $department->is_active;
    }

    public function toggleDepartmentActive(int $id, AccountingAuditLogService $auditLog): void
    {
        $this->authorizeFinanceSettings();

        $department = Department::query()->findOrFail($id);
        $department->update(['is_active' => ! $department->is_active]);

        $auditLog->log('settings.department.status_changed', (int) Auth::id(), $department, ['is_active' => (bool) $department->fresh()->is_active], (int) $department->company_id);
    }

    public function saveJob(AccountingAuditLogService $auditLog): void
    {
        $this->authorizeFinanceSettings();

        $data = $this->validate([
            'job_company_id' => ['required', 'integer', 'exists:accounting_companies,id'],
            'job_branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'job_name' => ['required', 'string', 'max:150'],
            'job_code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('accounting_jobs', 'code')
                    ->where(fn ($query) => $query->where('company_id', $this->job_company_id))
                    ->ignore($this->job_editing_id),
            ],
            'job_status' => ['required', 'in:active,on_hold,closed'],
            'job_start_date' => ['nullable', 'date'],
            'job_end_date' => ['nullable', 'date', 'after_or_equal:job_start_date'],
            'job_estimated_revenue' => ['required', 'numeric', 'min:0'],
            'job_estimated_cost' => ['required', 'numeric', 'min:0'],
            'job_notes' => ['nullable', 'string'],
        ]);

        if ($data['job_branch_id']) {
            $branch = Branch::query()->findOrFail((int) $data['job_branch_id']);
            if ((int) ($branch->company_id ?? 0) !== (int) $data['job_company_id']) {
                throw ValidationException::withMessages([
                    'job_branch_id' => __('The selected branch must belong to the selected company.'),
                ]);
            }
        }

        $attributes = [
            'company_id' => $data['job_company_id'],
            'branch_id' => $data['job_branch_id'] ?: null,
            'name' => trim($data['job_name']),
            'code' => strtoupper(trim($data['job_code'])),
            'status' => $data['job_status'],
            'start_date' => $data['job_start_date'] ?: null,
            'end_date' => $data['job_end_date'] ?: null,
            'estimated_revenue' => round((float) $data['job_estimated_revenue'], 2),
            'estimated_cost' => round((float) $data['job_estimated_cost'], 2),
            'notes' => $data['job_notes'] ? trim($data['job_notes']) : null,
        ];

        $job = $this->job_editing_id
            ? tap(Job::query()->findOrFail($this->job_editing_id))->update($attributes)
            : Job::query()->create($attributes);

        $job = $job->fresh();

        $auditLog->log(
            $this->job_editing_id ? 'settings.job.updated' : 'settings.job.created',
            (int) Auth::id(),
            $job,
            ['code' => $job->code, 'status' => $job->status],
            (int) $job->company_id
        );

        session()->flash('status', $this->job_editing_id ? __('Job updated.') : __('Job created.'));
        $this->resetJobForm();
    }

    public function editJob(int $id): void
    {
        $this->authorizeFinanceSettings();

        $job = Job::query()->findOrFail($id);
        $this->job_editing_id = (int) $job->id;
        $this->job_company_id = (int) $job->company_id;
        $this->job_branch_id = $job->branch_id ? (int) $job->branch_id : null;
        $this->job_name = (string) $job->name;
        $this->job_code = (string) $job->code;
        $this->job_status = (string) $job->status;
        $this->job_start_date = $job->start_date?->format('Y-m-d');
        $this->job_end_date = $job->end_date?->format('Y-m-d');
        $this->job_estimated_revenue = (float) $job->estimated_revenue;
        $this->job_estimated_cost = (float) $job->estimated_cost;
        $this->job_notes = $job->notes;
    }

    public function setJobStatus(int $id, string $status, AccountingAuditLogService $auditLog): void
    {
        $this->authorizeFinanceSettings();

        abort_unless(in_array($status, ['active', 'on_hold', 'closed'], true), 404);

        $job = Job::query()->findOrFail($id);
        $job->update(['status' => $status]);

        $auditLog->log('settings.job.status_changed', (int) Auth::id(), $job, ['status' => $status], (int) $job->company_id);
    }

    public function resetCompanyForm(): void
    {
        $this->company_editing_id = null;
        $this->company_name = '';
        $this->company_code = '';
        $this->company_base_currency = config('pos.currency', 'QAR');
        $this->company_is_active = true;
        $this->company_is_default = ! AccountingCompany::query()->where('is_default', true)->exists();
    }

    public function resetBranchForm(): void
    {
        $this->branch_editing_id = null;
        $this->branch_company_id = $this->defaultCompanyId();
        $this->branch_name = '';
        $this->branch_code = '';
        $this->branch_is_active = true;
    }

    public function resetDepartmentForm(): void
    {
        $this->department_editing_id = null;
        $this->department_company_id = $this->defaultCompanyId();
        $this->department_name = '';
        $this->department_code = '';
        $this->department_is_active = true;
    }

    public function resetJobForm(): void
    {
        $this->job_editing_id = null;
        $this->job_company_id = $this->defaultCompanyId();
        $this->job_branch_id = null;
        $this->job_name = '';
        $this->job_code = '';
        $this->job_status = 'active';
        $this->job_start_date = null;
        $this->job_end_date = null;
        $this->job_estimated_revenue = 0.0;
        $this->job_estimated_cost = 0.0;
        $this->job_notes = null;
    }

    private function defaultCompanyId(): ?int
    {
        return AccountingCompany::query()->where('is_default', true)->value('id')
            ?: AccountingCompany::query()->orderBy('id')->value('id');
    }

    private function availableTabs(): array
    {
        return [
            'companies' => __('Companies'),
            'branches' => __('Branches'),
            'departments' => __('Departments'),
            'jobs' => __('Jobs'),
        ];
    }

    private function authorizeFinanceSettings(): void
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();

        if (! $user || (! $user->hasRole('admin') && ! $user->hasRole('manager') && ! $user->can('finance.access'))) {
            abort(403);
        }
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout
        :heading="__('Organization')"
        :subheading="__('Manage accounting companies, branches, departments, and jobs from one settings workspace.')"
        contentClass="mt-5 w-full max-w-6xl"
    >
        @if(session('status'))
            <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
                {{ session('status') }}
            </div>
        @endif

        <div class="flex flex-wrap gap-3">
            @foreach ($tabs as $key => $label)
                <button
                    type="button"
                    wire:click="setTab('{{ $key }}')"
                    class="rounded-md px-3 py-2 text-sm font-semibold {{ $tab === $key ? 'bg-neutral-200 text-neutral-900 dark:bg-neutral-700 dark:text-neutral-50' : 'bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-200' }}"
                >
                    {{ $label }}
                </button>
            @endforeach
        </div>
        <br>

        @if ($tab === 'companies')
            <div class="grid gap-6 xl:grid-cols-2">
                <div class="space-y-4">
                <div class="rounded-md border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-sm font-semibold text-neutral-800 dark:text-neutral-100">{{ $company_editing_id ? __('Edit Company') : __('New Company') }}</div>
                            <div class="text-xs text-neutral-500 dark:text-neutral-400">{{ __('Accounting books, default currency, and default company selection.') }}</div>
                        </div>
                        <flux:button size="xs" variant="ghost" type="button" wire:click="resetCompanyForm">{{ __('Clear') }}</flux:button>
                    </div>

                    <div class="grid gap-3 md:grid-cols-2">
                        <flux:input wire:model="company_name" :label="__('Name')" />
                        <flux:input wire:model="company_code" :label="__('Code')" />
                        <flux:input wire:model="company_base_currency" :label="__('Base Currency')" />
                        <div class="flex items-center gap-4 pt-7 text-sm">
                            <label class="flex items-center gap-2">
                                <input type="checkbox" wire:model="company_is_active" />
                                <span>{{ __('Active') }}</span>
                            </label>
                            <label class="flex items-center gap-2">
                                <input type="checkbox" wire:model="company_is_default" />
                                <span>{{ __('Default Company') }}</span>
                            </label>
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <flux:button type="button" variant="primary" wire:click="saveCompany">{{ __('Save Company') }}</flux:button>
                    </div>
                </div>

                <div class="rounded-md border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="border-b border-neutral-200 px-4 py-3 text-sm font-semibold dark:border-neutral-800">{{ __('Companies') }}</div>
                    <div class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        @forelse ($companies as $company)
                            <div class="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                                <div>
                                    <div class="font-medium text-neutral-800 dark:text-neutral-100">
                                        {{ $company->name }}
                                        @if ($company->is_default)
                                            <span class="ml-2 rounded-full bg-sky-100 px-2 py-0.5 text-[11px] font-semibold text-sky-700 dark:bg-sky-950 dark:text-sky-200">{{ __('Default') }}</span>
                                        @endif
                                    </div>
                                    <div class="text-xs text-neutral-500 dark:text-neutral-400">
                                        {{ $company->code }} • {{ $company->base_currency }} • {{ $company->is_active ? __('Active') : __('Inactive') }}
                                    </div>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    @if (! $company->is_default)
                                        <flux:button size="xs" variant="outline" type="button" wire:click="makeDefaultCompany({{ $company->id }})">{{ __('Make Default') }}</flux:button>
                                    @endif
                                    <flux:button size="xs" variant="ghost" type="button" wire:click="editCompany({{ $company->id }})">{{ __('Edit') }}</flux:button>
                                    <flux:button size="xs" variant="outline" type="button" wire:click="toggleCompanyActive({{ $company->id }})">
                                        {{ $company->is_active ? __('Disable') : __('Enable') }}
                                    </flux:button>
                                </div>
                            </div>
                        @empty
                            <div class="px-4 py-6 text-sm text-neutral-500 dark:text-neutral-400">{{ __('No accounting companies yet.') }}</div>
                        @endforelse
                    </div>
                </div>
                </div>
            </div>
        @endif

        @if ($tab === 'branches')
            <div class="grid gap-6 xl:grid-cols-2">
                <div class="space-y-4">
                <div class="rounded-md border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-sm font-semibold text-neutral-800 dark:text-neutral-100">{{ $branch_editing_id ? __('Edit Branch') : __('New Branch') }}</div>
                            <div class="text-xs text-neutral-500 dark:text-neutral-400">{{ __('Operational sites mapped to accounting companies.') }}</div>
                        </div>
                        <flux:button size="xs" variant="ghost" type="button" wire:click="resetBranchForm">{{ __('Clear') }}</flux:button>
                    </div>

                    @if ($companies->isEmpty())
                        <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100">{{ __('Create a company before adding branches.') }}</div>
                    @else
                        <div class="grid gap-3 md:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Company') }}</label>
                                <select wire:model="branch_company_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                    <option value="">{{ __('Select company') }}</option>
                                    @foreach ($companies as $company)
                                        <option value="{{ $company->id }}">{{ $company->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <flux:input wire:model="branch_name" :label="__('Name')" />
                            <flux:input wire:model="branch_code" :label="__('Code')" />
                            <div class="flex items-center pt-7 text-sm">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" wire:model="branch_is_active" />
                                    <span>{{ __('Active') }}</span>
                                </label>
                            </div>
                        </div>

                        <div class="flex justify-end">
                            <flux:button type="button" variant="primary" wire:click="saveBranch">{{ __('Save Branch') }}</flux:button>
                        </div>
                    @endif
                </div>

                <div class="rounded-md border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="border-b border-neutral-200 px-4 py-3 text-sm font-semibold dark:border-neutral-800">{{ __('Branches') }}</div>
                    <div class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        @forelse ($branches as $branch)
                            <div class="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                                <div>
                                    <div class="font-medium text-neutral-800 dark:text-neutral-100">{{ $branch->name }}</div>
                                    <div class="text-xs text-neutral-500 dark:text-neutral-400">
                                        {{ $branch->code ?: '—' }} • {{ $companyNames[$branch->company_id] ?? __('Unassigned Company') }} • {{ $branch->is_active ? __('Active') : __('Inactive') }}
                                    </div>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <flux:button size="xs" variant="ghost" type="button" wire:click="editBranch({{ $branch->id }})">{{ __('Edit') }}</flux:button>
                                    <flux:button size="xs" variant="outline" type="button" wire:click="toggleBranchActive({{ $branch->id }})">
                                        {{ $branch->is_active ? __('Disable') : __('Enable') }}
                                    </flux:button>
                                </div>
                            </div>
                        @empty
                            <div class="px-4 py-6 text-sm text-neutral-500 dark:text-neutral-400">{{ __('No branches yet.') }}</div>
                        @endforelse
                    </div>
                </div>
                </div>
            </div>
        @endif

        @if ($tab === 'departments')
            <div class="grid gap-6 xl:grid-cols-2">
                <div class="space-y-4">
                <div class="rounded-md border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-sm font-semibold text-neutral-800 dark:text-neutral-100">{{ $department_editing_id ? __('Edit Department') : __('New Department') }}</div>
                            <div class="text-xs text-neutral-500 dark:text-neutral-400">{{ __('Reporting and budgeting dimensions for each accounting company.') }}</div>
                        </div>
                        <flux:button size="xs" variant="ghost" type="button" wire:click="resetDepartmentForm">{{ __('Clear') }}</flux:button>
                    </div>

                    @if ($companies->isEmpty())
                        <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100">{{ __('Create a company before adding departments.') }}</div>
                    @else
                        <div class="grid gap-3 md:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Company') }}</label>
                                <select wire:model="department_company_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                    <option value="">{{ __('Select company') }}</option>
                                    @foreach ($companies as $company)
                                        <option value="{{ $company->id }}">{{ $company->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <flux:input wire:model="department_name" :label="__('Name')" />
                            <flux:input wire:model="department_code" :label="__('Code')" />
                            <div class="flex items-center pt-7 text-sm">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" wire:model="department_is_active" />
                                    <span>{{ __('Active') }}</span>
                                </label>
                            </div>
                        </div>

                        <div class="flex justify-end">
                            <flux:button type="button" variant="primary" wire:click="saveDepartment">{{ __('Save Department') }}</flux:button>
                        </div>
                    @endif
                </div>

                <div class="rounded-md border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="border-b border-neutral-200 px-4 py-3 text-sm font-semibold dark:border-neutral-800">{{ __('Departments') }}</div>
                    <div class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        @forelse ($departments as $department)
                            <div class="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                                <div>
                                    <div class="font-medium text-neutral-800 dark:text-neutral-100">{{ $department->name }}</div>
                                    <div class="text-xs text-neutral-500 dark:text-neutral-400">
                                        {{ $department->code }} • {{ $department->company?->name ?? __('Unknown Company') }} • {{ $department->is_active ? __('Active') : __('Inactive') }}
                                    </div>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <flux:button size="xs" variant="ghost" type="button" wire:click="editDepartment({{ $department->id }})">{{ __('Edit') }}</flux:button>
                                    <flux:button size="xs" variant="outline" type="button" wire:click="toggleDepartmentActive({{ $department->id }})">
                                        {{ $department->is_active ? __('Disable') : __('Enable') }}
                                    </flux:button>
                                </div>
                            </div>
                        @empty
                            <div class="px-4 py-6 text-sm text-neutral-500 dark:text-neutral-400">{{ __('No departments yet.') }}</div>
                        @endforelse
                    </div>
                </div>
                </div>
            </div>
        @endif

        @if ($tab === 'jobs')
            <div class="grid gap-6 xl:grid-cols-2">
                <div class="space-y-4">
                <div class="rounded-md border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-sm font-semibold text-neutral-800 dark:text-neutral-100">{{ $job_editing_id ? __('Edit Job') : __('New Job') }}</div>
                            <div class="text-xs text-neutral-500 dark:text-neutral-400">{{ __('Projects or operational jobs used for costing, budgeting, and AP tagging.') }}</div>
                        </div>
                        <flux:button size="xs" variant="ghost" type="button" wire:click="resetJobForm">{{ __('Clear') }}</flux:button>
                    </div>

                    @if ($companies->isEmpty())
                        <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100">{{ __('Create a company before adding jobs.') }}</div>
                    @else
                        <div class="grid gap-3 md:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Company') }}</label>
                                <select wire:model.live="job_company_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                    <option value="">{{ __('Select company') }}</option>
                                    @foreach ($companies as $company)
                                        <option value="{{ $company->id }}">{{ $company->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Branch') }}</label>
                                <select wire:model="job_branch_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                    <option value="">{{ __('No branch') }}</option>
                                    @foreach ($branches->where('company_id', $job_company_id)->values() as $branch)
                                        <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <flux:input wire:model="job_name" :label="__('Name')" />
                            <flux:input wire:model="job_code" :label="__('Code')" />
                            <div>
                                <label class="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Status') }}</label>
                                <select wire:model="job_status" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                    <option value="active">{{ __('Active') }}</option>
                                    <option value="on_hold">{{ __('On Hold') }}</option>
                                    <option value="closed">{{ __('Closed') }}</option>
                                </select>
                            </div>
                            <flux:input wire:model="job_start_date" type="date" :label="__('Start Date')" />
                            <flux:input wire:model="job_end_date" type="date" :label="__('End Date')" />
                            <flux:input wire:model="job_estimated_revenue" type="number" step="0.01" min="0" :label="__('Estimated Revenue')" />
                            <flux:input wire:model="job_estimated_cost" type="number" step="0.01" min="0" :label="__('Estimated Cost')" />
                        </div>

                        <flux:textarea wire:model="job_notes" :label="__('Notes')" rows="3" />

                        <div class="flex justify-end">
                            <flux:button type="button" variant="primary" wire:click="saveJob">{{ __('Save Job') }}</flux:button>
                        </div>
                    @endif
                </div>

                <div class="rounded-md border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="border-b border-neutral-200 px-4 py-3 text-sm font-semibold dark:border-neutral-800">{{ __('Jobs') }}</div>
                    <div class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        @forelse ($jobs as $job)
                            <div class="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                                <div>
                                    <div class="font-medium text-neutral-800 dark:text-neutral-100">{{ $job->code }} · {{ $job->name }}</div>
                                    <div class="text-xs text-neutral-500 dark:text-neutral-400">
                                        {{ $companyNames[$job->company_id] ?? __('Unknown Company') }}
                                        @if ($job->branch_id)
                                            • {{ $branchNames[$job->branch_id] ?? __('Unknown Branch') }}
                                        @endif
                                        • {{ \Illuminate\Support\Str::headline($job->status) }}
                                    </div>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <flux:button size="xs" variant="ghost" type="button" wire:click="editJob({{ $job->id }})">{{ __('Edit') }}</flux:button>
                                    @if ($job->status !== 'active')
                                        <flux:button size="xs" variant="outline" type="button" wire:click="setJobStatus({{ $job->id }}, 'active')">{{ __('Reopen') }}</flux:button>
                                    @elseif ($job->status === 'active')
                                        <flux:button size="xs" variant="outline" type="button" wire:click="setJobStatus({{ $job->id }}, 'on_hold')">{{ __('Put On Hold') }}</flux:button>
                                    @endif
                                    @if ($job->status !== 'closed')
                                        <flux:button size="xs" variant="outline" type="button" wire:click="setJobStatus({{ $job->id }}, 'closed')">{{ __('Close') }}</flux:button>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <div class="px-4 py-6 text-sm text-neutral-500 dark:text-neutral-400">{{ __('No jobs yet.') }}</div>
                        @endforelse
                    </div>
                </div>
            </div>
        @endif
    </x-settings.layout>
</section>
