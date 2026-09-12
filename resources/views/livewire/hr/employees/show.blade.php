<?php

use App\Models\HrCompensationPackage;
use App\Models\HrDocument;
use App\Models\HrDocumentType;
use App\Models\HrEmployee;
use App\Models\HrEmployeeAssignment;
use App\Models\HrLeaveLedgerEntry;
use App\Models\HrLeaveRequest;
use App\Models\HrPayrollResult;
use App\Services\HR\CompensationService;
use App\Services\HR\EmployeeDocumentService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.app')] class extends Component
{
    use WithFileUploads;

    public int $employeeId;

    public string $tab = 'overview';

    public $document_file;

    public ?int $document_type_id = null;

    public string $issue_date = '';

    public string $expiry_date = '';

    public string $issuing_authority = '';

    public string $comp_effective_from = '';

    public string $basic_salary = '';

    public string $housing_allowance = '0';

    public string $transport_allowance = '0';

    public string $food_allowance = '0';

    public string $other_allowance = '0';

    public string $bank_name = '';

    public string $iban = '';

    public function mount(int $employee): void
    {
        $this->employeeId = $employee;
        $requested = (string) request()->query('tab', 'overview');
        $this->tab = in_array($requested, $this->allowedTabs(), true) ? $requested : 'overview';
        $this->employee();
        $this->comp_effective_from = now()->startOfMonth()->toDateString();
    }

    public function setTab(string $tab): void
    {
        abort_unless(in_array($tab, $this->allowedTabs(), true), 403);
        $this->tab = $tab;
    }

    public function uploadDocument(EmployeeDocumentService $service): void
    {
        abort_unless(auth()->user()?->hasRole('admin') || auth()->user()?->can('hr.documents.manage'), 403);
        $data = $this->validate([
            'document_type_id' => ['required', 'integer', 'exists:hr_document_types,id'],
            'document_file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:'.(int) config('hr.documents.max_size_kb', 10240)],
            'issue_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'issuing_authority' => ['nullable', 'string', 'max:150'],
        ]);
        $type = HrDocumentType::query()->where('company_id', $this->employee()->company_id)->findOrFail($data['document_type_id']);
        $service->store($this->employee(), $type, $this->document_file, collect($data)->except(['document_type_id', 'document_file'])->map(fn ($value) => $value === '' ? null : $value)->all(), auth()->user());
        $this->reset(['document_file', 'document_type_id', 'issue_date', 'expiry_date', 'issuing_authority']);
        $this->dispatch('modal-close', name: 'upload-employee-document-modal');
        session()->flash('status', __('Document uploaded and scanned.'));
    }

    public function createCompensation(CompensationService $service): void
    {
        abort_unless(auth()->user()?->hasRole('admin') || auth()->user()?->can('hr.payroll.prepare'), 403);
        $data = $this->validate([
            'comp_effective_from' => ['required', 'date'], 'basic_salary' => ['required', 'numeric', 'min:0.01'],
            'housing_allowance' => ['required', 'numeric', 'min:0'], 'transport_allowance' => ['required', 'numeric', 'min:0'],
            'food_allowance' => ['required', 'numeric', 'min:0'], 'other_allowance' => ['required', 'numeric', 'min:0'],
            'bank_name' => ['nullable', 'string', 'max:150'], 'iban' => ['nullable', 'string', 'max:100'],
        ]);
        $components = collect([
            ['code' => 'basic', 'name' => 'Basic Salary', 'category' => 'basic', 'amount' => $data['basic_salary']],
            ['code' => 'housing', 'name' => 'Housing Allowance', 'category' => 'allowance', 'amount' => $data['housing_allowance']],
            ['code' => 'transport', 'name' => 'Transport Allowance', 'category' => 'allowance', 'amount' => $data['transport_allowance']],
            ['code' => 'food', 'name' => 'Food Allowance', 'category' => 'allowance', 'amount' => $data['food_allowance']],
            ['code' => 'other', 'name' => 'Other Allowance', 'category' => 'allowance', 'amount' => $data['other_allowance']],
        ])->filter(fn ($row) => (float) $row['amount'] > 0)->map(fn ($row) => [...$row, 'amount_minor' => (int) round(((float) $row['amount']) * 100)])->map(fn ($row) => collect($row)->except('amount')->all())->values()->all();
        $service->createPackage($this->employee(), [
            'effective_from' => $data['comp_effective_from'], 'currency' => 'QAR', 'pay_frequency' => 'monthly',
            'proration_divisor' => config('hr.proration_divisor', 30), 'bank_name' => $data['bank_name'] ?: null,
            'beneficiary_name' => $this->employee()->display_name, 'iban' => $data['iban'] ?: null, 'is_active' => true,
        ], $components, auth()->user());
        $this->reset(['basic_salary', 'housing_allowance', 'transport_allowance', 'food_allowance', 'other_allowance', 'bank_name', 'iban']);
        $this->comp_effective_from = now()->startOfMonth()->toDateString();
        $this->dispatch('modal-close', name: 'create-employee-compensation-modal');
        session()->flash('status', __('Compensation package created.'));
    }

    public function with(): array
    {
        $employee = $this->employee();

        return [
            'employee' => $employee,
            'tabs' => $this->allowedTabs(),
            'canViewPayroll' => $this->canViewPayroll(),
            'canManageCompensation' => (bool) (auth()->user()?->hasRole('admin') || auth()->user()?->can('hr.payroll.prepare')),
            'assignments' => HrEmployeeAssignment::query()->where('employee_id', $employee->id)->latest('effective_from')->get(),
            'compensation' => $this->canViewPayroll() ? HrCompensationPackage::query()->where('employee_id', $employee->id)->with('components')->latest('effective_from')->get() : collect(),
            'documents' => $this->canViewDocuments() ? HrDocument::query()->where('employee_id', $employee->id)->with(['documentType', 'currentVersion'])->latest()->get() : collect(),
            'documentTypes' => $this->canViewDocuments() ? HrDocumentType::query()->where('company_id', $employee->company_id)->where('is_active', true)->orderBy('name')->get() : collect(),
            'leaveRequests' => HrLeaveRequest::query()->where('employee_id', $employee->id)->with('leaveType')->latest('start_date')->limit(30)->get(),
            'leaveBalances' => HrLeaveLedgerEntry::query()->where('employee_id', $employee->id)->selectRaw('leave_type_id, SUM(days) as balance')->groupBy('leave_type_id')->with('leaveType')->get(),
            'payrollResults' => $this->canViewPayroll() ? HrPayrollResult::query()->where('employee_id', $employee->id)->with('payrollRun')->latest()->limit(24)->get() : collect(),
        ];
    }

    private function employee(): HrEmployee
    {
        $user = auth()->user();
        abort_unless($user && ($user->hasRole('admin') || $user->can('hr.employees.view')), 403);
        $query = HrEmployee::query();
        if (! $user->hasRole('admin')) {
            $query->whereIn('current_branch_id', $user->allowedBranchIds() ?: [-1]);
            if ($user->hasRole('manager') && ! $user->can('hr.employees.manage')) {
                $managerEmployeeId = HrEmployee::query()->where('user_id', $user->id)->value('id');
                $query->where(fn ($q) => $q->where('manager_id', $managerEmployeeId ?: -1)->orWhere('user_id', $user->id));
            }
        }

        return $query->findOrFail($this->employeeId);
    }

    private function canViewDocuments(): bool
    {
        return (bool) (auth()->user()?->hasRole('admin') || auth()->user()?->can('hr.documents.view'));
    }

    private function canViewPayroll(): bool
    {
        return (bool) (auth()->user()?->hasRole('admin') || auth()->user()?->can('hr.payroll.view'));
    }

    private function allowedTabs(): array
    {
        return array_values(array_filter(['overview', 'employment', $this->canViewDocuments() ? 'documents' : null, 'leave', $this->canViewPayroll() ? 'payroll' : null]));
    }
}; ?>

