<?php

use App\Models\AccountingCompany;
use App\Models\BankAccount;
use App\Models\HrPayrollRun;
use App\Services\HR\PayrollRunService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public ?int $company_id = null;
    public string $period_start = '';
    public string $period_end = '';
    public string $name = '';
    public ?int $bank_account_id = null;
    public string $payment_date = '';
    public string $payment_reference = '';
    public string $action_note = '';
    public string $reversal_date = '';
    public ?int $selected_run_id = null;

    public function mount(): void
    {
        $this->authorizePayroll('hr.payroll.view');
        $this->company_id = AccountingCompany::query()->where('is_default', true)->value('id') ?? AccountingCompany::query()->value('id');
        $this->period_start = now()->startOfMonth()->toDateString();
        $this->period_end = now()->endOfMonth()->toDateString();
        $this->payment_date = now()->toDateString();
        $this->reversal_date = now()->toDateString();
    }

    public function create(PayrollRunService $service): void
    {
        $this->authorizePayroll('hr.payroll.prepare');
        $data = $this->validate([
            'company_id' => ['required', 'integer', 'exists:accounting_companies,id'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'name' => ['nullable', 'string', 'max:150'],
        ]);
        $service->create(['company_id' => $data['company_id'], 'pay_period_start' => $data['period_start'], 'pay_period_end' => $data['period_end'], 'description' => $data['name'] ?: null], auth()->user());
        $this->reset('name');
        $this->modal('hr-payroll-create')->close();
        session()->flash('status', __('Payroll run created.'));
    }

    public function calculate(int $id, PayrollRunService $service): void
    {
        $this->authorizePayroll('hr.payroll.prepare');
        $service->calculate($this->run($id), auth()->user());
        session()->flash('status', __('Payroll calculated. Review totals before approval.'));
    }

    public function openApprove(int $id): void
    {
        $this->authorizePayroll('hr.payroll.approve');
        $this->selected_run_id = $this->run($id)->id;
        $this->action_note = '';
        $this->resetValidation();
        $this->modal('hr-payroll-approve')->show();
    }

    public function approve(PayrollRunService $service): void
    {
        $this->authorizePayroll('hr.payroll.approve');
        $this->validate(['selected_run_id' => ['required', 'integer'], 'action_note' => ['nullable', 'string', 'max:1000']]);
        $service->approve($this->run((int) $this->selected_run_id), auth()->user(), $this->action_note ?: null);
        $this->reset(['selected_run_id', 'action_note']);
        $this->modal('hr-payroll-approve')->close();
        session()->flash('status', __('Payroll approved.'));
    }

    public function post(int $id, PayrollRunService $service): void
    {
        $this->authorizePayroll('hr.payroll.post');
        $service->post($this->run($id), auth()->user());
        $this->action_note = '';
        session()->flash('status', __('Payroll posted to accounting.'));
    }

    public function openPayment(int $id): void
    {
        $this->authorizePayroll('hr.payroll.pay');
        $run = $this->run($id);
        $this->selected_run_id = $run->id;
        $this->company_id = (int) $run->company_id;
        $this->reset(['bank_account_id', 'payment_reference']);
        $this->payment_date = now()->toDateString();
        $this->resetValidation();
        $this->modal('hr-payroll-payment')->show();
    }

    public function pay(PayrollRunService $service): void
    {
        $this->authorizePayroll('hr.payroll.pay');
        $data = $this->validate(['selected_run_id' => ['required', 'integer'], 'bank_account_id' => ['required', 'integer', 'exists:bank_accounts,id'], 'payment_date' => ['required', 'date'], 'payment_reference' => ['required', 'string', 'max:120']]);
        $service->pay($this->run((int) $this->selected_run_id), (int) $data['bank_account_id'], $data['payment_date'], $data['payment_reference'], auth()->user());
        $this->reset(['selected_run_id', 'bank_account_id', 'payment_reference']);
        $this->modal('hr-payroll-payment')->close();
        session()->flash('status', __('Payroll payment batch recorded.'));
    }

    public function openReversal(int $id): void
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);
        $this->selected_run_id = $this->run($id)->id;
        $this->action_note = '';
        $this->reversal_date = now()->toDateString();
        $this->resetValidation();
        $this->modal('hr-payroll-reversal')->show();
    }

    public function reverse(PayrollRunService $service): void
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);
        $this->validate(['selected_run_id' => ['required', 'integer'], 'reversal_date' => ['required', 'date'], 'action_note' => ['required', 'string', 'max:1000']]);
        $service->reverse($this->run((int) $this->selected_run_id), auth()->user(), $this->reversal_date, $this->action_note);
        $this->reset(['selected_run_id', 'action_note']);
        $this->reversal_date = now()->toDateString();
        $this->modal('hr-payroll-reversal')->close();
        session()->flash('status', __('Payroll reversed.'));
    }

    public function with(): array
    {
        $companyIds = $this->companyIds();
        return [
            'runs' => HrPayrollRun::query()->whereIn('company_id', $companyIds)->withCount('results')->withSum('results', 'net_minor')->latest('pay_period_start')->paginate(15),
            'companies' => AccountingCompany::query()->whereIn('id', $companyIds)->where('is_active', true)->orderBy('name')->get(),
            'banks' => BankAccount::query()->when($this->company_id, fn ($q) => $q->where('company_id', $this->company_id))->where('is_active', true)->orderBy('name')->get(),
        ];
    }

    private function run(int $id): HrPayrollRun { return HrPayrollRun::query()->whereIn('company_id', $this->companyIds())->findOrFail($id); }
    private function companyIds(): array
    {
        if (auth()->user()?->hasRole('admin')) return AccountingCompany::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $allowed = auth()->user()?->allowedBranchIds() ?: [];

        return \App\Models\Branch::query()
            ->where('is_active', true)
            ->get(['id', 'company_id'])
            ->groupBy('company_id')
            ->filter(fn ($branches) => $branches->every(fn ($branch) => in_array((int) $branch->id, $allowed, true)))
            ->keys()
            ->map(fn ($id) => (int) $id)
            ->all();
    }
    private function authorizePayroll(string $permission): void { $user = auth()->user(); abort_unless($user && ($user->hasRole('admin') || $user->can($permission)), 403); }
}; ?>

