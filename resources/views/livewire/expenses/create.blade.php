<?php

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Supplier;
use App\Services\Expenses\ExpensePaymentService;
use App\Services\Expenses\ExpensePaymentStatusService;
use App\Services\Expenses\ExpenseTotalsService;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public ?int $category_id = null;
    public ?int $supplier_id = null;
    public ?string $expense_date = null;
    public string $description = '';
    public float $amount = 0;
    public float $tax_amount = 0;
    public float $total_amount = 0;
    public float $payment_amount = 0;
    public string $payment_status = 'paid';
    public string $payment_method = 'cash';
    public ?string $reference = null;
    public ?string $notes = null;

    public function mount(): void
    {
        $this->expense_date = now()->toDateString();
    }

    public function updated($field): void
    {
        if (in_array($field, ['amount', 'tax_amount'], true)) {
            $this->total_amount = round((float) $this->amount + (float) $this->tax_amount, 2);

            if ($this->payment_status === 'paid') {
                $this->payment_amount = $this->total_amount;
            }
        }

        if ($field === 'payment_status') {
            if ($this->payment_status === 'paid') {
                $this->payment_amount = round((float) $this->amount + (float) $this->tax_amount, 2);
            } elseif ($this->payment_status === 'partial') {
                $this->payment_amount = ($this->payment_amount > 0 && $this->payment_amount < $this->total_amount)
                    ? $this->payment_amount
                    : 0;
            } else {
                $this->payment_amount = 0;
            }
        }
    }

    public function save(ExpenseTotalsService $totalsService, ExpensePaymentService $paymentService, ExpensePaymentStatusService $statusService): void
    {
        $data = $this->validate([
            'category_id' => ['required', 'integer', 'exists:expense_categories,id'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'expense_date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0'],
            'tax_amount' => ['required', 'numeric', 'min:0'],
            'payment_status' => ['required', 'in:unpaid,partial,paid'],
            'payment_amount' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['required', 'in:cash,card,bank_transfer,cheque,other'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
        ]);

        $total = round((float) $data['amount'] + (float) $data['tax_amount'], 2);
        $paymentAmount = null;

        if ($data['payment_status'] === 'paid') {
            $paymentAmount = $total;
        } elseif ($data['payment_status'] === 'partial') {
            $paymentAmount = round((float) $this->payment_amount, 2);
            if ($paymentAmount <= 0 || $paymentAmount >= $total) {
                throw ValidationException::withMessages(['payment_amount' => __('Partial payment must be greater than 0 and less than total.')]);
            }
        }

        $expense = DB::transaction(function () use ($data, $totalsService, $paymentService, $paymentAmount) {
            $expense = Expense::create([
                'category_id' => $data['category_id'],
                'supplier_id' => $data['supplier_id'] ?? null,
                'expense_date' => $data['expense_date'],
                'description' => $data['description'],
                'amount' => $data['amount'],
                'tax_amount' => $data['tax_amount'],
                'total_amount' => 0,
                'payment_status' => $data['payment_status'],
                'payment_method' => $data['payment_method'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $totalsService->recalc($expense);

            if (in_array($data['payment_status'], ['paid', 'partial'], true) && isset($paymentAmount) && $paymentAmount > 0) {
                $paymentService->addPayment($expense, [
                    'payment_date' => $expense->expense_date,
                    'amount' => $paymentAmount,
                    'payment_method' => $expense->payment_method,
                    'reference' => $expense->reference,
                    'notes' => $expense->notes,
                ], auth()->id());
            }

            return $expense->fresh();
        });

        session()->flash('status', __('Expense saved.'));
        $this->redirectRoute('expenses.index', navigate: true);
    }

    public function categories()
    {
        return Schema::hasTable('expense_categories') ? ExpenseCategory::orderBy('name')->get() : collect();
    }

    public function suppliers()
    {
        return Schema::hasTable('suppliers') ? Supplier::orderBy('name')->get() : collect();
    }
}; ?>

<div class="w-full max-w-4xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Create Expense') }}</h1>
        <flux:button :href="route('expenses.index')" wire:navigate variant="ghost">{{ __('Back') }}</flux:button>
    </div>

    <form wire:submit="save" class="space-y-6">
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Category') }}</label>
                    <select wire:model="category_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="">{{ __('Select category') }}</option>
                        @foreach($this->categories() as $cat)
                            <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                        @endforeach
                    </select>
                    @error('category_id') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Supplier') }}</label>
                    <select wire:model="supplier_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="">{{ __('None') }}</option>
                        @foreach($this->suppliers() as $sup)
                            <option value="{{ $sup->id }}">{{ $sup->name }}</option>
                        @endforeach
                    </select>
                    @error('supplier_id') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <flux:input wire:model="expense_date" type="date" :label="__('Expense Date')" />
                <flux:input wire:model="description" :label="__('Description')" />
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <flux:input wire:model="amount" type="number" step="0.01" min="0" :label="__('Amount')" />
                <flux:input wire:model="tax_amount" type="number" step="0.01" min="0" :label="__('Tax')" />
                <div>
                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Total') }}</label>
                    <div class="rounded-md border border-neutral-200 bg-neutral-50 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        {{ number_format((float) $amount + (float) $tax_amount, 2) }}
                    </div>
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div>
                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Payment Status') }}</label>
                    <select wire:model="payment_status" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="paid">{{ __('Paid') }}</option>
                        <option value="partial">{{ __('Partial') }}</option>
                        <option value="unpaid">{{ __('Unpaid') }}</option>
                    </select>
                </div>
                <div>
                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Payment Method') }}</label>
                    <select wire:model="payment_method" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="cash">Cash</option>
                        <option value="card">Card</option>
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="cheque">Cheque</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <flux:input wire:model="reference" :label="__('Reference')" />
            </div>
            <flux:textarea wire:model="notes" :label="__('Notes')" rows="2" />

            @if(in_array($payment_status, ['paid', 'partial']))
                <flux:input
                    wire:model="payment_amount"
                    type="number"
                    step="0.01"
                    min="0"
                    :label="$payment_status === 'paid' ? __('Payment Amount (auto-set to total)') : __('Payment Amount')"
                    @if($payment_status === 'paid') disabled @endif
                />
                @error('payment_amount') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
            @endif
        </div>

        <div class="flex justify-end">
            <flux:button type="submit" variant="primary">{{ __('Save Expense') }}</flux:button>
        </div>
    </form>
</div>
