<?php

use App\Models\ApInvoice;
use App\Models\ApInvoiceItem;
use App\Models\ExpenseCategory;
use App\Models\ExpenseProfile;
use App\Models\Job;
use App\Models\JobCostCode;
use App\Models\JobPhase;
use App\Models\PettyCashWallet;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\AP\ApInvoiceAttachmentService;
use App\Services\AP\SupplierAccountingPolicyService;
use App\Services\AP\ApInvoiceTotalsService;
use App\Services\Spend\ExpenseWorkflowService;
use App\Support\AP\DocumentTypeMap;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.app')] class extends Component {
    use WithFileUploads;

    public ApInvoice $invoice;
    public ?int $supplier_id = null;
    public ?int $purchase_order_id = null;
    public ?int $job_id = null;
    public ?int $job_phase_id = null;
    public ?int $job_cost_code_id = null;
    public string $document_type = 'vendor_bill';
    public ?int $category_id = null;
    public ?string $expense_channel = null;
    public ?int $wallet_id = null;
    public string $invoice_number = '';
    public ?string $invoice_date = null;
    public ?string $due_date = null;
    public float $tax_amount = 0.0;
    public ?string $notes = null;
    public array $lines = [];
    public array $new_attachments = [];

    public function mount(ApInvoice $invoice): void
    {
        $this->invoice = $invoice->load(['items', 'expenseProfile', 'attachments']);

        if (in_array($invoice->status, ['partially_paid', 'paid', 'void'], true) || ($invoice->status === 'posted' && $invoice->allocations()->exists())) {
            session()->flash('status', __('Cannot edit this document in its current state.'));
            $this->redirectRoute('payables.invoices.show', $invoice, navigate: true);
            return;
        }

        $this->supplier_id = $invoice->supplier_id;
        $this->purchase_order_id = $invoice->purchase_order_id;
        $this->job_id = $invoice->job_id;
        $this->job_phase_id = $invoice->job_phase_id;
        $this->job_cost_code_id = $invoice->job_cost_code_id;
        $this->document_type = DocumentTypeMap::normalizeDocumentType($invoice->document_type);
        $this->category_id = $invoice->category_id;
        $this->expense_channel = DocumentTypeMap::normalizeExpenseChannel($this->document_type, $invoice->expenseProfile?->channel);
        $this->wallet_id = $invoice->expenseProfile?->wallet_id;
        $this->invoice_number = $invoice->invoice_number;
        $this->invoice_date = optional($invoice->invoice_date)?->format('Y-m-d');
        $this->due_date = optional($invoice->due_date)?->format('Y-m-d');
        $this->tax_amount = (float) $invoice->tax_amount;
        $this->notes = $invoice->notes;
        $this->lines = $invoice->items->map(fn ($line) => [
            'purchase_order_item_id' => $line->purchase_order_item_id,
            'description' => $line->description,
            'quantity' => $line->quantity,
            'unit_price' => $line->unit_price,
            'line_total' => $line->line_total,
        ])->toArray();
    }

    public function updatedDocumentType(): void
    {
        $this->document_type = DocumentTypeMap::normalizeDocumentType($this->document_type);
        $this->expense_channel = DocumentTypeMap::normalizeExpenseChannel($this->document_type, $this->expense_channel);

        if (! DocumentTypeMap::isExpense($this->document_type)) {
            $this->category_id = null;
            $this->wallet_id = null;
        }
    }

    public function updatedExpenseChannel(): void
    {
        $this->expense_channel = DocumentTypeMap::normalizeExpenseChannel($this->document_type, $this->expense_channel);

        if ($this->expense_channel !== 'petty_cash') {
            $this->wallet_id = null;
        }
    }

    public function updatedSupplierId(): void
    {
        $supplier = $this->selectedSupplier();
        if ($supplier && app(SupplierAccountingPolicyService::class)->blocksDraft($supplier)) {
            $this->addError('supplier_id', app(SupplierAccountingPolicyService::class)->draftBlockedMessage($supplier));
            return;
        }

        $this->resetErrorBag('supplier_id');
    }

    public function updatedJobId(): void
    {
        if (! $this->job_id) {
            $this->job_phase_id = null;
            $this->job_cost_code_id = null;
            return;
        }

        if ($this->job_phase_id && ! $this->phaseOptions()->contains('id', (int) $this->job_phase_id)) {
            $this->job_phase_id = null;
        }

        if ($this->job_cost_code_id && ! $this->costCodeOptions()->contains('id', (int) $this->job_cost_code_id)) {
            $this->job_cost_code_id = null;
        }
    }

    public function addLine(): void
    {
        $this->lines[] = ['purchase_order_item_id' => null, 'description' => '', 'quantity' => 1, 'unit_price' => 0, 'line_total' => 0];
    }

    public function removeLine(int $idx): void
    {
        unset($this->lines[$idx]);
        $this->lines = array_values($this->lines);
    }

    public function updatedLines(): void
    {
        $this->recalc();
    }

    public function save(ApInvoiceTotalsService $totalsService, ExpenseWorkflowService $expenseWorkflowService, ApInvoiceAttachmentService $attachmentService): void
    {
        $this->recalc();
        $this->lines = collect($this->lines)
            ->filter(fn ($line) => ! empty($line['description']) && (float) ($line['quantity'] ?? 0) > 0)
            ->values()
            ->toArray();

        $data = $this->validate($this->rules());
        $document = DocumentTypeMap::derive($data['document_type'], $data['expense_channel'] ?? null);
        $supplierId = $this->resolveSupplierId($data, $document['expense_channel']);
        app(SupplierAccountingPolicyService::class)->assertCanCreateDraft(Supplier::query()->find($supplierId));

        if ($this->invoice->status === 'posted' && $this->invoice->allocations()->exists()) {
            throw ValidationException::withMessages(['status' => __('Cannot edit a posted document with allocations.')]);
        }

        DB::transaction(function () use ($data, $totalsService, $expenseWorkflowService, $document, $supplierId) {
            $this->invoice->update([
                'supplier_id' => $supplierId,
                'job_id' => $data['job_id'] ?? null,
                'job_phase_id' => $data['job_phase_id'] ?? null,
                'job_cost_code_id' => $data['job_cost_code_id'] ?? null,
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'is_expense' => $document['is_expense'],
                'document_type' => $data['document_type'],
                'invoice_number' => $data['invoice_number'],
                'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'],
                'tax_amount' => $data['tax_amount'],
                'notes' => $data['notes'] ?? null,
            ]);

            $this->invoice->items()->delete();
            foreach ($data['lines'] as $item) {
                $lineTotal = round((float) $item['quantity'] * (float) $item['unit_price'], 2);
                ApInvoiceItem::query()->create([
                    'invoice_id' => $this->invoice->id,
                    'purchase_order_item_id' => $item['purchase_order_item_id'] ?? null,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'line_total' => $lineTotal,
                ]);
            }

            $totalsService->recalc($this->invoice);

            if ($document['is_expense']) {
                $expenseWorkflowService->initializeProfile(
                    $this->invoice,
                    (string) ($document['expense_channel'] ?? 'vendor'),
                    isset($data['wallet_id']) ? (int) $data['wallet_id'] : null
                );
            } else {
                ExpenseProfile::query()->where('invoice_id', $this->invoice->id)->delete();
            }
        });

        $this->storeAttachments($attachmentService);

        session()->flash('status', __('Document updated.'));
        $this->redirectRoute('payables.invoices.show', $this->invoice, navigate: true);
    }

    private function rules(): array
    {
        $supplierId = $this->invoiceSupplierId();

        return [
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'purchase_order_id' => ['nullable', 'integer', 'exists:purchase_orders,id'],
            'job_id' => ['nullable', 'integer', 'exists:accounting_jobs,id'],
            'job_phase_id' => ['nullable', 'integer', 'exists:accounting_job_phases,id'],
            'job_cost_code_id' => ['nullable', 'integer', 'exists:accounting_job_cost_codes,id'],
            'category_id' => ['nullable', 'integer', 'exists:expense_categories,id'],
            'document_type' => ['required', Rule::in(DocumentTypeMap::types())],
            'expense_channel' => ['nullable', 'in:vendor,petty_cash,reimbursement'],
            'wallet_id' => ['nullable', 'integer', 'exists:petty_cash_wallets,id'],
            'invoice_number' => [
                'required',
                'string',
                'max:100',
                Rule::unique('ap_invoices', 'invoice_number')
                    ->where(fn ($query) => $query->where('supplier_id', $supplierId))
                    ->ignore($this->invoice->id),
            ],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:invoice_date'],
            'tax_amount' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'new_attachments' => ['nullable', 'array'],
            'new_attachments.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:7096'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_order_item_id' => ['nullable', 'integer', 'exists:purchase_order_items,id'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $documentType = DocumentTypeMap::normalizeDocumentType($this->document_type);
            $expenseChannel = DocumentTypeMap::normalizeExpenseChannel($documentType, $this->expense_channel);

            if ($this->shouldRequireSupplier($documentType, $expenseChannel) && empty($this->supplier_id)) {
                $validator->errors()->add('supplier_id', __('Supplier is required for this document.'));
            }

            if (DocumentTypeMap::requiresCategory($documentType) && Schema::hasTable('expense_categories') && empty($this->category_id)) {
                $validator->errors()->add('category_id', __('Category is required for this document type.'));
            }

            if (DocumentTypeMap::requiresWallet($documentType, $expenseChannel) && empty($this->wallet_id)) {
                $validator->errors()->add('wallet_id', __('Wallet is required for petty cash expenses.'));
            }

            $supplier = $this->selectedSupplier();
            if ($supplier && app(SupplierAccountingPolicyService::class)->blocksDraft($supplier)) {
                $validator->errors()->add('supplier_id', app(SupplierAccountingPolicyService::class)->draftBlockedMessage($supplier));
            }

            if (($this->job_phase_id || $this->job_cost_code_id) && ! $this->job_id) {
                $validator->errors()->add('job_id', __('Select a job before assigning a phase or cost code.'));
            }

            if ($this->job_phase_id && ! $this->phaseOptions()->contains('id', (int) $this->job_phase_id)) {
                $validator->errors()->add('job_phase_id', __('The selected phase must belong to the selected job.'));
            }

            if ($this->job_cost_code_id && ! $this->costCodeOptions()->contains('id', (int) $this->job_cost_code_id)) {
                $validator->errors()->add('job_cost_code_id', __('The selected cost code must be active and belong to the job company.'));
            }
        });
    }

    private function recalc(): void
    {
        foreach ($this->lines as $index => $line) {
            $qty = (float) ($line['quantity'] ?? 0);
            $unit = (float) ($line['unit_price'] ?? 0);
            $this->lines[$index]['line_total'] = round($qty * $unit, 2);
        }
    }

    private function shouldRequireSupplier(string $documentType, ?string $expenseChannel): bool
    {
        return ! ($documentType === 'expense'
            && $expenseChannel === 'petty_cash'
            && (int) config('spend.petty_cash_internal_supplier_id', 0) > 0);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function resolveSupplierId(array $data, ?string $expenseChannel): int
    {
        $supplierId = isset($data['supplier_id']) ? (int) $data['supplier_id'] : 0;
        if ($supplierId > 0) {
            return $supplierId;
        }

        if ($expenseChannel === 'petty_cash') {
            $fallbackSupplierId = (int) config('spend.petty_cash_internal_supplier_id', 0);
            if ($fallbackSupplierId > 0) {
                return $fallbackSupplierId;
            }
        }

        throw ValidationException::withMessages(['supplier_id' => __('Supplier is required for this document.')]);
    }

    private function invoiceSupplierId(): ?int
    {
        if ($this->supplier_id) {
            return (int) $this->supplier_id;
        }

        if ($this->document_type === 'expense' && $this->expense_channel === 'petty_cash') {
            $fallbackSupplierId = (int) config('spend.petty_cash_internal_supplier_id', 0);

            return $fallbackSupplierId > 0 ? $fallbackSupplierId : null;
        }

        return $this->invoice->supplier_id ? (int) $this->invoice->supplier_id : null;
    }

    public function suppliers()
    {
        return Schema::hasTable('suppliers') ? Supplier::query()->orderBy('name')->get() : collect();
    }

    public function purchaseOrders()
    {
        return Schema::hasTable('purchase_orders')
            ? PurchaseOrder::query()->orderByDesc('id')->get()
            : collect();
    }

    public function categories()
    {
        return Schema::hasTable('expense_categories') ? ExpenseCategory::query()->orderBy('name')->get() : collect();
    }

    public function jobs()
    {
        return Schema::hasTable('accounting_jobs') ? Job::query()->orderBy('code')->get() : collect();
    }

    public function selectedJob(): ?Job
    {
        return $this->job_id ? Job::query()->find((int) $this->job_id) : null;
    }

    public function phaseOptions()
    {
        if (! Schema::hasTable('accounting_job_phases') || ! $this->job_id) {
            return collect();
        }

        return JobPhase::query()
            ->where('job_id', $this->job_id)
            ->where('status', 'active')
            ->orderBy('code')
            ->get();
    }

    public function costCodeOptions()
    {
        $job = $this->selectedJob();
        if (! Schema::hasTable('accounting_job_cost_codes') || ! $job?->company_id) {
            return collect();
        }

        return JobCostCode::query()
            ->where('company_id', $job->company_id)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();
    }

    public function wallets()
    {
        return Schema::hasTable('petty_cash_wallets')
            ? PettyCashWallet::query()->where('active', 1)->orderBy('driver_name')->get()
            : collect();
    }

    public function documentTypeOptions(): array
    {
        return DocumentTypeMap::labels();
    }

    public function deleteAttachment(int $attachmentId, ApInvoiceAttachmentService $attachmentService): void
    {
        $attachment = $this->invoice->attachments()->findOrFail($attachmentId);
        $attachmentService->delete($attachment, (int) auth()->id());
        $this->invoice = $this->invoice->fresh(['items', 'expenseProfile', 'attachments']);
        session()->flash('status', __('Attachment deleted.'));
    }

    public function supplierDraftWarning(): ?string
    {
        return app(SupplierAccountingPolicyService::class)->draftWarning($this->selectedSupplier());
    }

    public function selectedSupplier(): ?Supplier
    {
        $supplierId = $this->supplier_id ?: $this->invoice->supplier_id;

        return $supplierId ? Supplier::query()->find((int) $supplierId) : null;
    }

    private function storeAttachments(ApInvoiceAttachmentService $attachmentService): void
    {
        foreach ($this->new_attachments as $file) {
            $attachmentService->upload($this->invoice, $file, (int) auth()->id());
        }

        $this->new_attachments = [];
        $this->invoice = $this->invoice->fresh(['items', 'expenseProfile', 'attachments']);
    }
}; ?>

