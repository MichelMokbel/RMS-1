<?php

use App\Models\HrEmployee;
use App\Models\HrLeaveRequest;
use App\Models\HrLeaveType;
use App\Services\HR\LeaveService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $view = 'queue';
    public ?int $employee_id = null;
    public ?int $leave_type_id = null;
    public string $start_date = '';
    public string $end_date = '';
    public string $start_portion = 'full';
    public string $end_portion = 'full';
    public string $reason = '';
    public string $decision_note = '';
    public ?int $reject_request_id = null;
    public ?int $adjust_employee_id = null;
    public ?int $adjust_leave_type_id = null;
    public string $adjust_days = '';
    public string $adjust_date = '';
    public string $adjust_note = '';

    public function mount(): void
    {
        $this->authorizeLeave('hr.leave.view');
        $this->adjust_date = now()->toDateString();
    }

    public function create(LeaveService $service): void
    {
        $this->authorizeLeave('hr.leave.manage');
        $data = $this->validate([
            'employee_id' => ['required', 'integer', 'exists:hr_employees,id'],
            'leave_type_id' => ['required', 'integer', 'exists:hr_leave_types,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'start_portion' => ['required', 'in:full,half'],
            'end_portion' => ['required', 'in:full,half'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $employee = HrEmployee::query()->whereIn('id', $this->visibleEmployeeIds())->findOrFail($data['employee_id']);
        $service->create($employee, collect($data)->except('employee_id')->all(), auth()->user());
        $this->reset(['employee_id', 'leave_type_id', 'start_date', 'end_date', 'reason']);
        $this->start_portion = $this->end_portion = 'full';
        $this->modal('hr-leave-create')->close();
        session()->flash('status', __('Leave request submitted for manager approval.'));
    }

    public function approve(int $id, LeaveService $service): void
    {
        $this->authorizeLeave('hr.leave.approve');
        $service->approve($this->requestForActor($id), auth()->user(), $this->decision_note ?: null);
        $this->decision_note = '';
        session()->flash('status', __('Leave approved.'));
    }

    public function openReject(int $id): void
    {
        $this->authorizeLeave('hr.leave.approve');
        $this->reject_request_id = $this->requestForActor($id)->id;
        $this->decision_note = '';
        $this->resetValidation('decision_note');
        $this->modal('hr-leave-reject')->show();
    }

    public function reject(LeaveService $service): void
    {
        $this->authorizeLeave('hr.leave.approve');
        $this->validate(['reject_request_id' => ['required', 'integer'], 'decision_note' => ['required', 'string', 'max:1000']]);
        $service->reject($this->requestForActor((int) $this->reject_request_id), auth()->user(), $this->decision_note);
        $this->reset(['reject_request_id', 'decision_note']);
        $this->modal('hr-leave-reject')->close();
        session()->flash('status', __('Leave rejected.'));
    }

    public function adjustBalance(LeaveService $service): void
    {
        $this->authorizeLeave('hr.leave.manage');
        $data = $this->validate([
            'adjust_employee_id' => ['required', 'integer', 'exists:hr_employees,id'],
            'adjust_leave_type_id' => ['required', 'integer', 'exists:hr_leave_types,id'],
            'adjust_days' => ['required', 'numeric', 'not_in:0', 'between:-365,365'],
            'adjust_date' => ['required', 'date'],
            'adjust_note' => ['required', 'string', 'max:1000'],
        ]);
        $employee = HrEmployee::query()->whereIn('id', $this->visibleEmployeeIds())->findOrFail($data['adjust_employee_id']);
        $leaveType = HrLeaveType::query()->where('company_id', $employee->company_id)->findOrFail($data['adjust_leave_type_id']);
        $service->adjustBalance($employee, $leaveType, (float) $data['adjust_days'], $data['adjust_date'], $data['adjust_note'], auth()->user());
        $this->reset(['adjust_employee_id', 'adjust_leave_type_id', 'adjust_days', 'adjust_note']);
        $this->adjust_date = now()->toDateString();
        $this->modal('hr-leave-balance-adjust')->close();
        session()->flash('status', __('Leave balance adjusted with an audit entry.'));
    }

    public function with(): array
    {
        $user = auth()->user();
        $allowed = $user?->allowedBranchIds() ?? [];
        $employeeScope = HrEmployee::query()->when(! $user?->hasRole('admin'), fn ($q) => $q->whereIn('current_branch_id', $allowed ?: [-1]));
        if ($user?->hasRole('manager') && ! $user->can('hr.leave.manage')) {
            $managerId = HrEmployee::query()->where('user_id', $user->id)->value('id');
            $employeeScope->where(fn ($q) => $q->where('manager_id', $managerId ?: -1)->orWhere('user_id', $user->id));
        }
        $employeeIds = (clone $employeeScope)->pluck('id');
        $companyIds = (clone $employeeScope)->pluck('company_id')->unique();
        $requests = HrLeaveRequest::query()->whereIn('employee_id', $employeeIds)->with(['employee', 'leaveType'])
            ->when($this->view === 'queue', fn ($q) => $q->where('status', 'pending_manager'))
            ->when($this->view === 'calendar', fn ($q) => $q->where('status', 'approved')->whereDate('end_date', '>=', now()->startOfMonth())->whereDate('start_date', '<=', now()->endOfMonth()))
            ->latest('start_date')->paginate(20);

        return ['requests' => $requests, 'employees' => $employeeScope->orderBy('display_name')->get(), 'leaveTypes' => HrLeaveType::query()->whereIn('company_id', $companyIds)->where('is_active', true)->with('company')->orderBy('name')->get()];
    }

    private function requestForActor(int $id): HrLeaveRequest
    {
        $allowed = auth()->user()?->allowedBranchIds() ?? [];
        return HrLeaveRequest::query()->whereHas('employee', fn ($q) => $q->when(! auth()->user()?->hasRole('admin'), fn ($q) => $q->whereIn('current_branch_id', $allowed ?: [-1])))->findOrFail($id);
    }

    private function visibleEmployeeIds()
    {
        $query = HrEmployee::query();
        if (! auth()->user()?->hasRole('admin')) $query->whereIn('current_branch_id', auth()->user()?->allowedBranchIds() ?: [-1]);
        return $query->pluck('id');
    }

    private function authorizeLeave(string $permission): void
    {
        $user = auth()->user();
        abort_unless($user && ($user->hasRole('admin') || $user->can($permission)), 403);
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div><h1 class="text-2xl font-semibold">{{ __('Leave management') }}</h1><p class="text-sm text-neutral-500">{{ __('Calendar-day balances and manager approvals.') }}</p></div>
        @if(auth()->user()?->hasRole('admin') || auth()->user()?->can('hr.leave.manage'))
            <div class="flex gap-2">
                <flux:modal.trigger name="hr-leave-balance-adjust"><flux:button variant="ghost" icon="adjustments-horizontal">{{ __('Adjust balance') }}</flux:button></flux:modal.trigger>
                <flux:modal.trigger name="hr-leave-create"><flux:button icon="plus">{{ __('Record leave') }}</flux:button></flux:modal.trigger>
            </div>
        @endif
    </div>
    @include('livewire.hr.partials.navigation')
    @if(session('status'))<div class="rounded-md bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>@endif

    <div class="flex gap-2"><flux:button wire:click="$set('view','queue')" :variant="$view === 'queue' ? 'primary' : 'ghost'">{{ __('Approval queue') }}</flux:button><flux:button wire:click="$set('view','calendar')" :variant="$view === 'calendar' ? 'primary' : 'ghost'">{{ __('This month calendar') }}</flux:button><flux:button wire:click="$set('view','history')" :variant="$view === 'history' ? 'primary' : 'ghost'">{{ __('History') }}</flux:button></div>
    <section class="overflow-hidden rounded-lg border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900"><div class="divide-y divide-neutral-100 dark:divide-neutral-800">@forelse($requests as $request)<div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4"><div><p class="font-medium">{{ $request->employee?->display_name ?? $request->employee?->employee_number }} · {{ $request->leaveType?->name }}</p><p class="text-sm text-neutral-500">{{ $request->start_date?->format('d M Y') }} — {{ $request->end_date?->format('d M Y') }} · {{ number_format((float) $request->requested_days, 1) }} {{ __('days') }}</p></div><div class="flex items-center gap-2"><span class="rounded-full bg-neutral-100 px-2 py-1 text-xs dark:bg-neutral-800">{{ Str::headline($request->status instanceof BackedEnum ? $request->status->value : $request->status) }}</span>@if($view === 'queue' && (auth()->user()?->hasRole('admin') || auth()->user()?->can('hr.leave.approve')))<flux:button wire:click="approve({{ $request->id }})" wire:confirm="{{ __('Approve this leave request?') }}" size="xs">{{ __('Approve') }}</flux:button><flux:button wire:click="openReject({{ $request->id }})" size="xs" variant="danger">{{ __('Reject') }}</flux:button>@endif</div></div>@empty<p class="px-5 py-10 text-center text-sm text-neutral-500">{{ __('No leave requests in this view.') }}</p>@endforelse</div><div class="border-t border-neutral-200 p-4 dark:border-neutral-700">{{ $requests->links() }}</div></section>

    <flux:modal name="hr-leave-create" focusable class="max-w-3xl">
        <form wire:submit="create" class="space-y-5">
            <div><flux:heading size="lg">{{ __('Record leave request') }}</flux:heading><flux:subheading>{{ __('Submit calendar-day leave for manager approval.') }}</flux:subheading></div>
            <div class="grid gap-4 md:grid-cols-2"><flux:select wire:model="employee_id" :label="__('Employee')"><option value="">{{ __('Choose employee') }}</option>@foreach($employees as $row)<option value="{{ $row->id }}">{{ $row->display_name ?: $row->employee_number }}</option>@endforeach</flux:select><flux:select wire:model="leave_type_id" :label="__('Leave type')"><option value="">{{ __('Choose type') }}</option>@foreach($leaveTypes as $row)<option value="{{ $row->id }}">{{ $row->name }} · {{ $row->company?->code }}</option>@endforeach</flux:select><flux:input wire:model="start_date" type="date" :label="__('Start date')" /><flux:select wire:model="start_portion" :label="__('Start day')"><option value="full">{{ __('Full') }}</option><option value="half">{{ __('Half') }}</option></flux:select><flux:input wire:model="end_date" type="date" :label="__('End date')" /><flux:select wire:model="end_portion" :label="__('End day')"><option value="full">{{ __('Full') }}</option><option value="half">{{ __('Half') }}</option></flux:select><flux:textarea wire:model="reason" :label="__('Reason')" class="md:col-span-2" /></div>
            <div class="flex justify-end gap-2"><flux:modal.close><flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close><flux:button type="submit" variant="primary">{{ __('Submit request') }}</flux:button></div>
        </form>
    </flux:modal>

    <flux:modal name="hr-leave-balance-adjust" focusable class="max-w-2xl">
        <form wire:submit="adjustBalance" class="space-y-5">
            <div><flux:heading size="lg">{{ __('Adjust leave balance') }}</flux:heading><flux:subheading>{{ __('Use positive days for credits and negative days for corrections. The entry is append-only and audited.') }}</flux:subheading></div>
            <div class="grid gap-4 md:grid-cols-2"><flux:select wire:model="adjust_employee_id" :label="__('Employee')"><option value="">{{ __('Choose employee') }}</option>@foreach($employees as $row)<option value="{{ $row->id }}">{{ $row->display_name ?: $row->employee_number }}</option>@endforeach</flux:select><flux:select wire:model="adjust_leave_type_id" :label="__('Leave type')"><option value="">{{ __('Choose type') }}</option>@foreach($leaveTypes as $row)<option value="{{ $row->id }}">{{ $row->name }} · {{ $row->company?->code }}</option>@endforeach</flux:select><x-number-input wire:model="adjust_days" type="number" :label="__('Days (+/-)')" /><flux:input wire:model="adjust_date" type="date" :label="__('Effective date')" /><flux:textarea wire:model="adjust_note" :label="__('Audit note')" class="md:col-span-2" /></div>
            <div class="flex justify-end gap-2"><flux:modal.close><flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close><flux:button type="submit" variant="primary">{{ __('Record adjustment') }}</flux:button></div>
        </form>
    </flux:modal>

    <flux:modal name="hr-leave-reject" focusable class="max-w-lg">
        <form wire:submit="reject" class="space-y-5">
            <div><flux:heading size="lg">{{ __('Reject leave request') }}</flux:heading><flux:subheading>{{ __('Give the employee a clear reason for the decision.') }}</flux:subheading></div>
            <flux:textarea wire:model="decision_note" :label="__('Rejection reason')" required />
            <div class="flex justify-end gap-2"><flux:modal.close><flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close><flux:button type="submit" variant="danger">{{ __('Reject request') }}</flux:button></div>
        </form>
    </flux:modal>
</div>
