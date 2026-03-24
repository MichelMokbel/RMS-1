<?php

use App\Models\ApInvoice;
use App\Services\AP\ApInvoiceAttachmentService;
use App\Services\AP\ApInvoicePostingService;
use App\Services\AP\PurchaseOrderInvoiceMatchingService;
use App\Services\AP\SupplierAccountingPolicyService;
use App\Services\AP\ApInvoiceVoidService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.app')] class extends Component {
    use WithFileUploads;

    public ApInvoice $invoice;
    public array $new_attachments = [];

    public function mount(ApInvoice $invoice): void
    {
        $this->invoice = $invoice->load(['items', 'allocations.payment', 'supplier', 'job', 'expenseProfile.wallet', 'attachments']);
    }

    public function post(ApInvoicePostingService $postingService): void
    {
        $this->invoice = $postingService->post($this->invoice, Illuminate\Support\Facades\Auth::id());
        session()->flash('status', __('Invoice posted.'));
    }

    public function void(ApInvoiceVoidService $voidService): void
    {
        $this->invoice = $voidService->void($this->invoice, Illuminate\Support\Facades\Auth::id());
        session()->flash('status', __('Invoice voided.'));
    }

    public function uploadAttachments(ApInvoiceAttachmentService $attachmentService): void
    {
        $this->validate([
            'new_attachments' => ['required', 'array', 'min:1'],
            'new_attachments.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:7096'],
        ]);

        foreach ($this->new_attachments as $file) {
            $attachmentService->upload($this->invoice, $file, (int) auth()->id());
        }

        $this->new_attachments = [];
        $this->invoice = $this->invoice->fresh(['items', 'allocations.payment', 'supplier', 'job', 'expenseProfile.wallet', 'attachments']);
        session()->flash('status', __('Attachments uploaded.'));
    }

    public function deleteAttachment(int $attachmentId, ApInvoiceAttachmentService $attachmentService): void
    {
        $attachment = $this->invoice->attachments()->findOrFail($attachmentId);
        $attachmentService->delete($attachment);
        $this->invoice = $this->invoice->fresh(['items', 'allocations.payment', 'supplier', 'job', 'expenseProfile.wallet', 'attachments']);
        session()->flash('status', __('Attachment deleted.'));
    }

    public function canManageAttachments(): bool
    {
        return ! in_array($this->invoice->status, ['paid', 'void'], true);
    }

    public function supplierControlMessage(): ?string
    {
        return app(SupplierAccountingPolicyService::class)->draftWarning($this->invoice->supplier);
    }

    public function matchingEvaluation(): ?array
    {
        if (! $this->invoice->purchase_order_id) {
            return null;
        }

        return app(PurchaseOrderInvoiceMatchingService::class)->evaluateInvoice($this->invoice->fresh(['items.purchaseOrderItem', 'purchaseOrder.items.item']));
    }
}; ?>

<div class="w-full max-w-5xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ $invoice->invoice_number }}</h1>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ $invoice->documentTypeLabel() }} · {{ Str::headline(str_replace('_', ' ', $invoice->workflowStateLabel())) }}</p>
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
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Type') }}: {{ $invoice->documentTypeLabel() }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Supplier') }}: {{ $invoice->supplier->name ?? '—' }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Job') }}: {{ $invoice->job ? $invoice->job->code.' · '.$invoice->job->name : '—' }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Invoice Date') }}: {{ $invoice->invoice_date?->format('Y-m-d') }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Due Date') }}: {{ $invoice->due_date?->format('Y-m-d') }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('PO') }}: {{ $invoice->purchase_order_id ?? '—' }}</p>
            @if($this->matchingEvaluation())
                <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('PO Match') }}: {{ str_replace('_', ' ', $this->matchingEvaluation()['overall_status']) }}</p>
            @endif
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Approval') }}: {{ Str::headline(str_replace('_', ' ', $invoice->approvalStatusLabel())) }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Workflow') }}: {{ Str::headline(str_replace('_', ' ', $invoice->workflowStateLabel())) }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Payment') }}: {{ Str::headline(str_replace('_', ' ', $invoice->paymentStateLabel())) }}</p>
            @if($invoice->expenseProfile?->channel)
                <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Expense Channel') }}: {{ Str::headline(str_replace('_', ' ', $invoice->expenseProfile->channel)) }}</p>
            @endif
            @if($invoice->expenseProfile?->wallet)
                <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Wallet') }}: {{ $invoice->expenseProfile->wallet->driver_name ?: $invoice->expenseProfile->wallet->driver_id }}</p>
            @endif
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

    @if($this->supplierControlMessage())
        <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100">
            {{ $this->supplierControlMessage() }}
        </div>
    @endif

    @if($invoice->is_expense && $invoice->attachments->isEmpty())
        <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100">
            {{ __('No supporting attachments have been added yet. Expense approvals may escalate until receipts are uploaded.') }}
        </div>
    @endif

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h3 class="text-sm font-semibold text-neutral-800 dark:text-neutral-200 mb-1">{{ __('Attachments') }}</h3>
                <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Receipts, invoices, and supporting files attached to this document.') }}</p>
            </div>
        </div>

        @if($this->canManageAttachments())
            <form wire:submit="uploadAttachments" class="space-y-3">
                <flux:input type="file" wire:model="new_attachments" accept=".jpg,.jpeg,.png,.webp,.pdf" multiple :label="__('Add Files')" />
                @error('new_attachments') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                @error('new_attachments.*') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                <div class="flex justify-end">
                    <flux:button type="submit" size="sm">{{ __('Upload Attachments') }}</flux:button>
                </div>
            </form>
        @endif

        @if($invoice->attachments->isNotEmpty())
            <div class="space-y-2">
                @foreach($invoice->attachments as $attachment)
                    <div class="flex items-center justify-between gap-4 rounded-md border border-neutral-200 px-3 py-2 text-sm dark:border-neutral-700">
                        <a href="{{ Storage::disk('public')->url($attachment->file_path) }}" target="_blank" class="truncate text-primary-700 hover:underline dark:text-primary-300">
                            {{ $attachment->original_name }}
                        </a>
                        @if($this->canManageAttachments())
                            <flux:button type="button" wire:click="deleteAttachment({{ $attachment->id }})" variant="ghost" size="sm">{{ __('Delete') }}</flux:button>
                        @endif
                    </div>
                @endforeach
            </div>
        @else
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('No attachments added yet.') }}</p>
        @endif
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
                    @if($this->matchingEvaluation())
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('PO Match') }}</th>
                    @endif
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                @php($matchLines = collect($this->matchingEvaluation()['lines'] ?? [])->keyBy('invoice_item_id'))
                @foreach ($invoice->items as $line)
                    <tr>
                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $line->description }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $line->quantity }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ number_format((float)$line->unit_price, 4) }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float)$line->line_total, 2) }}</td>
                        @if($this->matchingEvaluation())
                            @php($match = $matchLines->get($line->id))
                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                                {{ $match ? str_replace('_', ' ', $match['status']) : '—' }}
                            </td>
                        @endif
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