<div class="app-page space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3"><div><h1 class="text-2xl font-semibold">{{ __('Payroll runs') }}</h1><p class="text-sm text-neutral-500">{{ __('Prepare, approve, post and pay monthly QAR payroll.') }}</p></div>@if(auth()->user()?->hasRole('admin') || auth()->user()?->can('hr.payroll.prepare'))<flux:modal.trigger name="hr-payroll-create"><flux:button icon="plus">{{ __('New payroll run') }}</flux:button></flux:modal.trigger>@endif</div>
    @include('livewire.hr.partials.navigation')
    @if(session('status'))<div class="rounded-md bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>@endif
    <section class="space-y-3">@forelse($runs as $run)@php($status = $run->status instanceof BackedEnum ? $run->status->value : $run->status)<article class="rounded-lg border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-neutral-900"><div class="flex flex-wrap justify-between gap-3"><div><p class="text-xs uppercase text-neutral-500">{{ $run->run_number ?? __('Payroll run') }}</p><h2 class="font-semibold">{{ $run->description ?: $run->pay_period_start?->format('F Y') }}</h2><p class="text-sm text-neutral-500">{{ $run->pay_period_start?->format('d M Y') }} — {{ $run->pay_period_end?->format('d M Y') }} · {{ trans_choice(':count employee|:count employees', $run->results_count, ['count' => $run->results_count]) }}</p></div><div class="text-right"><span class="rounded-full bg-neutral-100 px-2 py-1 text-xs dark:bg-neutral-800">{{ Str::headline($status) }}</span><p class="mt-2 font-semibold">{{ number_format(((int) ($run->results_sum_net_minor ?? 0)) / 100, 2) }} QAR</p></div></div><div class="mt-4 flex flex-wrap gap-2">
        @if($status === 'draft' && (auth()->user()?->hasRole('admin') || auth()->user()?->can('hr.payroll.prepare')))<flux:button wire:click="calculate({{ $run->id }})" wire:confirm="{{ __('Calculate this payroll run?') }}" size="sm">{{ __('Calculate') }}</flux:button>@endif
        @if($status === 'calculated' && (auth()->user()?->hasRole('admin') || auth()->user()?->can('hr.payroll.approve')))<flux:button wire:click="openApprove({{ $run->id }})" size="sm">{{ __('Approve') }}</flux:button>@endif
        @if($status === 'approved' && (auth()->user()?->hasRole('admin') || auth()->user()?->can('hr.payroll.post')))<flux:button wire:click="post({{ $run->id }})" wire:confirm="{{ __('Post aggregate payroll journals?') }}" size="sm">{{ __('Post') }}</flux:button>@endif
        @if($status === 'posted' && (auth()->user()?->hasRole('admin') || auth()->user()?->can('hr.payroll.pay')))<flux:button wire:click="openPayment({{ $run->id }})" size="sm">{{ __('Record payment') }}</flux:button>@endif
        @if(in_array($status, ['posted','paid'], true) && auth()->user()?->hasRole('admin'))<flux:button wire:click="openReversal({{ $run->id }})" size="sm" variant="danger">{{ __('Reverse') }}</flux:button>@endif
    </div></article>@empty<p class="rounded-lg border border-neutral-200 bg-white px-5 py-10 text-center text-sm text-neutral-500 dark:border-neutral-700 dark:bg-neutral-900">{{ __('No payroll runs.') }}</p>@endforelse<div>{{ $runs->links() }}</div></section>

    <flux:modal name="hr-payroll-create" focusable class="max-w-2xl">
        <form wire:submit="create" class="space-y-5"><div><flux:heading size="lg">{{ __('New payroll run') }}</flux:heading><flux:subheading>{{ __('Create a complete monthly QAR payroll period.') }}</flux:subheading></div><div class="grid gap-4 md:grid-cols-2"><flux:select wire:model.live="company_id" :label="__('Company')">@foreach($companies as $row)<option value="{{ $row->id }}">{{ $row->name }}</option>@endforeach</flux:select><flux:input wire:model="name" :label="__('Description')" :placeholder="__('Optional')" /><flux:input wire:model="period_start" type="date" :label="__('Period start')" /><flux:input wire:model="period_end" type="date" :label="__('Period end')" /></div><div class="flex justify-end gap-2"><flux:modal.close><flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close><flux:button type="submit" variant="primary">{{ __('Create draft') }}</flux:button></div></form>
    </flux:modal>

    <flux:modal name="hr-payroll-approve" focusable class="max-w-lg">
        <form wire:submit="approve" class="space-y-5"><div><flux:heading size="lg">{{ __('Approve payroll') }}</flux:heading><flux:subheading>{{ __('Confirm the calculated totals after independent review.') }}</flux:subheading></div><flux:textarea wire:model="action_note" :label="__('Approval note')" :placeholder="__('Optional')" /><div class="flex justify-end gap-2"><flux:modal.close><flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close><flux:button type="submit" variant="primary">{{ __('Approve payroll') }}</flux:button></div></form>
    </flux:modal>

    <flux:modal name="hr-payroll-payment" focusable class="max-w-lg">
        <form wire:submit="pay" class="space-y-5"><div><flux:heading size="lg">{{ __('Record payroll payment') }}</flux:heading><flux:subheading>{{ __('Create the payable-to-bank entry and payment batch.') }}</flux:subheading></div><div class="space-y-4"><flux:select wire:model="bank_account_id" :label="__('Bank account')"><option value="">{{ __('Choose bank account') }}</option>@foreach($banks as $bank)<option value="{{ $bank->id }}">{{ $bank->name }}</option>@endforeach</flux:select><flux:input wire:model="payment_date" type="date" :label="__('Payment date')" /><flux:input wire:model="payment_reference" :label="__('Bank reference')" /></div><div class="flex justify-end gap-2"><flux:modal.close><flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close><flux:button type="submit" variant="primary">{{ __('Record payment') }}</flux:button></div></form>
    </flux:modal>

    <flux:modal name="hr-payroll-reversal" focusable class="max-w-lg">
        <form wire:submit="reverse" class="space-y-5"><div><flux:heading size="lg">{{ __('Reverse payroll') }}</flux:heading><flux:subheading>{{ __('Create exact reversal entries. This action remains permanently audited.') }}</flux:subheading></div><flux:input wire:model="reversal_date" type="date" :label="__('Reversal date')" /><flux:textarea wire:model="action_note" :label="__('Reversal reason')" required /><div class="flex justify-end gap-2"><flux:modal.close><flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close><flux:button type="submit" variant="danger">{{ __('Reverse payroll') }}</flux:button></div></form>
    </flux:modal>
</div>
