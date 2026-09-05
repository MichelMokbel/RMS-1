<?php

use App\Models\ApInvoice;
use App\Services\AP\ApInvoiceAttachmentService;
use App\Services\AP\ApInvoicePostingService;
use App\Services\AP\ApInvoiceVoidService;
use App\Services\AP\PaidExpenseCorrectionService;
use App\Services\AP\PurchaseOrderInvoiceMatchingService;
use App\Services\AP\SupplierAccountingPolicyService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.app')] class extends Component
{
    use WithFileUploads;

    public ApInvoice $invoice;

    public array $new_attachments = [];

    public string $revision_reason = '';

    public function mount(ApInvoice $invoice): void
    {
        $this->invoice = $invoice->load(['items', 'allocations.payment', 'supplier', 'job', 'jobPhase', 'jobCostCode', 'expenseProfile.wallet', 'attachments', 'period', 'revisionRoot', 'revisionSource']);
    }

    public function post(ApInvoicePostingService $postingService): void
    {
        abort_unless($this->canManageInvoice(), 403);
        $this->invoice = $postingService->post($this->invoice, Illuminate\Support\Facades\Auth::id());
        session()->flash('status', __('Invoice posted.'));
    }

    public function void(ApInvoiceVoidService $voidService): void
    {
        abort_unless($this->canManageInvoice(), 403);
        $this->invoice = $voidService->void($this->invoice, Illuminate\Support\Facades\Auth::id());
        session()->flash('status', __('Invoice voided.'));
    }

    public function revise(
        ApInvoiceVoidService $voidService,
        PaidExpenseCorrectionService $correctionService,
    ): void {
        abort_unless($this->canManageInvoice(), 403);
        $this->resetErrorBag();
        $data = $this->validate([
            'revision_reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $revision = $this->canCorrectClosedExpense()
                ? $correctionService->createEditableVersion(
                    $this->invoice,
                    (int) auth()->id(),
                    $data['revision_reason'] ?? null,
                )
                : $voidService->voidAndDuplicate(
                    $this->invoice,
                    (int) auth()->id(),
                    $data['revision_reason'] ?? null,
                );
        } catch (ValidationException $exception) {
            $this->addValidationErrors($exception);

            return;
        }

        session()->flash('status', __('Invoice voided and revision draft created.'));
        $this->redirectRoute('payables.invoices.edit', $revision, navigate: true);
    }

    public function voidClosedExpense(PaidExpenseCorrectionService $correctionService): void
    {
        abort_unless($this->canCorrectClosedExpense(), 403);
        $this->resetErrorBag();
        $data = $this->validate([
            'revision_reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->invoice = $correctionService->voidExpense(
                $this->invoice,
                (int) auth()->id(),
                $data['revision_reason'] ?? null,
            );
        } catch (ValidationException $exception) {
            $this->addValidationErrors($exception);

            return;
        }

        session()->flash('status', __('Expense settlement reversed and expense voided.'));
    }

    public function uploadAttachments(ApInvoiceAttachmentService $attachmentService): void
    {
        try {
            $this->validate([
                'new_attachments' => ['required', 'array', 'min:1'],
                'new_attachments.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:7096'],
            ]);

            foreach ($this->new_attachments as $file) {
                $attachmentService->upload($this->invoice, $file, (int) auth()->id());
            }

            $this->new_attachments = [];
            $this->invoice = $this->invoice->fresh(['items', 'allocations.payment', 'supplier', 'job', 'jobPhase', 'jobCostCode', 'expenseProfile.wallet', 'attachments', 'period']);
            session()->flash('status', __('Attachments uploaded.'));
        } catch (ValidationException $exception) {
            session()->flash('error', collect($exception->errors())->flatten()->first() ?: __('Attachments could not be updated.'));
        }
    }

    public function deleteAttachment(int $attachmentId, ApInvoiceAttachmentService $attachmentService): void
    {
        try {
            $attachment = $this->invoice->attachments()->findOrFail($attachmentId);
            $attachmentService->delete($attachment, (int) auth()->id());
            $this->invoice = $this->invoice->fresh(['items', 'allocations.payment', 'supplier', 'job', 'jobPhase', 'jobCostCode', 'expenseProfile.wallet', 'attachments', 'period']);
            session()->flash('status', __('Attachment deleted.'));
        } catch (ValidationException $exception) {
            session()->flash('error', collect($exception->errors())->flatten()->first() ?: __('Attachments could not be updated.'));
        }
    }

    public function canManageAttachments(): bool
    {
        return $this->invoice->canMutateAttachments();
    }

    public function canManageInvoice(): bool
    {
        return (bool) auth()->user()?->hasAnyRole(['admin', 'manager']);
    }

    public function canCreateRevision(): bool
    {
        return $this->canManageInvoice()
            && $this->invoice->status === 'posted'
            && $this->invoice->allocations->isEmpty()
            && $this->invoice->document_type !== 'landed_cost_adjustment';
    }

    public function canCorrectClosedExpense(): bool
    {
        return $this->canManageInvoice()
            && $this->invoice->is_expense
            && in_array($this->invoice->status, ['paid', 'partially_paid'], true)
            && $this->invoice->allocations->isNotEmpty()
            && $this->invoice->document_type !== 'landed_cost_adjustment';
    }

    private function addValidationErrors(ValidationException $exception): void
    {
        foreach ($exception->errors() as $field => $messages) {
            foreach ($messages as $message) {
                $this->addError($field, $message);
            }
        }
    }

    public function revisionHistory()
    {
        $rootId = $this->invoice->rootInvoiceId();
        if (! $rootId) {
            return collect();
        }

        return ApInvoice::query()
            ->whereKey($rootId)
            ->orWhere('revision_root_id', $rootId)
            ->orderBy('revision_number')
            ->orderBy('id')
            ->get();
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
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ $invoice->invoice_number }}</h1>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ $invoice->documentTypeLabel() }} · {{ Str::headline(str_replace('_', ' ', $invoice->workflowStateLabel())) }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @can('finance.write')
                <flux:button :href="route('payables.create')" wire:navigate variant="primary" class="touch-target">{{ __('Create New Document') }}</flux:button>
            @endcan
            <flux:button :href="route('payables.index')" wire:navigate variant="ghost">{{ __('Back') }}</flux:button>
            @if($this->canManageInvoice())
                @if($invoice->status === 'draft')
                    <flux:button type="button" wire:click="post">{{ __('Post') }}</flux:button>
                    <flux:button :href="route('payables.invoices.edit', $invoice)" wire:navigate>{{ __('Edit') }}</flux:button>
                @elseif($this->canCreateRevision() || $this->canCorrectClosedExpense())
                    <flux:modal.trigger name="revise-ap-invoice-modal">
                        <flux:button type="button" x-data="" x-on:click.prevent="$dispatch('open-modal', 'revise-ap-invoice-modal')">
                            {{ $this->canCorrectClosedExpense() ? __('Correct Expense') : __('Create Editable Version') }}
                        </flux:button>
                    </flux:modal.trigger>
                @endif
                @if(in_array($invoice->status, ['draft','posted']) && $invoice->allocations->count() === 0)
                    <flux:button type="button" wire:click="void" wire:confirm="{{ __('Void this invoice? Posted accounting entries will be reversed.') }}" variant="ghost">{{ __('Void') }}</flux:button>
                @endif
            @endif
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    @if (session('error'))
        <div class="rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 dark:border-rose-900 dark:bg-rose-950 dark:text-rose-100">
            {{ session('error') }}
        </div>
    @endif

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-2">
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Type') }}: {{ $invoice->documentTypeLabel() }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Supplier') }}: {{ $invoice->supplier->name ?? '—' }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Job') }}: {{ $invoice->job ? $invoice->job->code.' · '.$invoice->job->name : '—' }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Phase') }}: {{ $invoice->jobPhase ? $invoice->jobPhase->code.' · '.$invoice->jobPhase->name : '—' }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Cost Code') }}: {{ $invoice->jobCostCode ? $invoice->jobCostCode->code.' · '.$invoice->jobCostCode->name : '—' }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Invoice Date') }}: {{ $invoice->invoice_date?->format('Y-m-d') }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Due Date') }}: {{ $invoice->due_date?->format('Y-m-d') }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('PO') }}: {{ $invoice->purchase_order_id ?? '—' }}</p>
            @if($this->matchingEvaluation())
                <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('PO Match') }}: {{ str_replace('_', ' ', $this->matchingEvaluation()['overall_status']) }}</p>
            @endif
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Approval') }}: {{ Str::headline(str_replace('_', ' ', $invoice->approvalStatusLabel())) }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Workflow') }}: {{ Str::headline(str_replace('_', ' ', $invoice->workflowStateLabel())) }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Payment') }}: {{ Str::headline(str_replace('_', ' ', $invoice->paymentStateLabel())) }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Period Finalization') }}: {{ $invoice->periodFinalizationLabel() }}</p>
            @if($invoice->expenseProfile?->channel)
                <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Expense Channel') }}: {{ Str::headline(str_replace('_', ' ', $invoice->expenseProfile->channel)) }}</p>
            @endif
            @if($invoice->expenseProfile?->wallet)
                <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Wallet') }}: {{ $invoice->expenseProfile->wallet->driver_name ?: $invoice->expenseProfile->wallet->driver_id }}</p>
            @endif
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Reference') }}: {{ $invoice->reference_number ?: '—' }}</p>
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

    @php($revisionHistory = $this->revisionHistory())
    @if($revisionHistory->count() > 1 || $invoice->isRevision())
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
            <div>
                <h3 class="text-sm font-semibold text-neutral-800 dark:text-neutral-200">{{ __('Version History') }}</h3>
                <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Posted versions remain immutable; corrections continue as linked drafts.') }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @foreach($revisionHistory as $version)
                    <flux:button
                        :href="route('payables.invoices.show', $version)"
                        wire:navigate
                        size="sm"
                        :variant="$version->is($invoice) ? 'primary' : 'ghost'"
                    >
                        {{ $version->revision_number > 0 ? __('Version V:version', ['version' => $version->revision_number]) : __('Original') }}
                        · {{ $version->invoice_number }}
                        · {{ Str::headline($version->status) }}
                    </flux:button>
                @endforeach
            </div>
        </div>
    @endif

    @if($invoice->is_expense && $invoice->attachments->isEmpty())
        <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100">
            {{ __('No supporting attachments have been added yet. Expense approvals may escalate until receipts are uploaded.') }}
        </div>
    @endif

    @if($invoice->isPeriodFinalized())
        <div class="rounded-md border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800 dark:border-sky-900 dark:bg-sky-950 dark:text-sky-100">
            {{ __('This document is finalized because its accounting period is closed. Financial changes and attachment changes are no longer allowed.') }}
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
                    <x-payables.attachment-preview :attachment="$attachment" :can-delete="$this->canManageAttachments()" />
                @endforeach
            </div>
        @else
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('No attachments added yet.') }}</p>
        @endif
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <h3 class="text-sm font-semibold text-neutral-800 dark:text-neutral-200 mb-2">{{ __('Line Items') }}</h3>
        <div class="overflow-x-auto">
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
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <h3 class="text-sm font-semibold text-neutral-800 dark:text-neutral-200 mb-2">{{ __('Allocations') }}</h3>
        <div class="overflow-x-auto">
        <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Payment Date') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Amount') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Method') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Reference') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Voucher') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                @forelse ($invoice->allocations as $alloc)
                    <tr>
                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $alloc->payment->payment_date?->format('Y-m-d') }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ number_format((float)$alloc->allocated_amount, 2) }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $alloc->payment->payment_method }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $alloc->payment->reference ?: '—' }}</td>
                        <td class="px-3 py-2 text-right text-sm">
                            @if(auth()->user()?->hasAnyRole(['admin', 'manager']) || auth()->user()?->can('finance.access'))
                                <flux:button :href="route('payables.payments.voucher', $alloc->payment)" target="_blank" size="xs" variant="ghost" icon="printer">
                                    {{ __('Payment Voucher') }}
                                </flux:button>
                            @else
                                <span class="text-neutral-400">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-3 py-3 text-sm text-neutral-600 dark:text-neutral-300 text-center">{{ __('No allocations yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>

    @if($this->canManageInvoice())
        <flux:modal name="revise-ap-invoice-modal" :show="$errors->has('status') || $errors->has('document_type') || $errors->has('ledger') || $errors->has('invoice_number') || $errors->has('attachment') || $errors->has('payment') || $errors->has('wallet_id') || $errors->has('period')" focusable class="max-w-lg">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">
                        {{ $this->canCorrectClosedExpense() ? __('Correct Closed Expense') : __('Create Editable Invoice Version') }}
                    </flux:heading>
                    <flux:subheading>
                        @if($this->canCorrectClosedExpense())
                            {{ __('The linked payment will be reversed first. Petty cash funds, when applicable, will be returned to the wallet. The original expense will remain as void audit history, and a linked draft can be opened for correction.') }}
                        @else
                            {{ __('The posted invoice will be voided and reversed. A linked draft version will open for editing, with independent copies of the existing attachments.') }}
                        @endif
                    </flux:subheading>
                </div>

                <flux:input wire:model="revision_reason" :label="__('Correction reason (optional)')" />
                @foreach(['status', 'document_type', 'ledger', 'invoice_number', 'attachment', 'payment', 'wallet_id', 'period'] as $revisionError)
                    @error($revisionError) <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                @endforeach

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    @if($this->canCorrectClosedExpense())
                        <flux:button
                            type="button"
                            wire:click="voidClosedExpense"
                            wire:confirm="{{ __('Reverse the settlement and permanently void this expense without creating a replacement draft?') }}"
                            variant="danger"
                        >
                            {{ __('Void Expense') }}
                        </flux:button>
                        <flux:button type="button" wire:click="revise" variant="primary">
                            {{ __('Reverse & Create Draft') }}
                        </flux:button>
                    @else
                        <flux:button type="button" wire:click="revise" variant="primary">
                            {{ __('Void & Create Draft') }}
                        </flux:button>
                    @endif
                </div>
            </div>
        </flux:modal>
    @endif
</div>
