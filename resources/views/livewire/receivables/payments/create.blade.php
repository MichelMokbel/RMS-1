<?php

use App\Models\ArInvoice;
use App\Models\BankAccount;
use App\Models\Customer;
use App\Services\AR\ArAllocationService;
use App\Services\AR\ArInvoiceService;
use App\Services\AR\ArPaymentService;
use App\Support\Money\MinorUnits;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public int $branch_id = 1;
    public ?int $customer_id = null;
    public string $customer_search = '';

    public string $payment_date = '';
    public string $payment_method = 'bank_transfer';
    public ?int $bank_account_id = null;
    public string $amount = '0.00';
    public string $credit_note_amount = '0.00';
    public ?string $reference = null;
    public ?string $notes = null;

    public array $allocations = [];
    public bool $select_all_allocations = false;

    public string $invoice_date_from = '';
    public string $invoice_date_to = '';

    public function mount(): void
    {
        $this->branch_id = request()->integer('branch_id') ?: ((int) config('inventory.default_branch_id', 1) ?: 1);
        $this->payment_date = now()->toDateString();
        $this->amount = $this->moneyZero();
        $this->credit_note_amount = $this->moneyZero();

        $customerId = request()->integer('customer_id');
        if ($customerId > 0 && Customer::query()->whereKey($customerId)->exists()) {
            $this->selectCustomer($customerId);
        }
    }

    public function with(): array
    {
        $customers = collect();
        if (Schema::hasTable('customers') && $this->customer_id === null && trim($this->customer_search) !== '') {
            $customers = Customer::query()
                ->search($this->customer_search)
                ->orderBy('name')
                ->limit(25)
                ->get();
        }

        return [
            'customers' => $customers,
            'branches' => Schema::hasTable('branches')
                ? DB::table('branches')->where('is_active', 1)->orderBy('name')->get()
                : collect(),
            'bankAccounts' => Schema::hasTable('bank_accounts')
                ? BankAccount::query()->where('is_active', true)->orderBy('name')->get()
                : collect(),
        ];
    }

    public function updatedCustomerSearch(): void
    {
        if (trim($this->customer_search) === '') {
            $this->customer_id = null;
            $this->allocations = [];
            $this->select_all_allocations = false;
            $this->credit_note_amount = $this->moneyZero();
            $this->syncAmount();
        }
    }

    public function selectCustomer(int $id): void
    {
        $this->customer_id = $id;
        $c = Customer::find($id);
        $this->customer_search = $c ? trim($c->name.' '.($c->phone ?? '')) : '';
        $this->credit_note_amount = $this->moneyZero();
        $this->loadInvoices();
    }

    public function loadInvoices(): void
    {
        if (! $this->customer_id || ! Schema::hasTable('ar_invoices')) {
            $this->allocations = [];
            $this->select_all_allocations = false;
            $this->syncAmount();
            return;
        }

        $query = ArInvoice::query()
            ->where('customer_id', $this->customer_id)
            ->whereIn('status', ['issued', 'partially_paid']);

        if ($this->invoice_date_from !== '') {
            $query->whereDate('issue_date', '>=', $this->invoice_date_from);
        }
        if ($this->invoice_date_to !== '') {
            $query->whereDate('issue_date', '<=', $this->invoice_date_to);
        }

        $invoices = $query
            ->orderByDesc('issue_date')
            ->get()
            ->map(function (ArInvoice $invoice) {
                $outstanding = (int) ($invoice->balance_cents ?? 0);
                return [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number ?: ('#'.$invoice->id),
                    'issue_date' => $invoice->issue_date?->format('Y-m-d'),
                    'due_date' => $invoice->due_date?->format('Y-m-d'),
                    'outstanding_cents' => $outstanding,
                    'amount' => MinorUnits::format($outstanding),
                    'selected' => false,
                ];
            })
            ->filter(fn ($row) => (int) ($row['outstanding_cents'] ?? 0) > 0)
            ->values()
            ->toArray();

        $this->allocations = $invoices;
        $this->syncSelectAllAllocations();
        $this->syncAmount();
    }

    public function updatedInvoiceDateFrom(): void
    {
        $this->loadInvoices();
    }

    public function updatedInvoiceDateTo(): void
    {
        $this->loadInvoices();
    }

    public function updatedSelectAllAllocations(bool $selected): void
    {
        foreach ($this->allocations as $idx => $alloc) {
            $this->allocations[$idx]['selected'] = $selected;
        }

        $this->syncAmount();
    }

    public function syncAmount(): void
    {
        $this->amount = MinorUnits::format($this->allocatedTotalCents());
    }

    public function updated($property): void
    {
        // Auto-sync amount when any allocation selection or amount changes
        if (str_starts_with($property, 'allocations.')) {
            $this->syncSelectAllAllocations();
            $this->syncAmount();
        }
    }

    private function syncSelectAllAllocations(): void
    {
        $this->select_all_allocations = count($this->allocations) > 0
            && collect($this->allocations)->every(fn ($allocation) => (bool) ($allocation['selected'] ?? false));
    }

    public function save(ArPaymentService $payments): void
    {
        $this->resetErrorBag();

        $userId = Auth::id();
        if (! $userId) {
            abort(403);
        }

        if (! $this->customer_id) {
            $this->addError('customer_id', __('Customer is required.'));
            return;
        }

        try {
            $amountCents = MinorUnits::parsePos($this->amount);
        } catch (\InvalidArgumentException $e) {
            $this->addError('amount', __('Invalid amount.'));
            return;
        }

        if ($amountCents <= 0) {
            $this->addError('amount', __('Payment amount must be positive.'));
            return;
        }

        $allocationRows = [];
        $allocatedTotal = 0;
        foreach ($this->allocations as $row) {
            $selected = (bool) ($row['selected'] ?? false);
            if (! $selected) {
                continue;
            }
            $amountStr = (string) ($row['amount'] ?? '');
            if ($amountStr === '') {
                continue;
            }
            try {
                $lineCents = MinorUnits::parsePos($amountStr);
            } catch (\InvalidArgumentException $e) {
                $this->addError('allocations', __('Invalid allocation amount.'));
                return;
            }
            if ($lineCents <= 0) {
                continue;
            }
            $allocationRows[] = [
                'invoice_id' => (int) ($row['invoice_id'] ?? 0),
                'amount_cents' => $lineCents,
            ];
            $allocatedTotal += $lineCents;
        }

        if ($allocatedTotal > $amountCents) {
            $this->addError('amount', __('Payment amount must be greater than or equal to total allocated.'));
            return;
        }

        try {
            $payment = $payments->createPaymentWithAllocations([
                'customer_id' => $this->customer_id,
                'branch_id' => $this->branch_id,
                'amount_cents' => $amountCents,
                'method' => $this->payment_method,
                'bank_account_id' => $this->bank_account_id,
                'received_at' => $this->payment_date ? now()->parse($this->payment_date)->toDateTimeString() : now(),
                'reference' => $this->reference,
                'notes' => $this->notes,
                'allocations' => $allocationRows,
            ], $userId);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $m) {
                    $this->addError($field, $m);
                }
            }
            return;
        }

        $allocated = (int) $payment->allocations()->sum('amount_cents');
        $remainder = (int) $payment->amount_cents - $allocated;
        if ($remainder > 0) {
            session()->flash('status', __('Applied :applied, stored :remainder as customer advance.', [
                'applied' => MinorUnits::format($allocated, null, true),
                'remainder' => MinorUnits::format($remainder, null, true),
            ]));
        } else {
            session()->flash('status', __('Payment saved.'));
        }

        $this->redirectRoute('receivables.payments.show', $payment, navigate: true);
    }

    public function createCreditNote(ArInvoiceService $invoices, ArAllocationService $allocations): void
    {
        $this->resetErrorBag();

        $userId = Auth::id();
        if (! $userId) {
            abort(403);
        }

        if (! $this->customer_id) {
            $this->addError('customer_id', __('Customer is required.'));
            return;
        }

        $selected = collect($this->allocations)
            ->filter(fn ($row) => (bool) ($row['selected'] ?? false))
            ->values();

        if ($selected->count() !== 1) {
            $this->addError('credit_note_amount', __('Select exactly one invoice to create a credit note.'));
            return;
        }

        try {
            $amountCents = MinorUnits::parsePos($this->credit_note_amount);
        } catch (\InvalidArgumentException $e) {
            $this->addError('credit_note_amount', __('Invalid amount.'));
            return;
        }

        if ($amountCents <= 0) {
            $this->addError('credit_note_amount', __('Credit amount must be positive.'));
            return;
        }

        $selectedInvoiceId = (int) ($selected->first()['invoice_id'] ?? 0);
        $targetInvoice = ArInvoice::query()->find($selectedInvoiceId);
        if (! $targetInvoice) {
            $this->addError('credit_note_amount', __('Invoice not found.'));
            return;
        }

        $outstanding = (int) ($targetInvoice->balance_cents ?? 0);
        if ($amountCents > $outstanding) {
            $this->addError('credit_note_amount', __('Credit note amount cannot exceed the selected invoice balance.'));
            return;
        }

        try {
            $credit = $invoices->createDraft(
                branchId: (int) $targetInvoice->branch_id,
                customerId: (int) $targetInvoice->customer_id,
                items: [[
                    'description' => __('Credit Note for invoice :no', ['no' => $targetInvoice->invoice_number ?: '#'.$targetInvoice->id]),
                    'qty' => '1.000',
                    'unit_price_cents' => -$amountCents,
                    'discount_cents' => 0,
                    'tax_cents' => 0,
                    'line_total_cents' => -$amountCents,
                ]],
                actorId: $userId,
                currency: (string) ($targetInvoice->currency ?: config('pos.currency')),
                source: $targetInvoice->source ?? 'dashboard',
                type: 'credit_note',
                jobId: $targetInvoice->job_id ? (int) $targetInvoice->job_id : null,
            );
            $credit = $invoices->issue($credit, $userId);
            $allocations->applyCreditNote($credit, $targetInvoice, $userId);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError($field, $message);
                }
            }
            return;
        }

        $this->credit_note_amount = $this->moneyZero();
        $this->loadInvoices();
        $this->dispatch('modal-close', name: 'create-credit-note');
        session()->flash('status', __('Credit note applied.'));
    }

    public function prepareCreditNoteModal(): void
    {
        $this->resetErrorBag(['credit_note_amount']);
    }

    public function formatMoney(?int $cents): string
    {
        return MinorUnits::format((int) ($cents ?? 0));
    }

    public function moneyScaleDigits(): int
    {
        return MinorUnits::scaleDigits(MinorUnits::posScale());
    }

    public function moneyStep(): string
    {
        $digits = $this->moneyScaleDigits();
        if ($digits <= 0) {
            return '1';
        }

        return '0.'.str_pad('1', $digits, '0', STR_PAD_LEFT);
    }

    public function moneyZero(): string
    {
        $digits = $this->moneyScaleDigits();
        if ($digits <= 0) {
            return '0';
        }

        return '0.'.str_repeat('0', $digits);
    }

    public function allocatedTotalCents(): int
    {
        $total = 0;
        foreach ($this->allocations as $row) {
            if (! ($row['selected'] ?? false)) {
                continue;
            }
            $amountStr = (string) ($row['amount'] ?? '');
            if ($amountStr === '') {
                continue;
            }
            try {
                $total += MinorUnits::parsePos($amountStr);
            } catch (\InvalidArgumentException $e) {
                continue;
            }
        }
        return $total;
    }

    public function remainingCents(): int
    {
        try {
            $amount = MinorUnits::parsePos($this->amount);
        } catch (\InvalidArgumentException $e) {
            $amount = 0;
        }

        return $amount - $this->allocatedTotalCents();
    }
}; ?>

