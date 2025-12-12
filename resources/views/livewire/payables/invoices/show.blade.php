<?php

use App\Models\ApInvoice;
use App\Services\AP\ApInvoicePostingService;
use App\Services\AP\ApInvoiceVoidService;
use App\Services\AP\ApInvoiceStatusService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public ApInvoice $invoice;

    public function mount(ApInvoice $invoice): void
    {
        $this->invoice = $invoice->load(['items', 'allocations.payment', 'supplier']);
    }

    public function post(ApInvoicePostingService $postingService): void
    {
        $this->invoice = $postingService->post($this->invoice, auth()->id());
        session()->flash('status', __('Invoice posted.'));
    }

    public function void(ApInvoiceVoidService $voidService): void
    {
        $this->invoice = $voidService->void($this->invoice, auth()->id());
        session()->flash('status', __('Invoice voided.'));
    }
}; ?>

<div class="w-full max-w-5xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ $invoice->invoice_number }}</h1>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Status') }}: {{ $invoice->status }}</p>
        </div>
        <div class="flex gap-2">
            <flux:button :href="route('payables.index')" wire:navigate variant="ghost">{{ __('Back') }}</flux:button>
            @if($invoice->status === 'draft')
                <flux:button type="button" wire:click="post">{{ __('Post') }}</flux:button>
                <flux:button :href="route('payables.invoices.edit', $invoice)" wire:navigate>{{ __('Edit') }}</flux:button>
            @endif
            @if(in_array($invoice->status, ['draft','posted']) && $invoice->allocations->count() === 0)
                <flux:button type="button" wire:click="void" variant="ghost">{{ __('Void') }}</flux:button>
            @endif
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-2">
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Supplier') }}: {{ $invoice->supplier->name ?? '—' }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Invoice Date') }}: {{ $invoice->invoice_date?->format('Y-m-d') }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Due Date') }}: {{ $invoice->due_date?->format('Y-m-d') }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('PO') }}: {{ $invoice->purchase_order_id ?? '—' }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Expense') }}: {{ $invoice->is_expense ? 'Yes' : 'No' }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Notes') }}: {{ $invoice->notes ?? '—' }}</p>
        </div>
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-2">
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Subtotal') }}: {{ number_format((float)$invoice->subtotal, 2) }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Tax') }}: {{ number_format((float)$invoice->tax_amount, 2) }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Total') }}: {{ number_format((float)$invoice->total_amount, 2) }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Paid') }}: {{ number_format((float)$invoice->allocations->sum('allocated_amount'), 2) }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Outstanding') }}: {{ number_format((float)$invoice->total_amount - (float)$invoice->allocations->sum('allocated_amount'), 2) }}</p>
        </div>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <h3 class="text-sm font-semibold text-neutral-800 dark:text-neutral-200 mb-2">{{ __('Line Items') }}</h3>
        <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Description') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Qty') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Unit Price') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Total') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                @foreach ($invoice->items as $line)
                    <tr>
                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $line->description }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $line->quantity }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ number_format((float)$line->unit_price, 4) }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float)$line->line_total, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <h3 class="text-sm font-semibold text-neutral-800 dark:text-neutral-200 mb-2">{{ __('Allocations') }}</h3>
        <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Payment Date') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Amount') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Method') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                @forelse ($invoice->allocations as $alloc)
                    <tr>
                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $alloc->payment->payment_date?->format('Y-m-d') }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ number_format((float)$alloc->allocated_amount, 2) }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $alloc->payment->payment_method }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-3 py-3 text-sm text-neutral-600 dark:text-neutral-300 text-center">{{ __('No allocations yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
