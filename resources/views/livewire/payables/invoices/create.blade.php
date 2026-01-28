<?php

use App\Models\ApInvoice;
use App\Models\ApInvoiceItem;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\AP\ApInvoicePostingService;
use App\Services\AP\ApInvoiceTotalsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\Schema;

new #[Layout('components.layouts.app')] class extends Component {
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

    public function mount(): void
    {
        $this->invoice_date = now()->toDateString();
        $this->due_date = now()->addDays(30)->toDateString();
        $this->lines = [['description' => '', 'quantity' => 1, 'unit_price' => 0]];
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
            $this->addError('purchase_order_id', __('PO supplier must match invoice supplier.'));
            return;
        }
        if (! $this->supplier_id) {
            $this->supplier_id = $po->supplier_id;
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

    public function saveDraft(ApInvoiceTotalsService $totalsService): void
    {
        $this->persist('draft', $totalsService);
    }

    public function saveAndPost(ApInvoicePostingService $postingService, ApInvoiceTotalsService $totalsService): void
    {
        $invoice = $this->persist('draft', $totalsService, false);
        if ($invoice) {
            $postingService->post($invoice, Illuminate\Support\Facades\Auth::id());
            session()->flash('status', __('Invoice posted.'));
            $this->redirectRoute('payables.invoices.show', $invoice, navigate: true);
        }
    }

    private function persist(string $status, ApInvoiceTotalsService $totalsService, bool $redirect = true): ?ApInvoice
    {
        $this->recalc();
        $filtered = collect($this->lines)->filter(fn ($l) => ! empty($l['description']) && (float) ($l['quantity'] ?? 0) > 0)->values()->toArray();
        $this->lines = $filtered;

        $data = $this->validate($this->rules());

        if ($data['purchase_order_id']) {
            $po = PurchaseOrder::find($data['purchase_order_id']);
            if (! $po || $po->supplier_id !== $data['supplier_id']) {
                $this->addError('purchase_order_id', __('PO must exist and belong to the same supplier.'));
                return null;
            }
        }

        $invoice = DB::transaction(function () use ($data, $status, $totalsService) {
            $invoice = ApInvoice::create([
                'supplier_id' => $data['supplier_id'],
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'is_expense' => $data['is_expense'],
                'invoice_number' => $data['invoice_number'],
                'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'],
                'subtotal' => 0,
                'tax_amount' => $data['tax_amount'],
                'total_amount' => 0,
                'status' => $status,
                'notes' => $data['notes'] ?? null,
                'created_by' => Illuminate\Support\Facades\Auth::id(),
            ]);

            foreach ($data['lines'] as $item) {
                $lineTotal = round((float) $item['quantity'] * (float) $item['unit_price'], 2);
                ApInvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'line_total' => $lineTotal,
                ]);
            }

            $totalsService->recalc($invoice);
            return $invoice->fresh(['items']);
        });

        session()->flash('status', __('Invoice saved.'));
        if ($redirect) {
            $this->redirectRoute('payables.invoices.show', $invoice, navigate: true);
        }

        return $invoice;
    }

    private function rules(): array
    {
        $supplierId = $this->supplier_id;
        return [
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'purchase_order_id' => ['nullable', 'integer', 'exists:purchase_orders,id'],
            'category_id' => ['nullable', 'integer'],
            'is_expense' => ['required', 'boolean'],
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

    public function withValidator($validator)
    {
        $validator->after(function ($v) {
            if ($this->is_expense && Schema::hasTable('expense_categories') && empty($this->category_id)) {
                $v->errors()->add('category_id', __('Category is required for expenses.'));
            }
        });
    }

    public function suppliers()
    {
        return Schema::hasTable('suppliers') ? Supplier::orderBy('name')->get() : collect();
    }

    public function purchaseOrders()
    {
        if (! Schema::hasTable('purchase_orders')) {
            return collect();
        }

        return PurchaseOrder::orderByDesc('id')
            ->when($this->supplier_id, fn ($q) => $q->where('supplier_id', $this->supplier_id))
            ->get();
    }
}; ?>

<div class="w-full max-w-5xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Create AP Invoice') }}</h1>
        <flux:button :href="route('payables.index')" wire:navigate variant="ghost">{{ __('Back') }}</flux:button>
    </div>

    <form wire:submit="saveDraft" class="space-y-6">
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Supplier') }}</label>
                    <select wire:model="supplier_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="">{{ __('Select supplier') }}</option>
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
                    <flux:button type="button" wire:click="importPo" size="sm" class="mt-2">{{ __('Import PO Lines') }}</flux:button>
                    @error('purchase_order_id') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
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

            <div class="overflow-x-auto">
                <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                    <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">#</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Description') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Qty') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Unit Price') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Line Total') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        @foreach ($lines as $index => $line)
                            <tr class="align-top">
                                <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ $loop->iteration }}</td>
                                <td class="px-3 py-3 text-sm">
                                    <flux:input wire:model="lines.{{ $index }}.description" />
                                    @error("lines.$index.description") <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                                </td>
                                <td class="px-3 py-3 text-sm">
                                    <flux:input wire:model.live="lines.{{ $index }}.quantity" type="number" step="0.001" min="0.001" />
                                    @error("lines.$index.quantity") <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                                </td>
                                <td class="px-3 py-3 text-sm">
                                    <flux:input wire:model.live="lines.{{ $index }}.unit_price" type="number" step="0.0001" min="0" />
                                    @error("lines.$index.unit_price") <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                                </td>
                                <td class="px-3 py-3 text-sm text-neutral-900 dark:text-neutral-100">
                                    {{ number_format((float) ($line['line_total'] ?? ((float) ($line['quantity'] ?? 0) * (float) ($line['unit_price'] ?? 0))), 2) }}
                                </td>
                                <td class="px-3 py-3 text-sm text-right">
                                    <flux:button type="button" wire:click="removeLine({{ $index }})" variant="ghost">{{ __('Remove') }}</flux:button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="flex justify-end">
                <div class="text-right">
                    <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Subtotal') }}</p>
                    <p class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format(collect($lines)->sum(fn ($l) => (float) (($l['line_total'] ?? 0))), 2) }}</p>
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
            <flux:button type="button" wire:click="saveDraft">{{ __('Save Draft') }}</flux:button>
            <flux:button type="button" wire:click="saveAndPost" variant="primary">{{ __('Save & Post') }}</flux:button>
        </div>
    </form>
</div>