<div class="w-full max-w-5xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('New Customer Payment') }}</h1>
        <flux:button :href="route('receivables.payments.index')" wire:navigate variant="ghost">{{ __('Back') }}</flux:button>
    </div>

    @if(session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    <form wire:submit="save" class="space-y-6">
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                <div>
                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Branch') }}</label>
                    @if ($branches->count())
                        <select wire:model.live="branch_id" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                            @foreach ($branches as $b)
                                <option value="{{ $b->id }}">{{ $b->name }}</option>
                            @endforeach
                        </select>
                    @else
                        <flux:input wire:model.live="branch_id" type="number" :label="__('Branch ID')" />
                    @endif
                </div>
                <div class="md:col-span-2 relative">
                    <flux:input wire:model.live.debounce.300ms="customer_search" :label="__('Customer')" placeholder="{{ __('Search by name/phone/code') }}" />
                    @error('customer_id') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                    @if($customer_id === null && trim($customer_search) !== '')
                        <div class="absolute z-10 mt-1 w-full overflow-hidden rounded-md border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                            <div class="max-h-64 overflow-auto">
                                @forelse ($customers as $c)
                                    <button type="button" class="w-full px-3 py-2 text-left text-sm text-neutral-800 hover:bg-neutral-50 dark:text-neutral-100 dark:hover:bg-neutral-800/80" wire:click="selectCustomer({{ $c->id }})">
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="font-medium">{{ $c->name }}</span>
                                            @if($c->customer_code)
                                                <span class="text-xs text-neutral-500 dark:text-neutral-400">{{ $c->customer_code }}</span>
                                            @endif
                                        </div>
                                        @if($c->phone)
                                            <div class="text-xs text-neutral-500 dark:text-neutral-400">{{ $c->phone }}</div>
                                        @endif
                                    </button>
                                @empty
                                    <div class="px-3 py-2 text-sm text-neutral-500 dark:text-neutral-400">{{ __('No customers found.') }}</div>
                                @endforelse
                            </div>
                        </div>
                    @endif
                </div>
                <div>
                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Payment Date') }}</label>
                    <input type="date" wire:model.live="payment_date" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" />
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <flux:input wire:model.live="amount" type="number" step="{{ $this->moneyStep() }}" min="0" :label="__('Amount')" />
                <div>
                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Method') }}</label>
                    <select wire:model.live="payment_method" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="bank_transfer">{{ __('Bank Transfer') }}</option>
                        <option value="cash">{{ __('Cash') }}</option>
                        <option value="card">{{ __('Card') }}</option>
                        <option value="cheque">{{ __('Cheque') }}</option>
                        <option value="other">{{ __('Other') }}</option>
                    </select>
                </div>
                <flux:input wire:model.live="reference" :label="__('Reference')" />
            </div>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <div>
                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Bank Account') }}</label>
                    <select wire:model.live="bank_account_id" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="">{{ __('Default') }}</option>
                        @foreach ($bankAccounts as $account)
                            <option value="{{ $account->id }}">{{ $account->name }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                        @if($payment_method === 'bank_transfer')
                            {{ __('Required for bank transfers. This drives both the ledger posting and bank reconciliation account.') }}
                        @elseif($payment_method === 'card')
                            {{ __('Card receipts post to the configured card-clearing account.') }}
                        @elseif($payment_method === 'cash')
                            {{ __('Cash receipts post to the configured cash account.') }}
                        @else
                            {{ __('Cheque and other receipts post to their configured clearing accounts.') }}
                        @endif
                    </p>
                    @error('bank_account_id') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
            @error('amount') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
            <flux:textarea wire:model.live="notes" :label="__('Notes')" rows="2" />
        </div>

        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-semibold text-neutral-800 dark:text-neutral-200">{{ __('Allocations') }}</h3>
                <div class="flex items-center gap-3">
                    @if($customer_id)
                        <flux:button
                            type="button"
                            variant="ghost"
                            x-data=""
                            x-on:click.prevent="$wire.prepareCreditNoteModal(); $dispatch('modal-show', { name: 'create-credit-note' })"
                        >{{ __('Discount / Credit Note') }}</flux:button>
                    @endif
                    <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Remaining') }}: {{ $this->formatMoney($this->remainingCents()) }}</p>
                </div>
            </div>

            {{-- Date filters --}}
            @if($customer_id)
                <div class="grid grid-cols-1 gap-3 md:grid-cols-3 items-end">
                    <div>
                        <label class="text-xs font-medium text-neutral-600 dark:text-neutral-300">{{ __('Invoice Date From') }}</label>
                        <input type="date" wire:model.live="invoice_date_from" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-1.5 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" />
                    </div>
                    <div>
                        <label class="text-xs font-medium text-neutral-600 dark:text-neutral-300">{{ __('Invoice Date To') }}</label>
                        <input type="date" wire:model.live="invoice_date_to" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-1.5 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" />
                    </div>
                    <div class="text-right text-sm text-neutral-600 dark:text-neutral-300">
                        {{ count($allocations) }} {{ __('invoice(s)') }}
                    </div>
                </div>
            @endif

            @error('allocations') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
            <div class="overflow-x-auto">
                <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                    <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                                <label class="inline-flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        wire:model.live="select_all_allocations"
                                        class="rounded border-neutral-300 text-primary-600 shadow-sm focus:ring-primary-500"
                                    >
                                    <span>{{ __('Select') }}</span>
                                </label>
                            </th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Invoice') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Issue') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Due') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Outstanding') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Allocate') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        @forelse ($allocations as $idx => $alloc)
                            <tr>
                                <td class="px-3 py-2 text-sm">
                                    <input type="checkbox" wire:model.live="allocations.{{ $idx }}.selected" class="rounded border-neutral-300 text-primary-600 shadow-sm focus:ring-primary-500">
                                </td>
                                <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $alloc['invoice_number'] }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $alloc['issue_date'] }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $alloc['due_date'] }}</td>
                                <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ $this->formatMoney($alloc['outstanding_cents']) }}</td>
                                <td class="px-3 py-2 text-sm text-right">
                                    <flux:input wire:model.live="allocations.{{ $idx }}.amount" type="number" step="{{ $this->moneyStep() }}" min="0" />
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-3 py-3 text-sm text-neutral-600 dark:text-neutral-300 text-center">{{ __('No open invoices for customer') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="flex justify-end">
            <flux:button type="submit" variant="primary">{{ __('Save Payment') }}</flux:button>
        </div>
    </form>

    <flux:modal name="create-credit-note" focusable class="max-w-lg">
        <div class="space-y-4">
            <div class="space-y-1">
                <flux:heading size="lg">{{ __('Discount / Credit Note') }}</flux:heading>
                <flux:subheading>{{ __('Select exactly one invoice in the table, then create and apply a credit note to it.') }}</flux:subheading>
            </div>

            <div class="w-full">
                <flux:input wire:model.live="credit_note_amount" type="number" step="{{ $this->moneyStep() }}" min="0" :label="__('Credit Note Amount')" />
                @error('credit_note_amount') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled" type="button">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="button" wire:click="createCreditNote" variant="primary">{{ __('Create Credit Note') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
