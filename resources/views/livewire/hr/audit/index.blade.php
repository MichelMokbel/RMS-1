<?php

use App\Models\HrAuditLog;
use App\Models\User;
use App\Models\AccountingCompany;
use App\Models\Branch;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;
    public string $search = '';
    public string $event = '';

    public function mount(): void { abort_unless(auth()->user()?->hasRole('admin') || auth()->user()?->can('hr.audit.view'), 403); }
    public function updatedSearch(): void { $this->resetPage(); }

    public function with(): array
    {
        $companyIds = auth()->user()?->hasRole('admin') ? AccountingCompany::query()->pluck('id') : Branch::query()->whereIn('id', auth()->user()?->allowedBranchIds() ?: [-1])->pluck('company_id')->unique();
        $query = HrAuditLog::query()->whereIn('company_id', $companyIds)->latest('created_at')
            ->when($this->event !== '', fn ($q) => $q->where('action', 'like', $this->event.'%'))
            ->when($this->search !== '', function ($q): void { $term = '%'.trim($this->search).'%'; $q->where(fn ($inner) => $inner->where('action', 'like', $term)->orWhere('subject_type', 'like', $term)->orWhere('subject_id', trim($this->search))); });
        $logs = $query->paginate(30);
        return ['logs' => $logs, 'actors' => User::query()->whereIn('id', $logs->pluck('actor_id')->filter())->pluck('username', 'id')];
    }
}; ?>

<div class="app-page space-y-6"><div><h1 class="text-2xl font-semibold">{{ __('HR audit') }}</h1><p class="text-sm text-neutral-500">{{ __('Append-only history of sensitive access and workflow actions.') }}</p></div>@include('livewire.hr.partials.navigation')
    <div class="grid gap-3 rounded-lg border border-neutral-200 bg-white p-4 md:grid-cols-2 dark:border-neutral-700 dark:bg-neutral-900"><flux:input wire:model.live.debounce.300ms="search" :label="__('Search')" :placeholder="__('Event or subject')" /><flux:select wire:model.live="event" :label="__('Event family')"><option value="">{{ __('All HR events') }}</option><option value="hr.employee">{{ __('Employees') }}</option><option value="hr.document">{{ __('Documents') }}</option><option value="hr.leave">{{ __('Leave') }}</option><option value="hr.payroll">{{ __('Payroll') }}</option><option value="hr.import">{{ __('Imports') }}</option></flux:select></div>
    <section class="overflow-hidden rounded-lg border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900"><div class="overflow-x-auto"><table class="w-full text-sm"><thead class="bg-neutral-50 text-left text-xs uppercase text-neutral-500 dark:bg-neutral-800"><tr><th class="px-4 py-3">{{ __('Time') }}</th><th class="px-4 py-3">{{ __('Actor') }}</th><th class="px-4 py-3">{{ __('Event') }}</th><th class="px-4 py-3">{{ __('Subject') }}</th><th class="px-4 py-3">{{ __('Context') }}</th></tr></thead><tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">@forelse($logs as $log)<tr><td class="whitespace-nowrap px-4 py-3">{{ $log->created_at?->format('d M Y H:i') }}</td><td class="px-4 py-3">{{ $actors[$log->actor_id] ?? __('System') }}</td><td class="px-4 py-3 font-medium">{{ Str::headline(str_replace('hr.', '', $log->action)) }}</td><td class="px-4 py-3">{{ $log->subject_type ? class_basename($log->subject_type).' #'.$log->subject_id : '—' }}</td><td class="max-w-sm truncate px-4 py-3 text-neutral-500">{{ collect($log->payload ?? [])->keys()->join(', ') ?: '—' }}</td></tr>@empty<tr><td colspan="5" class="px-4 py-10 text-center text-neutral-500">{{ __('No audit events.') }}</td></tr>@endforelse</tbody></table></div><div class="border-t border-neutral-200 p-4 dark:border-neutral-700">{{ $logs->links() }}</div></section>
</div>
