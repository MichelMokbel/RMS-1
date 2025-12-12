<?php

use App\Models\ApInvoice;
use App\Models\ApInvoiceItem;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\AP\ApInvoiceTotalsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades.Schema;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Illuminate\Validation\ValidationException;

new #[Layout('components.layouts.app')] class extends Component {
    public ApInvoice $invoice;
    public ?int $supplier_id = null;
    public ?int $purchase_order_id = null;
    public bool $is_expense = false;
    public ?int $category_id = null;
    public string $invoice_number = '';
    public ?string $invoice_date = null;
    public ?string $due_date = null;
    public float $tax_amount = 0.0;
    public ?string $notes = null;
    public array $lines = [];

    public function mount(ApInvoice $invoice): void
    {
        $this->invoice = $invoice->load('items');

        if (in_array($invoice->status, ['partially_paid', 'paid', 'void'], true) || ($invoice->status === 'posted' && $invoice->allocations()->exists())) {
            session()->flash('status', __('Cannot edit invoice in current status.'));
            $this->redirectRoute('payables.invoices.show', $invoice, navigate: true);
            return;
        }

        $this->supplier_id = $invoice->supplier_id;
        $this->purchase_order_id = $invoice->purchase_order_id;
        $this->is_expense = (bool) $invoice->is_expense;
        $this->category_id = $invoice->category_id;
        $this->invoice_number = $invoice->invoice_number;
        $this->invoice_date = optional($invoice->invoice_date)?->format('Y-m-d');
        $this->due_date = optional($invoice->due_date)?->format('Y-m-d');
        $this->tax_amount = (float) $invoice->tax_amount;
        $this->notes = $invoice->notes;
        $this->lines = $invoice->items->map(function ($line) {
            return [
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'line_total' => $line->line_total,
            ];
        })->toArray();
    }

    public function addLine(): void
    {
        $this->lines[] = ['description' => '', 'quantity' => 1, 'unit_price' => 0];
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

    private function recalc(): void
    {
        foreach ($this->lines as $i => $line) {
            $qty = (float) ($line['quantity'] ?? 0);
            $unit = (float) ($line['unit_price'] ?? 0);
            $this->lines[$i]['line_total'] = round($qty * $unit, 2);
        }
    }

    public function save(ApInvoiceTotalsService $totalsService): void
    {
        $this->recalc();
        $filtered = collect($this->lines)->filter(fn ($l) => ! empty($l['description']) && (float) ($l['quantity'] ?? 0) > 0)->values()->toArray();
        $this->lines = $filtered;

        $data = $this->validate($this->rules());

        if ($this->invoice->status === 'posted' && $this->invoice->allocations()->exists()) {
            throw ValidationException::withMessages(['status' => __('Cannot edit invoice with allocations.')]);
        }

        DB::transaction(function () use ($data, $totalsService) {
            $this->invoice->update([
                'supplier_id' => $data['supplier_id'],
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'is_expense' => $data['is_expense'],
                'invoice_number' => $data['invoice_number'],
                'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'],
                'tax_amount' => $data['tax_amount'],
                'notes' => $data['notes'] ?? null,
            ]);

            $this->invoice->items()->delete();
            foreach ($data['items'] as $item) {
                $lineTotal = round((float) $item['quantity'] * (float) $item['unit_price'], 2);
                ApInvoiceItem::create([
                    'invoice_id' => $this->invoice->id,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'line_total' => $lineTotal,
                ]);
            }

            $totalsService->recalc($this->invoice);
        });

        session()->flash('status', __('Invoice updated.'));
        $this->redirectRoute('payables.invoices.show', $this->invoice, navigate: true);
    }

    private function rules(): array
    {
        $supplierId = $this->supplier_id ?? $this->invoice->supplier_id;
        return [
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'purchase_order_id' => ['nullable', 'integer', 'exists:purchase_orders,id'],
            'category_id' => ['nullable', 'integer'],
            'is_expense' => ['required', 'boolean'],
            'invoice_number' => [
                'required',
                'string',
                'max:100',
                Rule::unique('ap_invoices', 'invoice_number')
                    ->where(fn ($q) => $q->where('supplier_id', $supplierId))
                    ->ignore($this->invoice->id),
            ],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:invoice_date'],
            'tax_amount' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($v) {
            if ($this->is_expense && Schema::hasTable('expense_categories') && empty($this->category_id)) {
                $v->errors()->add('category_id', __('Category is required for expenses.'));
            }
        });
    }

    public function updatedPurchaseOrderId(): void
    {
        if ($this->invoice->status !== 'draft' && $this->purchase_order_id !== $this->invoice->purchase_order_id) {
            $this->addError('purchase_order_id', __('Cannot change PO after posting.'));
            $this->purchase_order_id = $this->invoice->purchase_order_id;
        }
    }

    public function suppliers()
    {
        return Schema::hasTable('suppliers') ? Supplier::orderBy('name')->get() : collect();
    }

    public function purchaseOrders()
    {
        return Schema::hasTable('purchase_orders') ? PurchaseOrder::orderByDesc('id')->get() : collect();
    }
}; ?>

