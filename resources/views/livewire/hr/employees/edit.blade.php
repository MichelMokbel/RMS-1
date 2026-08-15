<?php

use App\Models\Branch;
use App\Models\Department;
use App\Models\HrEmployee;
use App\Services\HR\EmployeeService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public int $employeeId;

    public string $legal_first_name = '';

    public string $legal_middle_name = '';

    public string $legal_last_name = '';

    public string $display_name = '';

    public string $preferred_name = '';

    public string $work_email = '';

    public string $personal_email = '';

    public string $work_phone = '';

    public string $personal_phone = '';

    public string $date_of_birth = '';

    public string $nationality = '';

    public string $gender = '';

    public string $qid_number = '';

    public string $passport_number = '';

    public string $emergency_name = '';

    public string $emergency_relationship = '';

    public string $emergency_phone = '';

    public ?int $branch_id = null;

    public ?int $department_id = null;

    public ?int $manager_id = null;

    public string $job_title = '';

    public string $effective_from = '';

    public string $next_status = '';

    public string $status_reason = '';

    public function mount(int $employee): void
    {
        $this->employeeId = $employee;
        $row = $this->employee();
        foreach (['legal_first_name', 'legal_middle_name', 'legal_last_name', 'display_name', 'preferred_name',
            'work_email', 'personal_email', 'work_phone', 'personal_phone', 'nationality', 'gender',
            'qid_number', 'passport_number'] as $field) {
            $this->{$field} = (string) ($row->{$field} ?? '');
        }
        $this->date_of_birth = $row->date_of_birth?->toDateString() ?? '';
        $this->emergency_name = (string) data_get($row->emergency_contact, 'name', '');
        $this->emergency_relationship = (string) data_get($row->emergency_contact, 'relationship', '');
        $this->emergency_phone = (string) data_get($row->emergency_contact, 'phone', '');
        $this->branch_id = $row->current_branch_id;
        $this->department_id = $row->current_department_id;
        $this->manager_id = $row->manager_id;
        $this->job_title = (string) $row->job_title;
        $this->effective_from = now()->toDateString();
    }

    public function saveProfile(EmployeeService $service): void
    {
        $data = $this->validate([
            'legal_first_name' => ['required', 'string', 'max:100'], 'legal_middle_name' => ['nullable', 'string', 'max:100'],
            'legal_last_name' => ['required', 'string', 'max:100'], 'display_name' => ['required', 'string', 'max:200'],
            'preferred_name' => ['nullable', 'string', 'max:100'], 'work_email' => ['nullable', 'email', 'max:255'],
            'personal_email' => ['nullable', 'email', 'max:255'], 'work_phone' => ['nullable', 'string', 'max:40'],
            'personal_phone' => ['nullable', 'string', 'max:40'], 'date_of_birth' => ['nullable', 'date', 'before:today'],
            'nationality' => ['nullable', 'string', 'max:100'], 'gender' => ['nullable', 'string', 'max:30'],
            'qid_number' => ['nullable', 'string', 'max:50'], 'passport_number' => ['nullable', 'string', 'max:50'],
            'emergency_name' => ['nullable', 'string', 'max:150'], 'emergency_relationship' => ['nullable', 'string', 'max:100'],
            'emergency_phone' => ['nullable', 'string', 'max:40'],
        ]);
        $emergency = ['name' => $data['emergency_name'], 'relationship' => $data['emergency_relationship'], 'phone' => $data['emergency_phone']];
        $service->update($this->employee(), [...collect($data)->except(['emergency_name', 'emergency_relationship', 'emergency_phone'])->map(fn ($value) => $value === '' ? null : $value)->all(),
            'emergency_contact' => collect($emergency)->filter(fn ($value) => filled($value))->all(), 'updated_by' => auth()->id()], (int) auth()->id());
        $this->dispatch('modal-close', name: 'edit-employee-profile-modal');
        session()->flash('status', __('Employee profile updated.'));
    }

    public function transfer(EmployeeService $service): void
    {
        $data = $this->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'], 'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'manager_id' => ['nullable', 'integer', 'exists:hr_employees,id'], 'job_title' => ['required', 'string', 'max:150'],
            'effective_from' => ['required', 'date', 'before_or_equal:today'],
        ]);
        $service->assign($this->employee(), [...$data, 'employment_type' => $this->employee()->employment_type], (int) auth()->id());
        $this->dispatch('modal-close', name: 'record-employee-assignment-modal');
        session()->flash('status', __('New assignment recorded.'));
    }

    public function transition(EmployeeService $service): void
    {
        $data = $this->validate(['next_status' => ['required', 'in:active,suspended,notice,exited,archived'], 'status_reason' => ['required', 'string', 'max:1000']]);
        $service->transition($this->employee(), $data['next_status'], (int) auth()->id(), $data['status_reason']);
        $this->reset(['next_status', 'status_reason']);
        $this->dispatch('modal-close', name: 'change-employee-status-modal');
        session()->flash('status', __('Employment status updated.'));
    }

    public function with(): array
    {
        $employee = $this->employee();

        return [
            'employee' => $employee,
            'branches' => Branch::query()->where('company_id', $employee->company_id)->where('is_active', true)->orderBy('name')->get(),
            'departments' => Department::query()->where('company_id', $employee->company_id)->orderBy('name')->get(),
            'managers' => HrEmployee::query()->where('company_id', $employee->company_id)->whereKeyNot($employee->id)->whereIn('employment_status', ['active', 'notice'])->orderBy('display_name')->get(),
        ];
    }

    private function employee(): HrEmployee
    {
        $user = auth()->user();
        abort_unless($user && ($user->hasRole('admin') || $user->can('hr.employees.manage')), 403);

        return HrEmployee::query()->when(! $user->hasRole('admin'), fn ($query) => $query->whereIn('current_branch_id', $user->allowedBranchIds() ?: [-1]))->findOrFail($this->employeeId);
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold">{{ __('Manage employee') }}</h1>
            <p class="text-sm text-neutral-500">{{ $employee->employee_number }} · {{ $employee->display_name }}</p>
        </div>
        <flux:button :href="route('hr.employees.show', $employee)" wire:navigate variant="ghost" icon="arrow-left">
            {{ __('Back to profile') }}
        </flux:button>
    </div>

    @include('livewire.hr.partials.navigation')
    @if(session('status'))<div class="rounded-md bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>@endif

    <div class="divide-y divide-neutral-200 overflow-hidden rounded-lg border border-neutral-200 bg-white dark:divide-neutral-700 dark:border-neutral-700 dark:bg-neutral-900">
        <div class="flex flex-wrap items-center justify-between gap-4 p-5">
            <div><h2 class="font-semibold">{{ __('Identity and contact') }}</h2><p class="text-sm text-neutral-500">{{ __('Legal identity, contact details and emergency contact.') }}</p></div>
            <flux:modal.trigger name="edit-employee-profile-modal"><flux:button icon="pencil-square">{{ __('Edit profile') }}</flux:button></flux:modal.trigger>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-4 p-5">
            <div><h2 class="font-semibold">{{ __('Assignment and reporting line') }}</h2><p class="text-sm text-neutral-500">{{ __('Record a branch transfer, department move, manager or role change.') }}</p></div>
            <flux:modal.trigger name="record-employee-assignment-modal"><flux:button icon="arrows-right-left">{{ __('Record assignment') }}</flux:button></flux:modal.trigger>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-4 bg-amber-50 p-5 dark:bg-amber-950">
            <div><h2 class="font-semibold">{{ __('Employment status') }}</h2><p class="text-sm text-neutral-600 dark:text-neutral-400">{{ __('Suspend, place on notice, exit or archive this employee with an audit reason.') }}</p></div>
            <flux:modal.trigger name="change-employee-status-modal"><flux:button variant="danger" icon="exclamation-triangle">{{ __('Change status') }}</flux:button></flux:modal.trigger>
        </div>
    </div>

    <flux:modal name="edit-employee-profile-modal" focusable class="max-w-5xl">
        <form wire:submit="saveProfile" class="space-y-6">
            <div><flux:heading size="lg">{{ __('Edit identity and contact') }}</flux:heading><flux:subheading>{{ __('Update the employee profile without changing the immutable employee number.') }}</flux:subheading></div>
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <flux:input wire:model="legal_first_name" :label="__('Legal first name')" />
                <flux:input wire:model="legal_middle_name" :label="__('Middle name')" />
                <flux:input wire:model="legal_last_name" :label="__('Legal last name')" />
                <flux:input wire:model="display_name" :label="__('Display name')" />
                <flux:input wire:model="preferred_name" :label="__('Preferred name')" />
                <flux:input wire:model="date_of_birth" type="date" :label="__('Date of birth')" />
                <flux:input wire:model="nationality" :label="__('Nationality')" />
                <flux:input wire:model="gender" :label="__('Gender')" />
                <flux:input wire:model="work_email" type="email" :label="__('Work email')" />
                <flux:input wire:model="personal_email" type="email" :label="__('Personal email')" />
                <flux:input wire:model="work_phone" :label="__('Work phone')" />
                <flux:input wire:model="personal_phone" :label="__('Personal phone')" />
                <flux:input wire:model="qid_number" :label="__('QID number')" />
                <flux:input wire:model="passport_number" :label="__('Passport number')" />
                <flux:input wire:model="emergency_name" :label="__('Emergency contact')" />
                <flux:input wire:model="emergency_relationship" :label="__('Relationship')" />
                <flux:input wire:model="emergency_phone" :label="__('Emergency phone')" />
            </div>
            <div class="flex justify-end gap-2"><flux:modal.close><flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close><flux:button type="submit" variant="primary">{{ __('Save profile') }}</flux:button></div>
        </form>
    </flux:modal>

    <flux:modal name="record-employee-assignment-modal" focusable class="max-w-3xl">
        <form wire:submit="transfer" class="space-y-6">
            <div><flux:heading size="lg">{{ __('Record assignment or transfer') }}</flux:heading><flux:subheading>{{ __('This adds a dated assignment record and preserves the employee history.') }}</flux:subheading></div>
            <div class="grid gap-4 md:grid-cols-2">
                <flux:select wire:model="branch_id" :label="__('Branch')">@foreach($branches as $row)<option value="{{ $row->id }}">{{ $row->name }}</option>@endforeach</flux:select>
                <flux:select wire:model="department_id" :label="__('Department')"><option value="">{{ __('None') }}</option>@foreach($departments as $row)<option value="{{ $row->id }}">{{ $row->name }}</option>@endforeach</flux:select>
                <flux:select wire:model="manager_id" :label="__('Manager')"><option value="">{{ __('None') }}</option>@foreach($managers as $row)<option value="{{ $row->id }}">{{ $row->display_name }}</option>@endforeach</flux:select>
                <flux:input wire:model="job_title" :label="__('Job title')" />
                <flux:input wire:model="effective_from" type="date" :label="__('Effective from')" />
            </div>
            <div class="flex justify-end gap-2"><flux:modal.close><flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close><flux:button type="submit" variant="primary">{{ __('Record assignment') }}</flux:button></div>
        </form>
    </flux:modal>

    <flux:modal name="change-employee-status-modal" focusable class="max-w-xl">
        <form wire:submit="transition" class="space-y-6">
            <div><flux:heading size="lg">{{ __('Change employment status') }}</flux:heading><flux:subheading>{{ __('This is an audited change. Add a clear reason before continuing.') }}</flux:subheading></div>
            <flux:select wire:model="next_status" :label="__('New status')"><option value="">{{ __('Choose status') }}</option>@foreach(['active','suspended','notice','exited','archived'] as $status)<option value="{{ $status }}">{{ Str::headline($status) }}</option>@endforeach</flux:select>
            <flux:textarea wire:model="status_reason" :label="__('Reason')" rows="4" />
            <div class="flex justify-end gap-2"><flux:modal.close><flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close><flux:button type="submit" variant="danger">{{ __('Change status') }}</flux:button></div>
        </form>
    </flux:modal>
</div>
