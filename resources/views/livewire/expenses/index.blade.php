<?php

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Supplier;
use App\Services\Expenses\ExpensePaymentService;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Auth;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public ?int $supplier_id = null;
    public ?int $category_id = null;
    public string $payment_status = 'all';
    public string $payment_method = 'all';
    public ?string $date_from = null;
    public ?string $date_to = null;

    public ?int $pay_expense_id = null;
    public ?string $pay_date = null;
    public float $pay_amount = 0;
    public ?string $pay_method = 'cash';
    public ?string $pay_reference = null;
    public ?string $pay_notes = null;

    protected $paginationTheme = 'tailwind';

    public function updating($field): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        return [
            'categories' => Schema::hasTable('expense_categories') ? ExpenseCategory::orderBy('name')->get() : collect(),
            'suppliers' => Schema::hasTable('suppliers') ? Supplier::orderBy('name')->get() : collect(),
            'expenses' => $this->query()->paginate(10),
        ];
    }

    private function query()
    {
        return Expense::query()
            ->with(['supplier', 'category'])
            ->withSum('payments as paid_sum', 'amount')
            ->when($this->search, fn ($q) => $q->where(function ($sub) {
                $sub->where('description', 'like', '%'.$this->search.'%')
                    ->orWhere('reference', 'like', '%'.$this->search.'%');
            }))
            ->when($this->supplier_id, fn ($q) => $q->where('supplier_id', $this->supplier_id))
            ->when($this->category_id, fn ($q) => $q->where('category_id', $this->category_id))
            ->when($this->payment_status !== 'all', fn ($q) => $q->where('payment_status', $this->payment_status))
            ->when($this->payment_method !== 'all', fn ($q) => $q->where('payment_method', $this->payment_method))
            ->when($this->date_from, fn ($q) => $q->whereDate('expense_date', '>=', $this->date_from))
            ->when($this->date_to, fn ($q) => $q->whereDate('expense_date', '<=', $this->date_to))
            ->orderByDesc('expense_date');
    }

    public function delete(int $id): void
    {
        $expense = Expense::with(['payments', 'attachments'])->findOrFail($id);
        if ($expense->payments()->exists() || $expense->attachments()->exists()) {
            $this->addError('delete', __('Cannot delete expense with payments or attachments.'));
            return;
        }
        $expense->delete();
        session()->flash('status', __('Expense deleted.'));
    }

    public function addPayment(ExpensePaymentService $paymentService): void
    {
        if (! $this->pay_expense_id) {
            return;
        }
        $expense = Expense::findOrFail($this->pay_expense_id);
        $this->validate([
            'pay_date' => ['required', 'date'],
            'pay_amount' => ['required', 'numeric', 'min:0.01'],
            'pay_method' => ['required', 'in:cash,card,bank_transfer,cheque,other'],
        ]);
        $paymentService->addPayment($expense, [
            'payment_date' => $this->pay_date,
            'amount' => $this->pay_amount,
            'payment_method' => $this->pay_method,
            'reference' => $this->pay_reference,
            'notes' => $this->pay_notes,
        ], Auth::id());

        $this->resetPaymentForm();
        session()->flash('status', __('Payment added.'));
    }

    public function startPay(int $expenseId, float $outstanding): void
    {
        $expense = Expense::withSum('payments as paid_sum', 'amount')->findOrFail($expenseId);
        $outstanding = max((float) $expense->total_amount - (float) ($expense->paid_sum ?? 0), 0);

        if ($expense->payment_status === 'paid' || $outstanding <= 0.0) {
            return;
        }

        $this->pay_expense_id = $expenseId;
        $this->pay_date = now()->toDateString();
        $this->pay_amount = $outstanding;
        $this->pay_method = 'cash';
        $this->pay_reference = null;
        $this->pay_notes = null;
    }

    private function resetPaymentForm(): void
    {
        $this->pay_expense_id = null;
        $this->pay_date = null;
        $this->pay_amount = 0;
        $this->pay_method = 'cash';
        $this->pay_reference = null;
        $this->pay_notes = null;
    }
}; ?>

