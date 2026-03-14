<?php

use App\Models\ApInvoice;
use App\Models\ApInvoiceItem;
use App\Models\ExpenseCategory;
use App\Models\PettyCashWallet;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\AP\ApInvoicePostingService;
use App\Services\AP\ApInvoiceTotalsService;
use App\Services\Spend\ExpenseWorkflowService;
use App\Support\AP\DocumentTypeMap;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public ?int $supplier_id = null;
    public ?int $purchase_order_id = null;
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

    public function mount(): void
    {
        $requestedType = (string) request()->query('document_type', '');
        if ($requestedType === '') {
            $this->redirectRoute('payables.create', navigate: true);
            return;
        }

        $this->document_type = DocumentTypeMap::normalizeDocumentType($requestedType);
        $this->expense_channel = DocumentTypeMap::normalizeExpenseChannel(
            $this->document_type,
            request()->query('expense_channel')
        );
        $this->invoice_date = now()->toDateString();
        $this->due_date = now()->addDays(30)->toDateString();
        $this->lines = [['description' => '', 'quantity' => 1, 'unit_price' => 0, 'line_total' => 0]];

        if ($this->expense_channel === 'petty_cash' && config('spend.petty_cash_internal_supplier_id')) {
            $this->supplier_id = (int) config('spend.petty_cash_internal_supplier_id');
        }
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
        } elseif (! $this->supplier_id && config('spend.petty_cash_internal_supplier_id')) {
            $this->supplier_id = (int) config('spend.petty_cash_internal_supplier_id');
        }
    }

    public function addLine(): void
    {
        $this->lines[] = ['description' => '', 'quantity' => 1, 'unit_price' => 0, 'line_total' => 0];
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

    public function importPo(): void
    {
        if (! $this->purchase_order_id || ! Schema::hasTable('purchase_orders')) {
            return;
        }

        $po = PurchaseOrder::with(['items.item'])->find($this->purchase_order_id);
        if (! $po) {
            $this->addError('purchase_order_id', __('Purchase order not found.'));
            return;
        }

        if ($this->supplier_id && $po->supplier_id !== $this->supplier_id) {
            $this->addError('purchase_order_id', __('PO supplier must match the selected supplier.'));
            return;
        }

        $this->supplier_id = $po->supplier_id;
        $this->lines = $po->items->map(function ($item) {
            $inv = $item->item;
            $code = $inv->item_code ?? $inv->code ?? null;
            $name = $inv->name ?? $inv->description ?? null;
            $label = trim(($code ? '['.$code.'] ' : '').($name ?? ''), ' []');

            return [
                'description' => $label !== '' ? $label : ($item->description ?? __('Item')),
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'line_total' => round((float) $item->quantity * (float) $item->unit_price, 2),
            ];
        })->toArray();
    }

    public function saveDraft(ApInvoiceTotalsService $totalsService, ExpenseWorkflowService $expenseWorkflowService): void
    {
        $this->persist('draft', $totalsService, $expenseWorkflowService);
    }

    public function saveAndPost(ApInvoicePostingService $postingService, ApInvoiceTotalsService $totalsService, ExpenseWorkflowService $expenseWorkflowService): void
    {
        $invoice = $this->persist('draft', $totalsService, $expenseWorkflowService, false);
        if (! $invoice) {
            return;
        }

        if (DocumentTypeMap::isExpense($this->document_type)) {
            session()->flash('status', __('Expense drafts must be approved before posting.'));
            $this->redirectRoute('payables.invoices.show', $invoice, navigate: true);
            return;
        }

        $postingService->post($invoice, (int) auth()->id());
        session()->flash('status', __('Document posted.'));
        $this->redirectRoute('payables.invoices.show', $invoice, navigate: true);
    }

    private function persist(string $status, ApInvoiceTotalsService $totalsService, ExpenseWorkflowService $expenseWorkflowService, bool $redirect = true): ?ApInvoice
    {
        $this->recalc();
        $this->lines = collect($this->lines)
            ->filter(fn ($line) => ! empty($line['description']) && (float) ($line['quantity'] ?? 0) > 0)
            ->values()
            ->toArray();

        $data = $this->validate($this->rules());
        $document = DocumentTypeMap::derive($data['document_type'], $data['expense_channel'] ?? null);
        $supplierId = $this->resolveSupplierId($data, $document['expense_channel']);

        if (($data['purchase_order_id'] ?? null) !== null) {
            $po = PurchaseOrder::query()->find($data['purchase_order_id']);
            if (! $po || $po->supplier_id !== $supplierId) {
                $this->addError('purchase_order_id', __('The purchase order must belong to the same supplier.'));
                return null;
            }
        }

        $invoice = DB::transaction(function () use ($data, $status, $totalsService, $expenseWorkflowService, $document, $supplierId) {
            $invoice = ApInvoice::query()->create([
                'supplier_id' => $supplierId,
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'is_expense' => $document['is_expense'],
                'document_type' => $data['document_type'],
                'currency_code' => config('pos.currency', 'QAR'),
                'invoice_number' => $data['invoice_number'],
                'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'],
                'subtotal' => 0,
                'tax_amount' => $data['tax_amount'],
                'total_amount' => 0,
                'status' => $status,
                'notes' => $data['notes'] ?? null,
                'created_by' => (int) auth()->id(),
            ]);

            foreach ($data['lines'] as $item) {
                $lineTotal = round((float) $item['quantity'] * (float) $item['unit_price'], 2);
                ApInvoiceItem::query()->create([
                    'invoice_id' => $invoice->id,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'line_total' => $lineTotal,
                ]);
            }

            $totalsService->recalc($invoice);

            if ($document['is_expense']) {
                $expenseWorkflowService->initializeProfile(
                    $invoice,
                    (string) ($document['expense_channel'] ?? 'vendor'),
                    isset($data['wallet_id']) ? (int) $data['wallet_id'] : null
                );
            }

            return $invoice->fresh(['items', 'expenseProfile']);
        });

        session()->flash('status', __('Document saved.'));
        if ($redirect) {
            $this->redirectRoute('payables.invoices.show', $invoice, navigate: true);
        }

        return $invoice;
    }

    private function rules(): array
    {
        $supplierId = $this->invoiceSupplierId();

        return [
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'purchase_order_id' => ['nullable', 'integer', 'exists:purchase_orders,id'],
            'category_id' => ['nullable', 'integer', 'exists:expense_categories,id'],
            'document_type' => ['required', Rule::in(DocumentTypeMap::types())],
            'expense_channel' => ['nullable', 'in:vendor,petty_cash,reimbursement'],
            'wallet_id' => ['nullable', 'integer', 'exists:petty_cash_wallets,id'],
            'invoice_number' => [
                'required',
                'string',
                'max:100',
                Rule::unique('ap_invoices', 'invoice_number')->where(fn ($q) => $q->where('supplier_id', $supplierId)),
            ],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:invoice_date'],
            'tax_amount' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
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

        throw new \RuntimeException('Supplier is required.');
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

        return null;
    }

    public function suppliers()
    {
        return Schema::hasTable('suppliers') ? Supplier::query()->orderBy('name')->get() : collect();
    }

    public function purchaseOrders()
    {
        if (! Schema::hasTable('purchase_orders')) {
            return collect();
        }

        return PurchaseOrder::query()
            ->orderByDesc('id')
            ->when($this->supplier_id, fn ($query) => $query->where('supplier_id', $this->supplier_id))
            ->get();
    }

    public function categories()
    {
        return Schema::hasTable('expense_categories') ? ExpenseCategory::query()->orderBy('name')->get() : collect();
    }

    public function wallets()
    {
        if (! Schema::hasTable('petty_cash_wallets')) {
            return collect();
        }

        return PettyCashWallet::query()->where('active', 1)->orderBy('driver_name')->get();
    }

    public function documentTypeOptions(): array
    {
        return DocumentTypeMap::labels();
    }
}; ?>