<div class="w-full max-w-5xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Edit').' '.DocumentTypeMap::label($document_type) }}</h1>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Draft documents can still be reclassified before posting or approval.') }}</p>
        </div>
        <div class="flex gap-2">
            <flux:button :href="route('payables.invoices.show', $invoice)" wire:navigate variant="ghost">{{ __('Back') }}</flux:button>
        </div>
    </div>

    <form wire:submit="save" class="space-y-6">
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div>
                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Document Type') }}</label>
                    <select wire:model.live="document_type" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        @foreach($this->documentTypeOptions() as $type => $label)
                            <option value="{{ $type }}">{{ __($label) }}</option>
                        @endforeach
                    </select>
                </div>
                @if($document_type === 'expense')
                    <div>
                        <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Expense Channel') }}</label>
                        <select wire:model.live="expense_channel" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                            <option value="vendor">{{ __('Vendor') }}</option>
                            <option value="petty_cash">{{ __('Petty Cash') }}</option>
                        </select>
                    </div>
                @endif
                <flux:input wire:model="invoice_number" :label="__('Document #')" />
                <flux:input wire:model="invoice_date" type="date" :label="__('Document Date')" />
                <flux:input wire:model="due_date" type="date" :label="__('Due Date')" />
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Supplier') }}</label>
                    <select wire:model="supplier_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="">{{ __('Select supplier') }}</option>
                        @foreach($this->suppliers() as $supplier)
                            <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                        @endforeach
                    </select>
                </div>

                @if(in_array($document_type, ['vendor_bill', 'recurring_bill', 'landed_cost_adjustment'], true))
                    <div>
                        <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Purchase Order (optional)') }}</label>
                        <select wire:model="purchase_order_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                            <option value="">{{ __('None') }}</option>
                            @foreach($this->purchaseOrders() as $po)
                                <option value="{{ $po->id }}">{{ $po->po_number }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <div>
                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Job') }}</label>
                    <select wire:model.live="job_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="">{{ __('Unassigned') }}</option>
                        @foreach($this->jobs() as $job)
                            <option value="{{ $job->id }}">{{ $job->code }} · {{ $job->name }}</option>
                        @endforeach
                    </select>
                    @error('job_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Phase') }}</label>
                    <select wire:model="job_phase_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" @disabled(! $job_id)>
                        <option value="">{{ $job_id ? __('None') : __('Select a job first') }}</option>
                        @foreach($this->phaseOptions() as $phase)
                            <option value="{{ $phase->id }}">{{ $phase->code }} · {{ $phase->name }}</option>
                        @endforeach
                    </select>
                    @error('job_phase_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Cost Code') }}</label>
                    <select wire:model="job_cost_code_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" @disabled(! $job_id)>
                        <option value="">{{ $job_id ? __('None') : __('Select a job first') }}</option>
                        @foreach($this->costCodeOptions() as $costCode)
                            <option value="{{ $costCode->id }}">{{ $costCode->code }} · {{ $costCode->name }}</option>
                        @endforeach
                    </select>
                    @error('job_cost_code_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
            </div>

            @if(DocumentTypeMap::requiresCategory($document_type) || $document_type === 'landed_cost_adjustment')
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Category') }}</label>
                        <select wire:model="category_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                            <option value="">{{ __('Select category') }}</option>
                            @foreach($this->categories() as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    @if(DocumentTypeMap::requiresWallet($document_type, $expense_channel))
                        <div>
                            <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Petty Cash Wallet') }}</label>
                            <select wire:model="wallet_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                <option value="">{{ __('Select wallet') }}</option>
                                @foreach($this->wallets() as $wallet)
                                    <option value="{{ $wallet->id }}">{{ $wallet->driver_name ?: $wallet->driver_id }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                </div>
            @endif

            <flux:textarea wire:model="notes" :label="__('Notes')" rows="2" />

            @if($this->supplierDraftWarning())
                <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100">
                    {{ $this->supplierDraftWarning() }}
                </div>
            @endif
        </div>

        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
            <div>
                <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Attachments') }}</h2>
                <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Manage receipts and supporting documents for this draft.') }}</p>
            </div>
            <flux:input type="file" wire:model="new_attachments" accept=".jpg,.jpeg,.png,.webp,.pdf" multiple :label="__('Add Files')" />
            @error('new_attachments.*') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror

            @if($invoice->attachments->isNotEmpty())
                <div class="space-y-2">
                    @foreach($invoice->attachments as $attachment)
                        <div class="flex items-center justify-between rounded-md border border-neutral-200 px-3 py-2 text-sm dark:border-neutral-700">
                            <span class="truncate text-neutral-800 dark:text-neutral-100">{{ $attachment->original_name }}</span>
                            <flux:button type="button" wire:click="deleteAttachment({{ $attachment->id }})" variant="ghost" size="sm">{{ __('Delete') }}</flux:button>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('No attachments added yet.') }}</p>
            @endif
        </div>

        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Line Items') }}</h2>
                <flux:button type="button" wire:click="addLine">{{ __('Add Line') }}</flux:button>
            </div>

            <div class="space-y-3">
                @foreach ($lines as $index => $line)
                    <div class="grid grid-cols-1 items-end gap-3 rounded-lg border border-neutral-200 p-3 md:grid-cols-12 dark:border-neutral-700">
                        <div class="md:col-span-6">
                            <flux:input wire:model="lines.{{ $index }}.description" :label="__('Description')" />
                        </div>
                        <div class="md:col-span-2">
                            <flux:input wire:model.live="lines.{{ $index }}.quantity" type="number" step="0.001" min="0.001" :label="__('Qty')" />
                        </div>
                        <div class="md:col-span-2">
                            <flux:input wire:model.live="lines.{{ $index }}.unit_price" type="number" step="0.0001" min="0" :label="__('Unit Price')" />
                        </div>
                        <div class="md:col-span-1">
                            <label class="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Line Total') }}</label>
                            <div class="rounded-md border border-neutral-200 bg-neutral-50 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                {{ number_format((float) ($line['line_total'] ?? 0), 2) }}
                            </div>
                        </div>
                        <div class="md:col-span-1 flex justify-end">
                            <flux:button type="button" wire:click="removeLine({{ $index }})" variant="ghost">{{ __('Remove') }}</flux:button>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="flex justify-end">
                <div class="text-right">
                    <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Subtotal') }}</p>
                    <p class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format(collect($lines)->sum(fn ($line) => (float) ($line['line_total'] ?? 0)), 2) }}</p>
                    <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Tax') }}</p>
                    <p class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format((float) $tax_amount, 2) }}</p>
                    <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Total') }}</p>
                    <p class="text-xl font-bold text-neutral-900 dark:text-neutral-100">{{ number_format(collect($lines)->sum(fn ($line) => (float) ($line['line_total'] ?? 0)) + (float) $tax_amount, 2) }}</p>
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-3">
            <flux:button type="submit">{{ __('Save Changes') }}</flux:button>
        </div>
    </form>
</div>
