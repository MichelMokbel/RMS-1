<?php

use App\Models\Branch;
use App\Models\DocumentTemplate;
use App\Services\Quotations\QuotationTemplateService;
use App\Services\Security\BranchAccessService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public string $name = '';
    public int $company_id = 0;

    public function mount(BranchAccessService $access, QuotationTemplateService $templates): void
    {
        abort_unless(auth()->user()?->can('quotation-templates.manage'), 403);
        $companyIds = $this->allowedCompanyIds($access);
        $this->company_id = (int) $companyIds->first();
        abort_if($this->company_id === 0, 403);
        foreach ($companyIds as $companyId) {
            $templates->ensureDefault((int) $companyId, auth()->user());
        }
    }

    public function createTemplate(BranchAccessService $access, QuotationTemplateService $templates): void
    {
        abort_unless($this->allowedCompanyIds($access)->contains($this->company_id), 403);
        $this->validate(['name' => ['required', 'string', 'max:255']]);

        $template = $templates->create($this->company_id, trim($this->name), $templates->defaultLayout(), auth()->user());

        $this->redirect(route('quotation-templates.edit', $template), navigate: true);
    }

    public function setDefault(int $id, BranchAccessService $access, QuotationTemplateService $templates): void
    {
        $template = DocumentTemplate::query()->whereIn('company_id', $this->allowedCompanyIds($access))->where('type', 'quotation')->findOrFail($id);
        $templates->setDefault($template, auth()->user());
        session()->flash('status', __('Default quotation template updated.'));
    }

    public function toggleActive(int $id, BranchAccessService $access): void
    {
        $template = DocumentTemplate::query()->whereIn('company_id', $this->allowedCompanyIds($access))->where('type', 'quotation')->findOrFail($id);
        abort_if($template->is_default && $template->is_active, 422, __('The default template cannot be disabled.'));
        $template->update(['is_active' => ! $template->is_active, 'updated_by' => auth()->id()]);
    }

    public function with(BranchAccessService $access): array
    {
        $companyIds = $this->allowedCompanyIds($access);
        return [
            'companies' => \App\Models\AccountingCompany::query()->whereIn('id', $companyIds)->orderBy('name')->get(),
            'templates' => DocumentTemplate::query()->with('currentVersion')->whereIn('company_id', $companyIds)->where('type', 'quotation')->orderByDesc('is_default')->orderBy('name')->get(),
        ];
    }

    private function allowedCompanyIds(BranchAccessService $access)
    {
        return Branch::query()->whereIn('id', $access->allowedBranchIds(auth()->user()))->pluck('company_id')->filter()->unique();
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div><h1 class="text-xl font-semibold">{{ __('Quotation templates') }}</h1><p class="mt-1 text-sm text-neutral-500">{{ __('Reusable, versioned layouts for quotations.') }}</p></div><flux:button :href="route('quotation-templates.branding')" wire:navigate icon="paint-brush">{{ __('Company branding') }}</flux:button></div>
    @if(session('status'))<div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>@endif
    <form wire:submit="createTemplate" class="grid gap-3 rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 sm:grid-cols-[1fr_1fr_auto] sm:items-end"><flux:input wire:model="name" :label="__('New template name')" required /><div><label class="mb-1 block text-sm font-medium">{{ __('Company') }}</label><select wire:model="company_id" class="w-full rounded-md border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-600 dark:bg-neutral-800">@foreach($companies as $company)<option value="{{ $company->id }}">{{ $company->name }}</option>@endforeach</select></div><flux:button type="submit" variant="primary" icon="plus">{{ __('Create') }}</flux:button></form>
    <div class="overflow-hidden rounded-lg border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900"><table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-700"><thead class="bg-neutral-50 text-left text-xs uppercase text-neutral-500 dark:bg-neutral-800"><tr><th class="px-4 py-3">{{ __('Name') }}</th><th class="px-4 py-3">{{ __('Company') }}</th><th class="px-4 py-3">{{ __('Version') }}</th><th class="px-4 py-3">{{ __('State') }}</th><th class="px-4 py-3"></th></tr></thead><tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">@forelse($templates as $template)<tr><td class="px-4 py-3 font-medium">{{ $template->name }}</td><td class="px-4 py-3">{{ $template->company?->name }}</td><td class="px-4 py-3">{{ $template->currentVersion?->version ?? 0 }}</td><td class="px-4 py-3"><span class="rounded-full bg-neutral-100 px-2 py-1 text-xs dark:bg-neutral-800">{{ $template->is_default ? __('Default') : ($template->is_active ? __('Active') : __('Inactive')) }}</span></td><td class="px-4 py-3"><div class="flex justify-end gap-2"><flux:button :href="route('quotation-templates.edit', $template)" wire:navigate size="xs">{{ __('Edit') }}</flux:button>@unless($template->is_default)<flux:button wire:click="setDefault({{ $template->id }})" size="xs" variant="ghost">{{ __('Make default') }}</flux:button>@endunless<flux:button wire:click="toggleActive({{ $template->id }})" size="xs" variant="ghost">{{ $template->is_active ? __('Disable') : __('Enable') }}</flux:button></div></td></tr>@empty<tr><td colspan="5" class="px-4 py-10 text-center text-neutral-500">{{ __('No templates yet.') }}</td></tr>@endforelse</tbody></table></div>
</div>