<div class="w-full max-w-5xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Edit AP Invoice') }}</h1>
        <flux:button :href="route('payables.invoices.show', $invoice)" wire:navigate variant="ghost">{{ __('Back') }}</flux:button>
    </div>

    <form wire:submit="save" class="space-y-6">
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Supplier') }}</label>
                    <select wire:model="supplier_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        @foreach($this->suppliers() as $sup)
                            <option value="{{ $sup->id }}">{{ $sup->name }}</option>
                        @endforeach
                    </select>
                    @error('supplier_id') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Purchase Order (optional)') }}</label>
                    <select wire:model="purchase_order_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="">{{ __('None') }}</option>
                        @foreach($this->purchaseOrders() as $po)
                            <option value="{{ $po->id }}">{{ $po->po_number }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <flux:input wire:model="invoice_number" :label="__('Invoice #')" />
                <flux:input wire:model="invoice_date" type="date" :label="__('Invoice Date')" />
                <flux:input wire:model="due_date" type="date" :label="__('Due Date')" />
            </div>
            <div class="flex items-center gap-3">
                <flux:checkbox wire:model="is_expense" :label="__('Expense')" />
                <flux:input wire:model="category_id" type="number" :label="__('Category ID (optional)')" />
            </div>
            <flux:textarea wire:model="notes" :label="__('Notes')" rows="2" />
        </div>

        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Line Items') }}</h2>
                <flux:button type="button" wire:click="addLine">{{ __('Add line') }}</flux:button>
            </div>

            <div class="space-y-3">
                @foreach ($lines as $index => $line)
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-12 items-end rounded-lg border border-neutral-200 p-3 dark:border-neutral-700">
                        <div class="md:col-span-6">
                            <flux:input wire:model="lines.{{ $index }}.description" :label="__('Description')" />
                            @error("lines.$index.description") <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div class="md:col-span-2">
                            <flux:input wire:model.live="lines.{{ $index }}.quantity" type="number" step="0.001" min="0.001" :label="__('Qty')" />
                            @error("lines.$index.quantity") <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div class="md:col-span-2">
                            <flux:input wire:model.live="lines.{{ $index }}.unit_price" type="number" step="0.0001" min="0" :label="__('Unit Price')" />
                            @error("lines.$index.unit_price") <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div class="md:col-span-1">
                            <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-200 mb-1">{{ __('Line Total') }}</label>
                            <div class="rounded-md border border-neutral-200 bg-neutral-50 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                {{ number_format((float) ($line['line_total'] ?? ((float) ($line['quantity'] ?? 0) * (float) ($line['unit_price'] ?? 0))), 2) }}
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
                    <p class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format(collect($lines)->sum(fn ($l) => (float) ($l['line_total'] ?? 0)), 2) }}</p>
                    <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Tax') }}</p>
                    <p class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format((float) $tax_amount, 2) }}</p>
                    <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Total') }}</p>
                    <p class="text-xl font-bold text-neutral-900 dark:text-neutral-100">
                        {{ number_format(collect($lines)->sum(fn ($l) => (float) ($l['line_total'] ?? 0)) + (float) $tax_amount, 2) }}
                    </p>
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-3">
            <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
        </div>
    </form>
</div>