<div class="w-full max-w-7xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Expenses') }}</h1>
        <div class="flex gap-2">
            <flux:button href="{{ url('expenses/categories') }}" wire:navigate>{{ __('Categories') }}</flux:button>
            <flux:button :href="route('expenses.create')" wire:navigate variant="primary">{{ __('New Expense') }}</flux:button>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif
    @error('delete')
        <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100">
            {{ $message }}
        </div>
    @enderror

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-2">
        <div class="flex flex-wrap gap-3">
            <div class="flex-1 min-w-[200px]">
                <flux:input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search description or reference') }}" />
            </div>
            <div class="flex-1 min-w-[160px]">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Supplier') }}</label>
                <select wire:model="supplier_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="">{{ __('All') }}</option>
                    @foreach($suppliers as $sup)
                        <option value="{{ $sup->id }}">{{ $sup->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex-1 min-w-[160px]">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Category') }}</label>
                <select wire:model="category_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="">{{ __('All') }}</option>
                    @foreach($categories as $cat)
                        <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex-1 min-w-[140px]">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Status') }}</label>
                <select wire:model="payment_status" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="all">{{ __('All') }}</option>
                    <option value="unpaid">{{ __('Unpaid') }}</option>
                    <option value="partial">{{ __('Partial') }}</option>
                    <option value="paid">{{ __('Paid') }}</option>
                </select>
            </div>
            <div class="flex-1 min-w-[140px]">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Method') }}</label>
                <select wire:model="payment_method" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="all">{{ __('All') }}</option>
                    <option value="cash">Cash</option>
                    <option value="card">Card</option>
                    <option value="bank_transfer">Bank Transfer</option>
                    <option value="cheque">Cheque</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div class="flex-1 min-w-[150px]">
                <flux:input wire:model="date_from" type="date" :label="__('Date From')" />
            </div>
            <div class="flex-1 min-w-[150px]">
                <flux:input wire:model="date_to" type="date" :label="__('Date To')" />
            </div>
        </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Date') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Supplier') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Category') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Description') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Amount') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Paid') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Outstanding') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Status') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                @forelse($expenses as $exp)
                    @php
                        $paid = (float) ($exp->paid_sum ?? 0);
                        $outstanding = max((float)$exp->total_amount - $paid, 0);
                    @endphp
                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $exp->expense_date?->format('Y-m-d') }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $exp->supplier->name ?? '—' }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $exp->category->name ?? '—' }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $exp->description }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float)$exp->amount, 2) }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ number_format($paid, 2) }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ number_format($outstanding, 2) }}</td>
                        <td class="px-3 py-2 text-sm">
                            <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold bg-neutral-100 text-neutral-800 dark:bg-neutral-800 dark:text-neutral-50">
                                {{ $exp->payment_status }}
                            </span>
                        </td>
                        <td class="px-3 py-2 text-sm">
                            <div class="flex flex-wrap gap-2">
                                <flux:button size="xs" :href="route('expenses.show', $exp)" wire:navigate>{{ __('View') }}</flux:button>
                                @if($outstanding > 0 && $exp->payment_status !== 'paid')
                                    <flux:button size="xs" wire:click="startPay({{ $exp->id }}, {{ $outstanding }})">{{ __('Add Payment') }}</flux:button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-3 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No expenses found') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $expenses->links() }}

    @if($pay_expense_id)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
            <div class="w-full max-w-md rounded-lg bg-white p-4 shadow-lg dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-700">
                <h3 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100 mb-3">{{ __('Add Payment') }}</h3>
                <div class="space-y-3">
                    <flux:input wire:model="pay_date" type="date" :label="__('Payment Date')" />
                    <flux:input wire:model="pay_amount" type="number" step="0.01" min="0.01" :label="__('Amount')" />
                    <div>
                        <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Method') }}</label>
                        <select wire:model="pay_method" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                            <option value="cash">Cash</option>
                            <option value="card">Card</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="cheque">Cheque</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <flux:input wire:model="pay_reference" :label="__('Reference')" />
                    <flux:textarea wire:model="pay_notes" :label="__('Notes')" rows="2" />
                </div>
                <div class="flex justify-end gap-2 mt-4">
                    <flux:button type="button" wire:click="resetPaymentForm" variant="ghost">{{ __('Cancel') }}</flux:button>
                    <flux:button type="button" wire:click="addPayment" variant="primary">{{ __('Save Payment') }}</flux:button>
                </div>
            </div>
        </div>
    @endif
</div>
