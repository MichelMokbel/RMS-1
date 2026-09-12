<?php

use App\Models\AccountingCompany;
use App\Models\Branch;
use App\Models\HrDocumentType;
use App\Models\HrLeavePolicy;
use App\Models\HrLeaveType;
use App\Services\HR\HrBootstrapService;
use App\Services\HR\HrSettingsService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public ?int $company_id = null;
    public ?int $selected_company_id = null;
    public ?int $leave_type_id = null;
    public string $policy_name = '';
    public string $effective_from = '';
    public string $entitlement_days = '0';
    public bool $allow_negative = false;
    public bool $requires_attachment = false;

    public function mount(): void
    {
        $this->authorizeSettings();
        $this->company_id = $this->companyIds()->first();
        $this->selected_company_id = $this->company_id;
        $this->effective_from = now()->startOfYear()->toDateString();
    }

    public function selectCompany(): void
    {
        $data = $this->validate([
            'selected_company_id' => ['required', 'integer', 'exists:accounting_companies,id'],
        ]);
        abort_unless($this->companyIds()->contains((int) $data['selected_company_id']), 403);
        $this->company_id = (int) $data['selected_company_id'];
        $this->resetValidation();
        $this->dispatch('modal-close', name: 'select-hr-settings-company');
    }

    public function bootstrap(HrBootstrapService $service): void
    {
        $company = AccountingCompany::query()->whereIn('id', $this->companyIds())->findOrFail($this->company_id);
        $service->bootstrap($company, auth()->user());
        session()->flash('status', __('HR document and leave presets created. Review policies before activating them.'));
    }

    public function createPolicy(HrSettingsService $service): void
    {
        $data = $this->validate([
            'leave_type_id' => ['required', 'integer', 'exists:hr_leave_types,id'],
            'policy_name' => ['required', 'string', 'max:150'],
            'effective_from' => ['required', 'date'],
            'entitlement_days' => ['required', 'numeric', 'min:0', 'max:365'],
            'allow_negative' => ['boolean'],
            'requires_attachment' => ['boolean'],
        ]);
        $type = HrLeaveType::query()->where('company_id', $this->company_id)->findOrFail($data['leave_type_id']);
        $service->createLeavePolicy($type, [
            'name' => $data['policy_name'], 'effective_from' => $data['effective_from'],
            'entitlement_days' => $data['entitlement_days'], 'allow_negative' => $data['allow_negative'],
            'requires_attachment' => $data['requires_attachment'], 'counts_calendar_days' => true,
        ], auth()->user());
        $this->reset(['leave_type_id', 'policy_name', 'entitlement_days', 'allow_negative', 'requires_attachment']);
        $this->effective_from = now()->startOfYear()->toDateString();
        $this->resetValidation();
        $this->dispatch('modal-close', name: 'create-hr-leave-policy');
        session()->flash('status', __('Draft leave policy created. Activate it after legal review.'));
    }

    public function activatePolicy(int $id, HrSettingsService $service): void
    {
        $policy = HrLeavePolicy::query()->where('company_id', $this->company_id)->findOrFail($id);
        $service->activateLeavePolicy($policy, auth()->user(), true);
        session()->flash('status', __('Leave policy activated.'));
    }

    public function with(): array
    {
        $ids = $this->companyIds();
        if (! $this->company_id || ! $ids->contains($this->company_id)) $this->company_id = $ids->first();
        return [
            'companies' => AccountingCompany::query()->whereIn('id', $ids)->where('is_active', true)->orderBy('name')->get(),
            'documentTypes' => HrDocumentType::query()->where('company_id', $this->company_id)->orderBy('name')->get(),
            'leaveTypes' => HrLeaveType::query()->where('company_id', $this->company_id)->withCount('policies')->orderBy('name')->get(),
            'policies' => HrLeavePolicy::query()->where('company_id', $this->company_id)->with('leaveType')->latest('effective_from')->get(),
        ];
    }

    private function companyIds()
    {
        return auth()->user()?->hasRole('admin') ? AccountingCompany::query()->pluck('id') : Branch::query()->whereIn('id', auth()->user()?->allowedBranchIds() ?: [-1])->pluck('company_id')->unique();
    }
    private function authorizeSettings(): void { abort_unless(auth()->user()?->hasRole('admin') || auth()->user()?->can('hr.settings.manage'), 403); }
}; ?>

