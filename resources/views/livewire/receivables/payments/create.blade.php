<?php

use App\Models\ArInvoice;
use App\Models\Customer;
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
    public string $payment_method = 'bank';
    public string $amount = '0.00';
    public ?string $reference = null;
    public ?string $notes = null;

    public array $allocations = [];

    public function mount(): void
    {
        $this->branch_id = (int) config('inventory.default_branch_id', 1) ?: 1;
        $this->payment_date = now()->toDateString();
        $this->amount = $this->moneyZero();
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
        ];
    }

    public function updatedCustomerSearch(): void
    {
        if (trim($this->customer_search) === '') {
            $this->customer_id = null;
            $this->allocations = [];
        }
    }

    public function selectCustomer(int $id): void
    {
        $this->customer_id = $id;
        $c = Customer::find($id);
        $this->customer_search = $c ? trim($c->name.' '.($c->phone ?? '')) : '';
        $this->loadInvoices();
    }

    public function loadInvoices(): void
    {
        if (! $this->customer_id || ! Schema::hasTable('ar_invoices')) {
            $this->allocations = [];
            return;
        }

        $invoices = ArInvoice::query()
            ->where('customer_id', $this->customer_id)
            ->whereIn('status', ['issued', 'partially_paid'])
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
                    'selected' => $outstanding > 0,
                ];
            })
            ->filter(fn ($row) => (int) ($row['outstanding_cents'] ?? 0) > 0)
            ->values()
            ->toArray();

        $this->allocations = $invoices;
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
                        <option value="bank">{{ __('Bank') }}</option>
                        <option value="cash">{{ __('Cash') }}</option>
                        <option value="card">{{ __('Card') }}</option>
                        <option value="online">{{ __('Online') }}</option>
                        <option value="voucher">{{ __('Voucher') }}</option>
                    </select>
                </div>
                <flux:input wire:model.live="reference" :label="__('Reference')" />
            </div>
            @error('amount') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
            <flux:textarea wire:model.live="notes" :label="__('Notes')" rows="2" />
        </div>

        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-semibold text-neutral-800 dark:text-neutral-200">{{ __('Allocations') }}</h3>
                <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Remaining') }}: {{ $this->formatMoney($this->remainingCents()) }}</p>
            </div>
            @error('allocations') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
            <div class="overflow-x-auto">
                <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                    <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Select') }}</th>
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
                                    <input type="checkbox" wire:model="allocations.{{ $idx }}.selected" class="rounded border-neutral-300 text-primary-600 shadow-sm focus:ring-primary-500">
                                </td>
                                <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $alloc['invoice_number'] }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $alloc['issue_date'] }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $alloc['due_date'] }}</td>
                                <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ $this->formatMoney($alloc['outstanding_cents']) }}</td>
                                <td class="px-3 py-2 text-sm text-right">
                                    <flux:input wire:model="allocations.{{ $idx }}.amount" type="number" step="{{ $this->moneyStep() }}" min="0" />
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
</div>