<div class="w-full max-w-5xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Create').' '.DocumentTypeMap::label($document_type) }}</h1>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Document type drives the approval and accounting workflow.') }}</p>
        </div>
        <div class="flex gap-2">
            <flux:button :href="route('payables.create')" wire:navigate variant="ghost">{{ __('Change Type') }}</flux:button>
            <flux:button :href="route('payables.index')" wire:navigate variant="ghost">{{ __('Back') }}</flux:button>
        </div>
    </div>

    <form wire:submit="saveDraft" class="space-y-6">
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
                    @error('supplier_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
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
                        <flux:button type="button" wire:click="importPo" size="sm" class="mt-2">{{ __('Import PO Lines') }}</flux:button>
                        @error('purchase_order_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                @endif
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
                        @error('category_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
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
                            @error('wallet_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                    @endif
                </div>
            @endif

            <flux:textarea wire:model="notes" :label="__('Notes')" rows="2" />
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
                            @error("lines.$index.description") <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
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

            <div class="grid grid-cols-1 gap-4 md:grid-cols-[1fr,180px]">
                <div></div>
                <flux:input wire:model="tax_amount" type="number" step="0.01" min="0" :label="__('Tax')" />
            </div>

            <div class="flex justify-end">
                <div class="text-right">
                    <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Subtotal') }}</p>
                    <p class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format(collect($lines)->sum(fn ($line) => (float) ($line['line_total'] ?? 0)), 2) }}</p>
                    <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Total') }}</p>
                    <p class="text-2xl font-bold text-neutral-900 dark:text-neutral-100">{{ number_format(collect($lines)->sum(fn ($line) => (float) ($line['line_total'] ?? 0)) + (float) $tax_amount, 2) }}</p>
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-3">
            <flux:button :href="route('payables.index')" wire:navigate variant="ghost">{{ __('Cancel') }}</flux:button>
            <flux:button type="submit" variant="ghost">{{ __('Save Draft') }}</flux:button>
            <flux:button type="button" wire:click="saveAndPost">{{ __('Save and Post') }}</flux:button>
        </div>
    </form>
</div>
