<?php

use App\Models\AccountingCompany;
use App\Models\Branch;
use App\Models\Department;
use App\Models\HrEmployee;
use App\Services\HR\EmployeeService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $status = '';

    public string $branch = '';

    public ?int $company_id = null;

    public ?int $current_branch_id = null;

    public ?int $current_department_id = null;

    public string $legal_first_name = '';

    public string $legal_middle_name = '';

    public string $legal_last_name = '';

    public string $display_name = '';

    public string $work_email = '';

    public string $personal_phone = '';

    public string $job_title = '';

    public string $hire_date = '';

    public string $employment_status = 'onboarding';

    public function mount(): void
    {
        $this->authorizeView();
        $companyIds = auth()->user()?->hasRole('admin')
            ? AccountingCompany::query()->pluck('id')
            : Branch::query()->whereIn('id', auth()->user()?->allowedBranchIds() ?: [-1])->pluck('company_id')->unique();
        $this->company_id = AccountingCompany::query()->whereIn('id', $companyIds)->orderByDesc('is_default')->value('id');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedBranch(): void
    {
        $this->resetPage();
    }

    public function updatedCompanyId(): void
    {
        $this->reset(['current_branch_id', 'current_department_id']);
    }

    public function createEmployee(EmployeeService $service): void
    {
        abort_unless(auth()->user()?->hasRole('admin') || auth()->user()?->can('hr.employees.manage'), 403);
        $data = $this->validate([
            'company_id' => ['required', 'integer', 'exists:accounting_companies,id'],
            'current_branch_id' => ['required', 'integer', 'exists:branches,id'],
            'current_department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'legal_first_name' => ['required', 'string', 'max:100'],
            'legal_middle_name' => ['nullable', 'string', 'max:100'],
            'legal_last_name' => ['required', 'string', 'max:100'],
            'display_name' => ['nullable', 'string', 'max:150'],
            'work_email' => ['nullable', 'email', 'max:255'],
            'personal_phone' => ['nullable', 'string', 'max:30'],
            'job_title' => ['required', 'string', 'max:150'],
            'hire_date' => ['required', 'date'],
            'employment_status' => ['required', 'in:onboarding,active'],
        ]);
        $service->create($data, (int) auth()->id());
        $this->reset([
            'current_branch_id', 'current_department_id', 'legal_first_name', 'legal_middle_name',
            'legal_last_name', 'display_name', 'work_email', 'personal_phone', 'job_title', 'hire_date',
        ]);
        $this->employment_status = 'onboarding';
        $this->resetValidation();
        $this->resetPage();
        $this->dispatch('modal-close', name: 'directory-add-employee-modal');
        session()->flash('status', __('Employee created.'));
    }

    public function with(): array
    {
        $this->authorizeView();
        $allowed = auth()->user()?->allowedBranchIds() ?? [];
        $query = HrEmployee::query()
            ->when(! auth()->user()?->hasRole('admin'), function ($q) use ($allowed): void {
                $q->whereIn('current_branch_id', $allowed ?: [-1]);
                if (auth()->user()?->hasRole('manager') && ! auth()->user()?->can('hr.employees.manage')) {
                    $managerId = HrEmployee::query()->where('user_id', auth()->id())->value('id');
                    $q->where(fn ($inner) => $inner->where('manager_id', $managerId ?: -1)->orWhere('user_id', auth()->id()));
                }
            })
            ->when($this->search !== '', function ($q): void {
                $term = '%'.trim($this->search).'%';
                $q->where(fn ($inner) => $inner->where('employee_number', 'like', $term)->orWhere('display_name', 'like', $term)->orWhere('legal_first_name', 'like', $term)->orWhere('legal_last_name', 'like', $term));
            })
            ->when($this->status !== '', fn ($q) => $q->where('employment_status', $this->status))
            ->when($this->branch !== '', fn ($q) => $q->where('current_branch_id', (int) $this->branch))
            ->orderBy('display_name')->orderBy('legal_first_name');

        return [
            'employees' => $query->paginate(20),
            'branches' => Branch::query()->when(! auth()->user()?->hasRole('admin'), fn ($q) => $q->whereIn('id', $allowed ?: [-1]))->where('is_active', true)->orderBy('name')->get(),
            'branchNames' => Branch::query()->pluck('name', 'id'),
            'companies' => AccountingCompany::query()
                ->when(! auth()->user()?->hasRole('admin'), fn ($q) => $q->whereIn('id', Branch::query()->whereIn('id', $allowed ?: [-1])->select('company_id')))
                ->where('is_active', true)->orderBy('name')->get(),
            'creationBranches' => Branch::query()
                ->when(! auth()->user()?->hasRole('admin'), fn ($q) => $q->whereIn('id', $allowed ?: [-1]))
                ->when($this->company_id, fn ($q) => $q->where('company_id', $this->company_id))
                ->where('is_active', true)->orderBy('name')->get(),
            'departments' => Department::query()->when($this->company_id, fn ($q) => $q->where('company_id', $this->company_id))->orderBy('name')->get(),
        ];
    }

    private function authorizeView(): void
    {
        $user = auth()->user();
        abort_unless($user && ($user->hasRole('admin') || $user->can('hr.employees.view')), 403);
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div><h1 class="text-2xl font-semibold">{{ __('Employee directory') }}</h1><p class="text-sm text-neutral-500">{{ __('Search current and historical employee records.') }}</p></div>
        @if(auth()->user()?->hasRole('admin') || auth()->user()?->can('hr.employees.manage'))
            <flux:modal.trigger name="directory-add-employee-modal"><flux:button icon="plus" variant="primary">{{ __('Add employee') }}</flux:button></flux:modal.trigger>
        @endif
    </div>
    @include('livewire.hr.partials.navigation')
    @if(session('status'))<div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>@endif
    <div class="grid gap-3 rounded-lg border border-neutral-200 bg-white p-4 md:grid-cols-3 dark:border-neutral-700 dark:bg-neutral-900">
        <flux:input wire:model.live.debounce.300ms="search" :label="__('Search')" :placeholder="__('Name or employee number')" />
        <flux:select wire:model.live="status" :label="__('Status')"><option value="">{{ __('All statuses') }}</option>@foreach(['onboarding','active','suspended','notice','exited','archived'] as $value)<option value="{{ $value }}">{{ Str::headline($value) }}</option>@endforeach</flux:select>
        <flux:select wire:model.live="branch" :label="__('Branch')"><option value="">{{ __('All allowed branches') }}</option>@foreach($branches as $row)<option value="{{ $row->id }}">{{ $row->name }}</option>@endforeach</flux:select>
    </div>
    <div class="overflow-hidden rounded-lg border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
        <div class="overflow-x-auto"><table class="w-full text-sm"><thead class="bg-neutral-50 text-left text-xs uppercase text-neutral-500 dark:bg-neutral-800"><tr><th class="px-4 py-3">{{ __('Employee') }}</th><th class="px-4 py-3">{{ __('Branch') }}</th><th class="px-4 py-3">{{ __('Job title') }}</th><th class="px-4 py-3">{{ __('Status') }}</th><th class="px-4 py-3"></th></tr></thead><tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
            @forelse($employees as $employee)<tr><td class="px-4 py-3"><p class="font-medium">{{ $employee->display_name ?: trim($employee->legal_first_name.' '.$employee->legal_last_name) }}</p><p class="text-xs text-neutral-500">{{ $employee->employee_number }}</p></td><td class="px-4 py-3">{{ $branchNames[$employee->current_branch_id] ?? '—' }}</td><td class="px-4 py-3">{{ $employee->job_title ?? '—' }}</td><td class="px-4 py-3"><span class="rounded-full bg-neutral-100 px-2 py-1 text-xs dark:bg-neutral-800">{{ Str::headline($employee->employment_status instanceof BackedEnum ? $employee->employment_status->value : $employee->employment_status) }}</span></td><td class="px-4 py-3 text-right"><flux:button :href="route('hr.employees.show', $employee)" wire:navigate size="xs" variant="ghost">{{ __('Open') }}</flux:button></td></tr>
            @empty<tr><td colspan="5" class="px-4 py-10 text-center text-neutral-500">{{ __('No employees match these filters.') }}</td></tr>@endforelse
        </tbody></table></div><div class="border-t border-neutral-200 p-4 dark:border-neutral-700">{{ $employees->links() }}</div>
    </div>

    @if(auth()->user()?->hasRole('admin') || auth()->user()?->can('hr.employees.manage'))
        <flux:modal name="directory-add-employee-modal" focusable class="max-w-4xl">
            <form wire:submit="createEmployee" class="space-y-6">
                <div><flux:heading size="lg">{{ __('Add employee') }}</flux:heading><flux:subheading>{{ __('Create the legal identity and starting assignment. The employee number is generated automatically.') }}</flux:subheading></div>
                <section class="space-y-4">
                    <h2 class="font-semibold">{{ __('Identity') }}</h2>
                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <flux:input wire:model="legal_first_name" :label="__('Legal first name')" required />
                        <flux:input wire:model="legal_middle_name" :label="__('Legal middle name')" />
                        <flux:input wire:model="legal_last_name" :label="__('Legal last name')" required />
                        <flux:input wire:model="display_name" :label="__('Display name')" />
                        <flux:input wire:model="personal_phone" :label="__('Personal phone')" />
                        <flux:input wire:model="work_email" type="email" :label="__('Work email')" />
                    </div>
                </section>
                <section class="space-y-4 border-t border-neutral-200 pt-5 dark:border-neutral-700">
                    <h2 class="font-semibold">{{ __('Initial employment') }}</h2>
                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <flux:select wire:model.live="company_id" :label="__('Legal company')" required>@foreach($companies as $row)<option value="{{ $row->id }}">{{ $row->name }}</option>@endforeach</flux:select>
                        <flux:select wire:model="current_branch_id" :label="__('Primary branch')" required><option value="">{{ __('Choose branch') }}</option>@foreach($creationBranches as $row)<option value="{{ $row->id }}">{{ $row->name }}</option>@endforeach</flux:select>
                        <flux:select wire:model="current_department_id" :label="__('Department')"><option value="">{{ __('No department') }}</option>@foreach($departments as $row)<option value="{{ $row->id }}">{{ $row->name }}</option>@endforeach</flux:select>
                        <flux:input wire:model="job_title" :label="__('Job title')" required />
                        <flux:input wire:model="hire_date" type="date" :label="__('Hire date')" required />
                        <flux:select wire:model="employment_status" :label="__('Starting status')"><option value="onboarding">{{ __('Onboarding') }}</option><option value="active">{{ __('Active') }}</option></flux:select>
                    </div>
                </section>
                <div class="flex justify-end gap-2"><flux:modal.close><flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close><flux:button type="submit" variant="primary" wire:loading.attr="disabled">{{ __('Create employee') }}</flux:button></div>
            </form>
        </flux:modal>
    @endif
</div>
