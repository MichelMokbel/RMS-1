<?php

use App\Models\AccountingCompany;
use App\Models\Branch;
use App\Models\Department;
use App\Services\HR\EmployeeService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
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
        abort_unless(auth()->user()?->hasRole('admin') || auth()->user()?->can('hr.employees.manage'), 403);
        $companyIds = auth()->user()?->hasRole('admin') ? AccountingCompany::query()->pluck('id') : Branch::query()->whereIn('id', auth()->user()?->allowedBranchIds() ?: [-1])->pluck('company_id')->unique();
        $this->company_id = AccountingCompany::query()->whereIn('id', $companyIds)->orderByDesc('is_default')->value('id');
    }

    public function save(EmployeeService $service): void
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
        $employee = $service->create($data, (int) auth()->id());
        $this->dispatch('modal-close', name: 'add-employee-modal');
        session()->flash('status', __('Employee created.'));
        $this->redirectRoute('hr.employees.show', $employee, navigate: true);
    }

    public function with(): array
    {
        $allowed = auth()->user()?->allowedBranchIds() ?? [];

        return [
            'companies' => AccountingCompany::query()->when(! auth()->user()?->hasRole('admin'), fn ($q) => $q->whereIn('id', Branch::query()->whereIn('id', $allowed ?: [-1])->select('company_id')))->where('is_active', true)->orderBy('name')->get(),
            'branches' => Branch::query()->when(! auth()->user()?->hasRole('admin'), fn ($q) => $q->whereIn('id', $allowed ?: [-1]))->when($this->company_id, fn ($q) => $q->where('company_id', $this->company_id))->where('is_active', true)->orderBy('name')->get(),
            'departments' => Department::query()->when($this->company_id, fn ($q) => $q->where('company_id', $this->company_id))->orderBy('name')->get(),
        ];
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold">{{ __('Employee directory') }}</h1>
            <p class="text-sm text-neutral-500">{{ __('Create a legal employee record and its initial assignment.') }}</p>
        </div>
        <flux:modal.trigger name="add-employee-modal">
            <flux:button variant="primary" icon="plus">{{ __('Add employee') }}</flux:button>
        </flux:modal.trigger>
    </div>

    @include('livewire.hr.partials.navigation')

    <section class="overflow-hidden rounded-lg border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
        <div class="flex flex-wrap items-center justify-between gap-4 px-5 py-5">
            <div>
                <h2 class="font-semibold">{{ __('New employee record') }}</h2>
                <p class="mt-1 text-sm text-neutral-500">{{ __('Employee numbers are assigned automatically after the record is saved.') }}</p>
            </div>
            <div class="flex gap-2">
                <flux:button :href="route('hr.employees.index')" wire:navigate variant="ghost" icon="users">
                    {{ __('View directory') }}
                </flux:button>
                <flux:modal.trigger name="add-employee-modal">
                    <flux:button icon="plus">{{ __('Add employee') }}</flux:button>
                </flux:modal.trigger>
            </div>
        </div>
    </section>

    <flux:modal name="add-employee-modal" focusable class="max-w-4xl">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Add employee') }}</flux:heading>
                <flux:subheading>{{ __('Create the legal identity and starting assignment. The employee number is generated automatically.') }}</flux:subheading>
            </div>

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
                    <flux:select wire:model.live="company_id" :label="__('Legal company')" required>
                        @foreach($companies as $row)<option value="{{ $row->id }}">{{ $row->name }}</option>@endforeach
                    </flux:select>
                    <flux:select wire:model="current_branch_id" :label="__('Primary branch')" required>
                        <option value="">{{ __('Choose branch') }}</option>
                        @foreach($branches as $row)<option value="{{ $row->id }}">{{ $row->name }}</option>@endforeach
                    </flux:select>
                    <flux:select wire:model="current_department_id" :label="__('Department')">
                        <option value="">{{ __('No department') }}</option>
                        @foreach($departments as $row)<option value="{{ $row->id }}">{{ $row->name }}</option>@endforeach
                    </flux:select>
                    <flux:input wire:model="job_title" :label="__('Job title')" required />
                    <flux:input wire:model="hire_date" type="date" :label="__('Hire date')" required />
                    <flux:select wire:model="employment_status" :label="__('Starting status')">
                        <option value="onboarding">{{ __('Onboarding') }}</option>
                        <option value="active">{{ __('Active') }}</option>
                    </flux:select>
                </div>
            </section>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled">{{ __('Create employee') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