<div class="app-page space-y-6"><div class="flex flex-wrap items-start justify-between gap-3"><div><h1 class="text-2xl font-semibold">{{ __('HR settings') }}</h1><p class="text-sm text-neutral-500">{{ __('Bootstrap and confirm company-specific compliance and leave rules.') }}</p></div><div class="flex flex-wrap gap-2"><flux:modal.trigger name="select-hr-settings-company"><flux:button type="button" variant="ghost" icon="building-office">{{ __('Choose company') }}</flux:button></flux:modal.trigger><flux:modal.trigger name="create-hr-leave-policy"><flux:button type="button" icon="plus">{{ __('New leave policy') }}</flux:button></flux:modal.trigger></div></div>@include('livewire.hr.partials.navigation')
    @if(session('status'))<div class="rounded-md bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>@endif
    <section class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-neutral-900"><div><p class="text-xs font-medium uppercase tracking-wide text-neutral-500">{{ __('Company') }}</p><p class="font-semibold">{{ $companies->firstWhere('id', $company_id)?->name ?? __('No company selected') }}</p><p class="text-sm text-neutral-500">{{ __('Qatar-oriented presets remain drafts until reviewed and activated.') }}</p></div><flux:button type="button" wire:click="bootstrap" wire:confirm="{{ __('Create any missing Qatar-oriented HR presets?') }}" icon="sparkles">{{ __('Bootstrap presets') }}</flux:button></section>
    <div class="grid gap-4 xl:grid-cols-2"><section class="rounded-lg border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-neutral-900"><h2 class="font-semibold">{{ __('Document types') }}</h2>@forelse($documentTypes as $row)<div class="flex justify-between border-b border-neutral-100 py-3 text-sm dark:border-neutral-800"><div><p class="font-medium">{{ $row->name }}</p><p class="text-xs text-neutral-500">{{ $row->code }}</p></div><span>{{ $row->is_required ? __('Required') : __('Optional') }}</span></div>@empty<p class="py-8 text-center text-sm text-neutral-500">{{ __('No document types. Bootstrap the presets to begin.') }}</p>@endforelse</section>
        <section class="rounded-lg border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-neutral-900"><h2 class="font-semibold">{{ __('Leave policies') }}</h2>@forelse($policies as $row)<div class="flex items-center justify-between gap-3 border-b border-neutral-100 py-3 text-sm dark:border-neutral-800"><div><p class="font-medium">{{ $row->name }} · {{ $row->leaveType?->name }}</p><p class="text-xs text-neutral-500">{{ number_format((float) $row->entitlement_days, 1) }} {{ __('calendar days') }} · {{ $row->effective_from?->format('d M Y') }}</p></div>@if($row->is_active)<span class="rounded-full bg-emerald-100 px-2 py-1 text-xs text-emerald-800">{{ __('Active') }}</span>@else<flux:button wire:click="activatePolicy({{ $row->id }})" wire:confirm="{{ __('Confirm legal review and activate this policy?') }}" size="xs">{{ __('Activate') }}</flux:button>@endif</div>@empty<p class="py-8 text-center text-sm text-neutral-500">{{ __('No leave policies configured.') }}</p>@endforelse</section></div>
    <flux:modal name="select-hr-settings-company" :show="$errors->has('selected_company_id')" focusable class="max-w-lg">
        <form wire:submit="selectCompany" class="space-y-6">
            <div><flux:heading size="lg">{{ __('Choose company') }}</flux:heading><flux:subheading>{{ __('Select the company whose HR settings you want to review.') }}</flux:subheading></div>
            <flux:select wire:model="selected_company_id" :label="__('Company')">@foreach($companies as $row)<option value="{{ $row->id }}">{{ $row->name }}</option>@endforeach</flux:select>
            <div class="flex justify-end gap-2"><flux:modal.close><flux:button type="button" variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close><flux:button type="submit" variant="primary">{{ __('Apply') }}</flux:button></div>
        </form>
    </flux:modal>

    <flux:modal name="create-hr-leave-policy" :show="$errors->has('leave_type_id') || $errors->has('policy_name') || $errors->has('effective_from') || $errors->has('entitlement_days')" focusable class="max-w-2xl">
        <form wire:submit="createPolicy" class="space-y-6">
            <div><flux:heading size="lg">{{ __('New leave policy') }}</flux:heading><flux:subheading>{{ __('Policies are created inactive and require explicit activation after legal review.') }}</flux:subheading></div>
            <div class="grid gap-4 md:grid-cols-2"><flux:select wire:model="leave_type_id" :label="__('Leave type')"><option value="">{{ __('Choose type') }}</option>@foreach($leaveTypes as $row)<option value="{{ $row->id }}">{{ $row->name }}</option>@endforeach</flux:select><flux:input wire:model="policy_name" :label="__('Policy name')" /><flux:input wire:model="effective_from" type="date" :label="__('Effective from')" /><x-number-input wire:model="entitlement_days" type="number" :label="__('Annual entitlement days')" /><flux:checkbox wire:model="allow_negative" :label="__('Allow negative balance')" /><flux:checkbox wire:model="requires_attachment" :label="__('Require attachment')" /></div>
            <div class="flex justify-end gap-2"><flux:modal.close><flux:button type="button" variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close><flux:button type="submit" variant="primary">{{ __('Create draft policy') }}</flux:button></div>
        </form>
    </flux:modal>
</div>
