<?php

use App\Models\DeliveryNote;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public string $search = '';
    public string $status = 'all';

    public function with(): array
    {
        $branchIds = auth()->user()?->allowedBranchIds() ?? [];

        return ['notes' => DeliveryNote::query()
            ->with(['customer', 'sourceInvoice', 'invoice'])
            ->whereIn('branch_id', $branchIds)
            ->when($this->status !== 'all', fn ($query) => $query->where('status', $this->status))
            ->when(trim($this->search) !== '', function ($query): void {
                $term = '%'.trim($this->search).'%';
                $query->where(fn ($inner) => $inner->where('delivery_note_number', 'like', $term)
                    ->orWhere('reference', 'like', $term)
                    ->orWhere('customer_name_snapshot', 'like', $term));
            })
            ->latest('delivery_date')->latest('id')->limit(200)->get()];
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div><h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Delivery Notes') }}</h1><p class="text-sm text-neutral-500">{{ __('Create delivery notes and convert issued notes into invoices.') }}</p></div>
        @can('finance.write')<flux:button :href="route('delivery-notes.create')" wire:navigate variant="primary">{{ __('New Delivery Note') }}</flux:button>@endcan
    </div>
    @if(session('status'))<div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">{{ session('status') }}</div>@endif
    <div class="grid gap-3 rounded-lg border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900 sm:grid-cols-[1fr_12rem]">
        <flux:input wire:model.live.debounce.300ms="search" :label="__('Search')" :placeholder="__('Number, reference, or customer')" />
        <flux:select wire:model.live="status" :label="__('Status')"><option value="all">{{ __('All') }}</option><option value="draft">{{ __('Draft') }}</option><option value="issued">{{ __('Issued') }}</option></flux:select>
    </div>
    <div class="overflow-x-auto rounded-lg border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
        <table class="min-w-full text-sm"><thead class="bg-neutral-50 dark:bg-neutral-800"><tr><th class="px-4 py-3 text-left">{{ __('Number') }}</th><th class="px-4 py-3 text-left">{{ __('Date') }}</th><th class="px-4 py-3 text-left">{{ __('Customer') }}</th><th class="px-4 py-3 text-left">{{ __('Reference') }}</th><th class="px-4 py-3 text-left">{{ __('Status') }}</th><th class="px-4 py-3 text-right">{{ __('Action') }}</th></tr></thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">@forelse($notes as $note)<tr><td class="px-4 py-3 font-medium">{{ $note->delivery_note_number ?: '#'.$note->id }}</td><td class="px-4 py-3">{{ $note->delivery_date->format('Y-m-d') }}</td><td class="px-4 py-3">{{ $note->customer_name_snapshot }}</td><td class="px-4 py-3">{{ $note->reference ?: '—' }}</td><td class="px-4 py-3"><span class="rounded-full bg-neutral-100 px-2 py-1 text-xs font-semibold uppercase dark:bg-neutral-800">{{ $note->status }}</span></td><td class="px-4 py-3 text-right"><flux:button size="sm" :href="route('delivery-notes.show', $note)" wire:navigate>{{ __('Open') }}</flux:button></td></tr>@empty<tr><td colspan="6" class="px-4 py-10 text-center text-neutral-500">{{ __('No delivery notes found.') }}</td></tr>@endforelse</tbody>
        </table>
    </div>
</div>
