<?php

use App\Models\DeliveryNote;
use App\Services\AR\DeliveryNoteService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public DeliveryNote $deliveryNote;

    public function mount(DeliveryNote $deliveryNote): void
    {
        abort_unless(in_array((int) $deliveryNote->branch_id, auth()->user()?->allowedBranchIds() ?? [], true), 403);
        $this->deliveryNote = $deliveryNote->load(['items', 'customer', 'sourceInvoice', 'invoice']);
    }

    public function issue(DeliveryNoteService $service): void
    {
        abort_unless(auth()->user()?->can('finance.write'), 403);
        $this->deliveryNote = $service->issue($this->deliveryNote, auth()->id())->load(['items', 'customer', 'sourceInvoice', 'invoice']);
        session()->flash('status', __('Delivery note issued.'));
    }

    public function createInvoice(DeliveryNoteService $service): void
    {
        abort_unless(auth()->user()?->can('finance.write'), 403);
        $invoice = $service->createInvoice($this->deliveryNote, auth()->id());
        session()->flash('status', __('Draft invoice created from delivery note.'));
        $this->redirectRoute('invoices.show', $invoice, navigate: true);
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div><h1 class="text-xl font-semibold">{{ __('Delivery Note') }} {{ $deliveryNote->delivery_note_number ?: '#'.$deliveryNote->id }}</h1><p class="text-sm text-neutral-500">{{ $deliveryNote->customer_name_snapshot }}</p></div><div class="flex flex-wrap gap-2"><flux:button :href="route('delivery-notes.index')" wire:navigate variant="ghost">{{ __('Back') }}</flux:button>@can('finance.write')@if($deliveryNote->status === 'draft')<flux:button :href="route('delivery-notes.edit', $deliveryNote)" wire:navigate>{{ __('Edit') }}</flux:button><flux:button wire:click="issue" variant="primary" wire:loading.attr="disabled">{{ __('Issue Delivery Note') }}</flux:button>@elseif(!$deliveryNote->source_invoice_id)<flux:button wire:click="createInvoice" variant="primary" wire:loading.attr="disabled">{{ $deliveryNote->invoice ? __('Open Invoice') : __('Generate Invoice') }}</flux:button>@endif @endcan @if($deliveryNote->status === 'issued')<flux:button :href="route('delivery-notes.print', $deliveryNote)" target="_blank">{{ __('Print') }}</flux:button>@endif</div></div>
    @if(session('status'))<div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">{{ session('status') }}</div>@endif
    <div class="grid gap-4 rounded-lg border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-neutral-900 sm:grid-cols-2 lg:grid-cols-4"><div><div class="text-xs uppercase text-neutral-500">{{ __('Status') }}</div><div class="font-semibold uppercase">{{ $deliveryNote->status }}</div></div><div><div class="text-xs uppercase text-neutral-500">{{ __('Delivery Date') }}</div><div>{{ $deliveryNote->delivery_date->format('Y-m-d') }}</div></div><div><div class="text-xs uppercase text-neutral-500">{{ __('Reference') }}</div><div>{{ $deliveryNote->reference ?: '—' }}</div></div><div><div class="text-xs uppercase text-neutral-500">{{ __('Source') }}</div><div>@if($deliveryNote->sourceInvoice)<a class="text-blue-600 underline" href="{{ route('invoices.show', $deliveryNote->sourceInvoice) }}">{{ __('Invoice') }} {{ $deliveryNote->sourceInvoice->invoice_number }}</a>@elseif($deliveryNote->invoice)<a class="text-blue-600 underline" href="{{ route('invoices.show', $deliveryNote->invoice) }}">{{ __('Invoice') }} {{ $deliveryNote->invoice->invoice_number ?: '#'.$deliveryNote->invoice->id }}</a>@else{{ __('Manual') }}@endif</div></div><div class="sm:col-span-2"><div class="text-xs uppercase text-neutral-500">{{ __('Delivery Address') }}</div><div class="whitespace-pre-line">{{ $deliveryNote->delivery_address_snapshot ?: '—' }}</div></div><div class="sm:col-span-2"><div class="text-xs uppercase text-neutral-500">{{ __('Notes') }}</div><div class="whitespace-pre-line">{{ $deliveryNote->notes ?: '—' }}</div></div></div>
    <div class="overflow-x-auto rounded-lg border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900"><table class="min-w-full text-sm"><thead class="bg-neutral-50 dark:bg-neutral-800"><tr><th class="px-4 py-3 text-left">#</th><th class="px-4 py-3 text-left">{{ __('Description') }}</th><th class="px-4 py-3 text-right">{{ __('Quantity') }}</th><th class="px-4 py-3 text-left">{{ __('Unit') }}</th><th class="px-4 py-3 text-left">{{ __('Notes') }}</th></tr></thead><tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">@foreach($deliveryNote->items as $index => $item)<tr><td class="px-4 py-3">{{ $index + 1 }}</td><td class="px-4 py-3 font-medium">{{ $item->description }}</td><td class="px-4 py-3 text-right tabular-nums">{{ rtrim(rtrim(number_format((float)$item->qty, 3, '.', ''), '0'), '.') }}</td><td class="px-4 py-3">{{ $item->unit ?: '—' }}</td><td class="px-4 py-3">{{ $item->line_notes ?: '' }}</td></tr>@endforeach</tbody></table></div>
</div>