<div class="app-page space-y-6">
    @if(session('status'))<div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>@endif
    <div class="flex flex-wrap items-start justify-between gap-3"><div><p class="text-sm text-neutral-500">{{ $employee->employee_number }}</p><h1 class="text-2xl font-semibold">{{ $employee->display_name ?: trim($employee->legal_first_name.' '.$employee->legal_last_name) }}</h1><p class="text-sm text-neutral-500">{{ $employee->job_title ?? __('No job title') }}</p></div><div class="flex gap-2">@if(auth()->user()?->hasRole('admin') || auth()->user()?->can('hr.employees.manage'))<flux:button :href="route('hr.employees.edit', $employee)" wire:navigate variant="ghost" icon="pencil-square">{{ __('Edit') }}</flux:button>@endif<flux:button :href="route('hr.employees.index')" wire:navigate variant="ghost" icon="arrow-left">{{ __('Directory') }}</flux:button></div></div>
    @include('livewire.hr.partials.navigation')
    <div class="flex flex-wrap gap-2 border-b border-neutral-200 dark:border-neutral-700">@foreach($tabs as $item)<button type="button" wire:click="setTab('{{ $item }}')" @class(['border-b-2 px-3 py-2 text-sm font-medium', 'border-neutral-900 text-neutral-900 dark:border-white dark:text-white' => $tab === $item, 'border-transparent text-neutral-500' => $tab !== $item])>{{ Str::headline($item) }}</button>@endforeach</div>

    @if($tab === 'overview')
        @php($emergency = $employee->emergency_contact ?? [])<div class="grid gap-4 lg:grid-cols-3"><section class="rounded-lg border border-neutral-200 bg-white p-5 lg:col-span-2 dark:border-neutral-700 dark:bg-neutral-900"><h2 class="mb-4 font-semibold">{{ __('Personal details') }}</h2><dl class="grid gap-4 text-sm sm:grid-cols-2"><div><dt class="text-neutral-500">{{ __('Legal name') }}</dt><dd>{{ trim($employee->legal_first_name.' '.$employee->legal_middle_name.' '.$employee->legal_last_name) }}</dd></div><div><dt class="text-neutral-500">{{ __('Nationality') }}</dt><dd>{{ $employee->nationality ?? '—' }}</dd></div><div><dt class="text-neutral-500">{{ __('Work email') }}</dt><dd>{{ $employee->work_email ?? '—' }}</dd></div><div><dt class="text-neutral-500">{{ __('Personal phone') }}</dt><dd>{{ $employee->personal_phone ?? '—' }}</dd></div><div><dt class="text-neutral-500">{{ __('Hire date') }}</dt><dd>{{ $employee->hire_date?->format('d M Y') ?? '—' }}</dd></div><div><dt class="text-neutral-500">{{ __('Status') }}</dt><dd>{{ Str::headline($employee->employment_status instanceof BackedEnum ? $employee->employment_status->value : $employee->employment_status) }}</dd></div></dl></section><section class="rounded-lg border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-neutral-900"><h2 class="font-semibold">{{ __('Emergency contact') }}</h2><p class="mt-4 font-medium">{{ $emergency['name'] ?? __('Not recorded') }}</p><p class="text-sm text-neutral-500">{{ $emergency['relationship'] ?? '' }}</p><p class="mt-2 text-sm">{{ $emergency['phone'] ?? '' }}</p></section></div>
    @elseif($tab === 'employment')
        <div @class(['grid gap-4', 'lg:grid-cols-2' => $canViewPayroll])><section class="rounded-lg border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-neutral-900"><h2 class="mb-4 font-semibold">{{ __('Assignment history') }}</h2><div class="space-y-3">@forelse($assignments as $row)<div class="rounded-md border border-neutral-200 p-3 text-sm dark:border-neutral-700"><p class="font-medium">{{ $row->job_title ?? __('Assignment') }}</p><p class="text-neutral-500">{{ $row->effective_from?->format('d M Y') }} — {{ $row->effective_to?->format('d M Y') ?? __('Current') }}</p></div>@empty<p class="text-sm text-neutral-500">{{ __('No assignment history.') }}</p>@endforelse</div></section>@if($canViewPayroll)<section class="rounded-lg border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-neutral-900"><h2 class="mb-4 font-semibold">{{ __('Compensation history') }}</h2><div class="space-y-3">@forelse($compensation as $row)@php($basic = $row->components->firstWhere('category', 'basic')?->amount_minor ?? 0)<div class="rounded-md border border-neutral-200 p-3 text-sm dark:border-neutral-700"><div class="flex justify-between"><span>{{ $row->effective_from?->format('d M Y') }}</span><strong>{{ number_format(((int) $basic) / 100, 2) }} QAR</strong></div><p class="text-neutral-500">{{ __('Basic monthly salary') }}</p></div>@empty<p class="text-sm text-neutral-500">{{ __('No compensation packages.') }}</p>@endforelse</div></section>@endif</div>
        @if($canManageCompensation)
            <div class="flex justify-end"><flux:modal.trigger name="create-employee-compensation-modal"><flux:button icon="plus">{{ __('New compensation package') }}</flux:button></flux:modal.trigger></div>
            <flux:modal name="create-employee-compensation-modal" focusable class="max-w-4xl">
                <form wire:submit="createCompensation" class="space-y-6">
                    <div><flux:heading size="lg">{{ __('New compensation package') }}</flux:heading><flux:subheading>{{ __('Record an effective-dated package. Salary amounts are stored in minor units.') }}</flux:subheading></div>
                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                        <flux:input wire:model="comp_effective_from" type="date" :label="__('Effective from')" />
                        <x-number-input wire:model="basic_salary" type="number" :label="__('Basic salary (QAR)')" />
                        <x-number-input wire:model="housing_allowance" type="number" :label="__('Housing allowance')" />
                        <x-number-input wire:model="transport_allowance" type="number" :label="__('Transport allowance')" />
                        <x-number-input wire:model="food_allowance" type="number" :label="__('Food allowance')" />
                        <x-number-input wire:model="other_allowance" type="number" :label="__('Other allowance')" />
                        <flux:input wire:model="bank_name" :label="__('Bank name')" />
                        <flux:input wire:model="iban" :label="__('IBAN')" />
                    </div>
                    <div class="flex justify-end gap-2"><flux:modal.close><flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close><flux:button type="submit" variant="primary">{{ __('Create package') }}</flux:button></div>
                </form>
            </flux:modal>
        @endif
    @elseif($tab === 'documents')
        @if(auth()->user()?->hasRole('admin') || auth()->user()?->can('hr.documents.manage'))
            <div class="flex justify-end"><flux:modal.trigger name="upload-employee-document-modal"><flux:button icon="arrow-up-tray">{{ __('Upload secure document') }}</flux:button></flux:modal.trigger></div>
            <flux:modal name="upload-employee-document-modal" focusable class="max-w-3xl">
                <form wire:submit="uploadDocument" class="space-y-6">
                    <div><flux:heading size="lg">{{ __('Upload secure document') }}</flux:heading><flux:subheading>{{ __('The HR document number is assigned automatically. The file must pass its security scan before it is stored.') }}</flux:subheading></div>
                    <div class="grid gap-4 md:grid-cols-2">
                        <flux:select wire:model="document_type_id" :label="__('Document type')"><option value="">{{ __('Choose type') }}</option>@foreach($documentTypes as $row)<option value="{{ $row->id }}">{{ $row->name }}</option>@endforeach</flux:select>
                        <flux:input wire:model="document_file" type="file" accept="application/pdf,image/jpeg,image/png" :label="__('PDF or image')" />
                        <flux:input wire:model="issue_date" type="date" :label="__('Issue date')" />
                        <flux:input wire:model="expiry_date" type="date" :label="__('Expiry date')" />
                        <flux:input wire:model="issuing_authority" :label="__('Issuing authority')" />
                    </div>
                    <div class="flex justify-end gap-2"><flux:modal.close><flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close><flux:button type="submit" variant="primary" wire:loading.attr="disabled">{{ __('Upload and scan') }}</flux:button></div>
                </form>
            </flux:modal>
        @endif
        <section class="overflow-hidden rounded-lg border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900"><div class="border-b border-neutral-200 px-5 py-4 dark:border-neutral-700"><h2 class="font-semibold">{{ __('Secure document vault') }}</h2><p class="text-sm text-neutral-500">{{ __('All access to sensitive files is audited.') }}</p></div><div class="divide-y divide-neutral-100 dark:divide-neutral-800">@forelse($documents as $document)@php($number = (string) ($document->document_number ?? ''))<div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4"><div><p class="font-medium">{{ $document->documentType?->name ?? __('Employee document') }}</p><p class="text-sm text-neutral-500">{{ $number !== '' ? $number : __('Number unavailable') }} · {{ $document->expiry_date?->format('d M Y') ?? __('No expiry') }}</p></div><div class="flex gap-2"><span class="rounded-full bg-neutral-100 px-2 py-1 text-xs dark:bg-neutral-800">{{ Str::headline($document->status instanceof BackedEnum ? $document->status->value : $document->status) }}</span>@if(auth()->user()?->hasRole('admin') || auth()->user()?->can('hr.documents.download'))<flux:button :href="route('hr.documents.preview', $document)" target="_blank" size="xs" variant="ghost">{{ __('Preview') }}</flux:button><flux:button :href="route('hr.documents.download', $document)" size="xs" variant="ghost">{{ __('Download') }}</flux:button>@endif</div></div>@empty<p class="px-5 py-10 text-center text-sm text-neutral-500">{{ __('No documents uploaded.') }}</p>@endforelse</div></section>
    @elseif($tab === 'leave')
        <div class="grid gap-4 lg:grid-cols-3"><section class="rounded-lg border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-neutral-900"><h2 class="mb-4 font-semibold">{{ __('Balances') }}</h2>@forelse($leaveBalances as $row)<div class="flex justify-between border-b border-neutral-100 py-2 text-sm dark:border-neutral-800"><span>{{ $row->leaveType?->name ?? __('Leave') }}</span><strong>{{ number_format((float) $row->balance, 2) }} {{ __('days') }}</strong></div>@empty<p class="text-sm text-neutral-500">{{ __('No balance entries.') }}</p>@endforelse</section><section class="rounded-lg border border-neutral-200 bg-white p-5 lg:col-span-2 dark:border-neutral-700 dark:bg-neutral-900"><h2 class="mb-4 font-semibold">{{ __('Recent leave') }}</h2>@forelse($leaveRequests as $row)<div class="flex justify-between border-b border-neutral-100 py-3 text-sm dark:border-neutral-800"><div><p class="font-medium">{{ $row->leaveType?->name ?? __('Leave') }}</p><p class="text-neutral-500">{{ $row->start_date?->format('d M Y') }} — {{ $row->end_date?->format('d M Y') }}</p></div><span>{{ Str::headline($row->status instanceof BackedEnum ? $row->status->value : $row->status) }}</span></div>@empty<p class="text-sm text-neutral-500">{{ __('No leave requests.') }}</p>@endforelse</section></div>
    @else
        <section class="overflow-hidden rounded-lg border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900"><div class="border-b border-neutral-200 px-5 py-4 dark:border-neutral-700"><h2 class="font-semibold">{{ __('Payroll history') }}</h2></div><div class="divide-y divide-neutral-100 dark:divide-neutral-800">@forelse($payrollResults as $row)<div class="flex justify-between px-5 py-4 text-sm"><div><p class="font-medium">{{ $row->payrollRun?->description ?? __('Payroll run') }}</p><p class="text-neutral-500">{{ $row->payrollRun?->pay_period_start?->format('M Y') }}</p></div><div class="text-right"><p class="font-semibold">{{ number_format(((int) $row->net_minor) / 100, 2) }} QAR</p><flux:button :href="route('hr.payroll.payslip', $row)" target="_blank" size="xs" variant="ghost">{{ __('Payslip PDF') }}</flux:button></div></div>@empty<p class="px-5 py-10 text-center text-sm text-neutral-500">{{ __('No payroll results.') }}</p>@endforelse</div></section>
    @endif
</div>
