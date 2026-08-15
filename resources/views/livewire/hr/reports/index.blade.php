<?php

use App\Models\AccountingCompany;
use App\Models\Branch;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public ?int $company_id = null;
    public string $as_of = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->hasRole('admin') || auth()->user()?->can('hr.reports.view'), 403);
        $this->company_id = $this->companies()->sortByDesc('is_default')->first()?->id;
        $this->as_of = now()->toDateString();
    }

    public function with(): array { return ['companies' => $this->companies()]; }
    private function companies()
    {
        return AccountingCompany::query()->when(! auth()->user()?->hasRole('admin'), fn ($q) => $q->whereIn('id', Branch::query()->whereIn('id', auth()->user()?->allowedBranchIds() ?: [-1])->select('company_id')))->where('is_active', true)->orderBy('name')->get();
    }
}; ?>

<div class="app-page space-y-6"><div><h1 class="text-2xl font-semibold">{{ __('HR reports') }}</h1><p class="text-sm text-neutral-500">{{ __('Operational reporting without exposing salary detail outside payroll permissions.') }}</p></div>@include('livewire.hr.partials.navigation')
    <div class="grid gap-4 rounded-lg border border-neutral-200 bg-white p-5 sm:grid-cols-2 dark:border-neutral-700 dark:bg-neutral-900"><flux:select wire:model="company_id" :label="__('Company')">@foreach($companies as $row)<option value="{{ $row->id }}">{{ $row->name }}</option>@endforeach</flux:select><flux:input wire:model="as_of" type="date" :label="__('As of')" /></div>
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ([
            ['directory', 'Employee directory', 'Headcount, branch, department and employment status.'],
            ['leave-balances', 'Leave balances', 'Opening, earned, used and available calendar days.'],
            ['payroll-register', 'Payroll register', 'Employee gross, deductions and net pay for finance review.'],
        ] as [$key, $title, $description])
            @php($allowed = $key !== 'payroll-register' || auth()->user()?->hasRole('admin') || auth()->user()?->can('hr.payroll.export'))
            @if($allowed)<article class="rounded-lg border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-neutral-900"><h2 class="font-semibold">{{ __($title) }}</h2><p class="mt-2 min-h-10 text-sm text-neutral-500">{{ __($description) }}</p><div class="mt-4 flex gap-2"><flux:button :href="route('hr.reports.print', ['report' => $key, 'company_id' => $company_id, 'as_of' => $as_of])" target="_blank" size="sm" variant="ghost" icon="printer">{{ __('Print view') }}</flux:button><flux:button :href="route('hr.reports.xlsx', ['report' => $key, 'company_id' => $company_id, 'as_of' => $as_of])" size="sm" variant="ghost" icon="arrow-down-tray">{{ __('Excel') }}</flux:button></div></article>@endif
        @endforeach
    </div>
</div>
