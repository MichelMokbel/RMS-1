<?php

use App\Models\Customer;
use App\Models\Quotation;
use App\Services\Quotations\QuotationInvoiceConversionService;
use App\Services\Quotations\QuotationLifecycleService;
use App\Services\Quotations\Storage\QuotationArtifactService;
use App\Services\Security\BranchAccessService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public Quotation $quotation;
    public string $status_note = '';
    public string $customer_search = '';
    public ?int $conversion_customer_id = null;

    public function mount(Quotation $quotation, BranchAccessService $access): void
    {
        abort_unless(auth()->user()?->can('quotations.access'), 403);
        abort_unless($access->canAccessBranch(auth()->user(), (int) $quotation->branch_id), 403);
        $this->quotation = $quotation;
        $this->conversion_customer_id = $quotation->customer_id;
    }

    public function markAccepted(QuotationLifecycleService $lifecycle): void
    {
        abort_unless(auth()->user()?->can('quotations.manage'), 403);
        $lifecycle->markAccepted(auth()->user(), $this->quotation, $this->status_note ?: null);
        $this->refreshQuotation(__('Quotation marked as accepted.'));
    }

    public function markRejected(QuotationLifecycleService $lifecycle): void
    {
        abort_unless(auth()->user()?->can('quotations.manage'), 403);
        $lifecycle->markRejected(auth()->user(), $this->quotation, $this->status_note ?: null);
        $this->refreshQuotation(__('Quotation marked as rejected.'));
    }

    public function startRevision(QuotationLifecycleService $lifecycle): void
    {
        abort_unless(auth()->user()?->can('quotations.manage'), 403);
        $draft = $lifecycle->startRevision(auth()->user(), $this->quotation);
        session()->flash('status', __('A new draft revision is ready.'));
        $this->redirect(route('quotations.edit', $draft), navigate: true);
    }

    public function selectConversionCustomer(int $id): void
    {
        $this->conversion_customer_id = Customer::query()->active()->findOrFail($id)->id;
        $this->customer_search = '';
    }

    public function convert(QuotationInvoiceConversionService $conversion): void
    {
        abort_unless(auth()->user()?->can('quotations.convert'), 403);
        $invoice = $conversion->convert(auth()->user(), $this->quotation, $this->conversion_customer_id);
        session()->flash('status', __('Draft invoice created from the accepted quotation.'));
        $this->redirect(route('invoices.show', $invoice), navigate: true);
    }

    public function retryArtifacts(int $versionId, QuotationArtifactService $artifacts): void
    {
        abort_unless(auth()->user()?->can('quotations.manage'), 403);
        $version = $this->quotation->versions()->findOrFail($versionId);
        $artifacts->retryForVersion($version);
        $this->refreshQuotation(__('Document generation retried.'));
    }

    public function with(): array
    {
        $quotation = $this->quotation->fresh()->load(['branch', 'company', 'customer', 'versions.artifacts', 'statusEvents.actor']);
        return [
            'quote' => $quotation,
            'customerResults' => strlen(trim($this->customer_search)) >= 2 ? Customer::query()->active()->search($this->customer_search)->limit(8)->get() : collect(),
        ];
    }

    private function refreshQuotation(string $message): void
    {
        $this->quotation = $this->quotation->fresh();
        $this->status_note = '';
        session()->flash('status', $message);
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="flex items-start gap-3"><flux:button :href="route('quotations.index')" wire:navigate variant="ghost" icon="arrow-left" size="sm" /><div><div class="flex flex-wrap items-center gap-2"><h1 class="text-xl font-semibold">{{ $quote->number ?: __('Draft quotation') }}</h1><span class="rounded-full bg-neutral-100 px-2.5 py-1 text-xs font-medium dark:bg-neutral-800">{{ __(ucfirst($quote->status)) }}</span><span class="text-xs text-neutral-500">{{ __('Revision :number', ['number' => max(1, (int)$quote->current_revision)]) }}</span></div><p class="mt-1 text-sm text-neutral-500">{{ $quote->customer?->name ?: $quote->recipient_name }} · {{ $quote->branch?->name }}</p></div></div>
        <div class="flex flex-wrap gap-2">
            @if($quote->versions->isNotEmpty()) <flux:button :href="route('quotations.preview', [$quote, $quote->versions->sortByDesc('revision')->first()])" target="_blank" icon="eye">{{ __('Preview') }}</flux:button> @endif
            @can('quotations.manage')
                @if($quote->status === 'draft') <flux:button :href="route('quotations.edit', $quote)" wire:navigate variant="primary">{{ __('Edit draft') }}</flux:button> @endif
                @if(in_array($quote->status, ['sent','rejected','expired'])) <flux:button wire:click="startRevision" wire:confirm="{{ __('Create a new draft revision?') }}">{{ __('New revision') }}</flux:button> @endif
            @endcan
        </div>
    </div>

    @if(session('status')) <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">{{ session('status') }}</div> @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <section class="rounded-lg border border-neutral-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <h2 class="mb-4 font-semibold">{{ __('Commercial summary') }}</h2>
                <dl class="grid gap-4 text-sm sm:grid-cols-2"><div><dt class="text-neutral-500">{{ __('Issue date') }}</dt><dd class="font-medium">{{ optional($quote->issue_date)->format('d M Y') }}</dd></div><div><dt class="text-neutral-500">{{ __('Valid until') }}</dt><dd class="font-medium">{{ optional($quote->valid_until)->format('d M Y') }}</dd></div><div><dt class="text-neutral-500">{{ __('Recipient') }}</dt><dd class="font-medium">{{ $quote->recipient_name }}</dd></div><div><dt class="text-neutral-500">{{ __('Total') }}</dt><dd class="text-lg font-semibold">{{ number_format(((int)$quote->total_minor)/100, 2) }} QAR</dd></div></dl>
            </section>

            <section class="rounded-lg border border-neutral-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <h2 class="mb-4 font-semibold">{{ __('Generated documents') }}</h2>
                <div class="space-y-4">
                    @forelse($quote->versions->sortByDesc('revision') as $version)
                        <div class="rounded-lg border border-neutral-200 p-4 dark:border-neutral-700"><div class="mb-3 flex items-center justify-between"><div><p class="font-medium">{{ __('Revision :number', ['number' => $version->revision]) }}</p><p class="text-xs text-neutral-500">{{ optional($version->finalized_at)->format('d M Y H:i') }}</p></div><div class="flex gap-2">@foreach($version->artifacts as $artifact)<flux:button :href="$artifact->generation_status === 'ready' ? route('quotations.artifacts.download', $artifact) : '#'" size="sm" :disabled="$artifact->generation_status !== 'ready'">{{ strtoupper($artifact->format) }}</flux:button>@endforeach</div></div>@if($version->artifacts->count() < 2 || $version->artifacts->contains(fn($artifact) => $artifact->generation_status !== 'ready')) @can('quotations.manage')<flux:button wire:click="retryArtifacts({{ $version->id }})" size="xs" variant="ghost" icon="arrow-path">{{ __('Retry missing documents') }}</flux:button>@endcan @endif</div>
                    @empty <p class="text-sm text-neutral-500">{{ __('Documents are generated when a draft is finalized.') }}</p> @endforelse
                </div>
            </section>
        </div>

        <aside class="space-y-6">
            @can('quotations.manage')
                @if($quote->status === 'sent' && (! $quote->valid_until || $quote->valid_until->endOfDay()->isFuture()))
                    <section class="rounded-lg border border-neutral-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"><h2 class="mb-3 font-semibold">{{ __('Record customer decision') }}</h2><flux:textarea wire:model="status_note" :label="__('Internal note (optional)')" rows="3" /><div class="mt-3 flex gap-2"><flux:button wire:click="markAccepted" wire:confirm="{{ __('Mark this quotation as accepted?') }}" variant="primary">{{ __('Accept') }}</flux:button><flux:button wire:click="markRejected" wire:confirm="{{ __('Mark this quotation as rejected?') }}" variant="danger">{{ __('Reject') }}</flux:button></div></section>
                @endif
            @endcan

            @can('quotations.convert')
                @if($quote->status === 'accepted' && ! $quote->converted_invoice_id)
                    <section class="rounded-lg border border-primary-200 bg-primary-50 p-5 shadow-sm dark:border-primary-800 dark:bg-primary-950"><h2 class="font-semibold">{{ __('Create invoice') }}</h2><p class="mt-1 text-sm text-neutral-600 dark:text-neutral-300">{{ __('Creates one draft AR invoice from the accepted revision.') }}</p>@if(!$quote->customer_id)<div class="relative mt-3"><flux:input wire:model.live.debounce.300ms="customer_search" :label="__('Select a customer first')" />@if($customerResults->isNotEmpty())<div class="absolute z-20 mt-1 w-full rounded border bg-white shadow-lg dark:bg-neutral-800">@foreach($customerResults as $customer)<button type="button" wire:click="selectConversionCustomer({{ $customer->id }})" class="block w-full px-3 py-2 text-left text-sm hover:bg-neutral-100">{{ $customer->name }}</button>@endforeach</div>@endif</div><flux:button class="mt-2" :href="route('customers.create')" wire:navigate size="xs" variant="ghost">{{ __('Create customer') }}</flux:button>@endif<flux:button class="mt-4 w-full" wire:click="convert" wire:confirm="{{ __('Create a draft invoice now?') }}" :disabled="!$conversion_customer_id" variant="primary">{{ __('Convert to invoice') }}</flux:button></section>
                @elseif($quote->converted_invoice_id) <flux:button :href="route('invoices.show', $quote->converted_invoice_id)" wire:navigate class="w-full">{{ __('View converted invoice') }}</flux:button> @endif
            @endcan

            <section class="rounded-lg border border-neutral-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"><h2 class="mb-3 font-semibold">{{ __('History') }}</h2><ol class="space-y-3">@forelse($quote->statusEvents->sortByDesc('created_at') as $event)<li class="border-l-2 border-neutral-200 pl-3 text-sm dark:border-neutral-700"><p class="font-medium">{{ __(str($event->event)->replace('_',' ')->title()->toString()) }}</p><p class="text-xs text-neutral-500">{{ optional($event->created_at)->format('d M Y H:i') }} · {{ $event->actor?->name }}</p>@if($event->note)<p class="mt-1 text-neutral-600 dark:text-neutral-300">{{ $event->note }}</p>@endif</li>@empty<li class="text-sm text-neutral-500">{{ __('No lifecycle events yet.') }}</li>@endforelse</ol></section>
        </aside>
    </div>
</div>
