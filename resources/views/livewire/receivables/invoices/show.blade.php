<?php

use App\Models\ArInvoice;
use App\Models\Payment;
use App\Services\AR\ArAllocationService;
use App\Services\AR\ArInvoiceService;
use App\Services\AR\ArPaymentService;
use App\Support\Money\MinorUnits;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public ArInvoice $invoice;
    public string $void_reason = '';

    public string $payment_method = 'bank';
    public string $payment_amount = '0.00';

    public string $credit_amount = '0.00';

    public ?int $advance_payment_id = null;
    public string $advance_amount = '0.00';
    public array $available_advances = [];

    public function mount(ArInvoice $invoice): void
    {
        $this->invoice = $invoice->load(['items', 'customer', 'paymentAllocations.payment']);
        $this->payment_amount = $this->moneyZero();
        $this->credit_amount = $this->moneyZero();
        $this->advance_amount = $this->moneyZero();
        $this->loadAdvances();
    }

    public function issue(ArInvoiceService $service): void
    {
        $userId = Auth::id();
        if (! $userId) {
            abort(403);
        }

        try {
            $this->invoice = $service->issue($this->invoice, $userId)->load(['items', 'customer', 'paymentAllocations.payment']);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $m) {
                    $this->addError($field, $m);
                }
            }
            return;
        }

        session()->flash('status', __('Invoice issued.'));
    }

    public function voidInvoice(ArInvoiceService $service): void
    {
        $this->resetErrorBag();
        $userId = Auth::id();
        if (! $userId) {
            abort(403);
        }

        try {
            $this->invoice = $service->void($this->invoice, $userId, $this->void_reason);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $m) {
                    $this->addError($field, $m);
                }
            }
            return;
        }

        $this->void_reason = '';
        session()->flash('status', __('Invoice voided.'));
    }

    public function voidAndDuplicate(ArInvoiceService $service): void
    {
        $this->resetErrorBag();
        $userId = Auth::id();
        if (! $userId) {
            abort(403);
        }

        try {
            $duplicate = $service->voidAndDuplicate($this->invoice, $userId, $this->void_reason);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $m) {
                    $this->addError($field, $m);
                }
            }
            return;
        }

        session()->flash('status', __('Invoice voided and duplicated as draft.'));
        $this->redirectRoute('invoices.edit', $duplicate, navigate: true);
    }

    public function receivePayment(ArAllocationService $alloc): void
    {
        $this->resetErrorBag();
        $userId = Auth::id();
        if (! $userId) {
            abort(403);
        }

        try {
            $amountCents = MinorUnits::parsePos($this->payment_amount);
        } catch (\InvalidArgumentException $e) {
            $this->addError('payment_amount', __('Invalid amount.'));
            return;
        }

        try {
            $result = $alloc->createPaymentAndAllocate([
                'invoice_id' => $this->invoice->id,
                'amount_cents' => $amountCents,
                'method' => $this->payment_method,
            ], $userId);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $m) {
                    $this->addError($field, $m);
                }
            }
            return;
        }

        $this->invoice = ArInvoice::with(['items', 'customer', 'paymentAllocations.payment'])->findOrFail($this->invoice->id);
        $this->payment_amount = $this->moneyZero();
        $this->loadAdvances();
        $applied = (int) ($result['allocated_cents'] ?? 0);
        $remainder = (int) ($result['remainder_cents'] ?? 0);
        if ($remainder > 0) {
            session()->flash('status', __('Applied :applied, stored :remainder as customer advance.', [
                'applied' => MinorUnits::format($applied, null, true),
                'remainder' => MinorUnits::format($remainder, null, true),
            ]));
        } else {
            session()->flash('status', __('Payment applied.'));
        }
    }

    public function applyCredit(ArInvoiceService $invoices, ArAllocationService $alloc): void
    {
        $this->resetErrorBag();
        $userId = Auth::id();
        if (! $userId) {
            abort(403);
        }

        try {
            $amountCents = MinorUnits::parsePos($this->credit_amount);
        } catch (\InvalidArgumentException $e) {
            $this->addError('credit_amount', __('Invalid amount.'));
            return;
        }

        if ($amountCents <= 0) {
            $this->addError('credit_amount', __('Credit amount must be positive.'));
            return;
        }

        $credit = $invoices->createDraft(
            branchId: (int) $this->invoice->branch_id,
            customerId: (int) $this->invoice->customer_id,
            items: [[
                'description' => __('Credit Note for invoice :no', ['no' => $this->invoice->invoice_number ?: '#'.$this->invoice->id]),
                'qty' => '1.000',
                'unit_price_cents' => -$amountCents,
                'discount_cents' => 0,
                'tax_cents' => 0,
                'line_total_cents' => -$amountCents,
            ]],
            actorId: $userId,
            currency: (string) ($this->invoice->currency ?: config('pos.currency')),
            posReference: $this->invoice->pos_reference ?? null,
            source: $this->invoice->source ?? 'dashboard',
            sourceSaleId: null,
            type: 'credit_note',
        );
        $credit = $invoices->issue($credit, $userId);

        try {
            $alloc->applyCreditNote($credit, $this->invoice, $userId);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $m) {
                    $this->addError($field, $m);
                }
            }
            return;
        }

        $this->invoice = ArInvoice::with(['items', 'customer', 'paymentAllocations.payment'])->findOrFail($this->invoice->id);
        $this->credit_amount = $this->moneyZero();
        $this->loadAdvances();
        session()->flash('status', __('Credit note applied.'));
    }

    public function applyAdvance(ArPaymentService $payments): void
    {
        $this->resetErrorBag();
        $userId = Auth::id();
        if (! $userId) {
            abort(403);
        }

        if (! $this->advance_payment_id) {
            $this->addError('advance_payment_id', __('Select a payment.'));
            return;
        }

        try {
            $amountCents = MinorUnits::parsePos($this->advance_amount);
        } catch (\InvalidArgumentException $e) {
            $this->addError('advance_amount', __('Invalid amount.'));
            return;
        }

        try {
            $payments->applyExistingPaymentToInvoice($this->advance_payment_id, $this->invoice->id, $amountCents, $userId);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $m) {
                    $this->addError($field, $m);
                }
            }
            return;
        }

        $this->invoice = ArInvoice::with(['items', 'customer', 'paymentAllocations.payment'])->findOrFail($this->invoice->id);
        $this->advance_amount = $this->moneyZero();
        $this->advance_payment_id = null;
        $this->loadAdvances();
        session()->flash('status', __('Advance applied.'));
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

    public function loadAdvances(): void
    {
        $customerId = (int) ($this->invoice->customer_id ?? 0);
        if ($customerId <= 0) {
            $this->available_advances = [];
            return;
        }

        $payments = Payment::query()
            ->where('source', 'ar')
            ->where('customer_id', $customerId)
            ->whereNull('voided_at')
            ->withSum('allocations as allocated_sum', 'amount_cents')
            ->orderByDesc('received_at')
            ->get();

        $this->available_advances = $payments->map(function (Payment $payment) {
            $allocated = (int) ($payment->allocated_sum ?? 0);
            $remaining = (int) $payment->amount_cents - $allocated;
            if ($remaining <= 0) {
                return null;
            }

            return [
                'id' => $payment->id,
                'received_at' => $payment->received_at?->format('Y-m-d'),
                'method' => $payment->method,
                'amount_cents' => (int) $payment->amount_cents,
                'allocated_cents' => $allocated,
                'remaining_cents' => $remaining,
            ];
        })->filter()->values()->toArray();
    }
}; ?>

<div class="w-full max-w-5xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                {{ __('Invoice') }} {{ $invoice->invoice_number ?: ('#'.$invoice->id) }}
            </h1>
            <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ $invoice->customer?->name }}</p>
        </div>
        <div class="flex items-center gap-2">
            <flux:button :href="route('invoices.index')" wire:navigate variant="ghost">{{ __('Back') }}</flux:button>
            @if ($invoice->status === 'draft')
                <flux:button :href="route('invoices.edit', $invoice)" wire:navigate variant="ghost">{{ __('Edit') }}</flux:button>
            @endif
            @if ($invoice->status !== 'draft')
                <flux:button :href="route('invoices.print', $invoice)" target="_blank">{{ __('Print') }}</flux:button>
            @endif
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
        <div class="grid grid-cols-1 gap-3 md:grid-cols-3 text-sm">
            <div class="rounded-md border border-neutral-200 p-3 dark:border-neutral-700">
                <div class="text-neutral-500 dark:text-neutral-400">{{ __('Status') }}</div>
                <div class="font-semibold text-neutral-900 dark:text-neutral-100">{{ $invoice->status }}</div>
            </div>
            <div class="rounded-md border border-neutral-200 p-3 dark:border-neutral-700">
                <div class="text-neutral-500 dark:text-neutral-400">{{ __('Total') }}</div>
                <div class="font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($invoice->total_cents) }}</div>
            </div>
            <div class="rounded-md border border-neutral-200 p-3 dark:border-neutral-700">
                <div class="text-neutral-500 dark:text-neutral-400">{{ __('Balance') }}</div>
                <div class="font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($invoice->balance_cents) }}</div>
            </div>
        </div>

        @if ($invoice->status === 'draft')
            <div class="flex justify-end">
                <flux:button type="button" wire:click="issue" variant="primary">{{ __('Issue Invoice') }}</flux:button>
            </div>
        @endif

        @if (in_array($invoice->status, ['draft', 'issued', 'partially_paid', 'paid'], true))
            <div class="border-t border-neutral-200 pt-4 dark:border-neutral-700 space-y-3">
                <h2 class="text-lg font-semibold text-rose-700 dark:text-rose-200">{{ __('Void Invoice') }}</h2>
                <flux:input wire:model="void_reason" :label="__('Reason (optional)')" />
                @error('status') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                <div class="flex justify-end gap-2">
                    <flux:button
                        type="button"
                        wire:click="voidInvoice"
                        wire:confirm="{{ __('Void this invoice?') }}"
                        variant="danger"
                    >{{ __('Void') }}</flux:button>
                    <flux:button
                        type="button"
                        wire:click="voidAndDuplicate"
                        wire:confirm="{{ __('Void and create an editable duplicate draft?') }}"
                        variant="primary"
                    >{{ __('Void & Duplicate') }}</flux:button>
                </div>
            </div>
        @endif

        <div class="border-t border-neutral-200 pt-4 dark:border-neutral-700">
            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Items') }}</h2>
            <div class="mt-3 space-y-2">
                @foreach ($invoice->items as $row)
                    <div class="flex items-start justify-between gap-3 rounded-md border border-neutral-200 p-3 dark:border-neutral-700">
                        <div>
                            <div class="text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ $row->description }}</div>
                            <div class="text-xs text-neutral-500 dark:text-neutral-400">{{ __('Qty') }}: {{ $row->qty }}</div>
                        </div>
                        <div class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($row->line_total_cents) }}</div>
                    </div>
                @endforeach
            </div>
        </div>

        @if (in_array($invoice->status, ['issued', 'partially_paid'], true))
            <div class="border-t border-neutral-200 pt-4 dark:border-neutral-700 space-y-3">
                <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Receive Payment') }}</h2>
                <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                    <div>
                        <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Method') }}</label>
                        <select wire:model="payment_method" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                            <option value="cash">{{ __('Cash') }}</option>
                            <option value="card">{{ __('Card') }}</option>
                            <option value="online">{{ __('Online') }}</option>
                            <option value="bank">{{ __('Bank') }}</option>
                            <option value="voucher">{{ __('Voucher') }}</option>
                        </select>
                    </div>
                    <flux:input wire:model="payment_amount" type="number" step="{{ $this->moneyStep() }}" :label="__('Amount')" />
                    <div class="flex items-end justify-end">
                        <flux:button type="button" wire:click="receivePayment" variant="primary">{{ __('Apply') }}</flux:button>
                    </div>
                </div>
                @error('payment_amount') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>

            <div class="border-t border-neutral-200 pt-4 dark:border-neutral-700 space-y-3">
                <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Credit Note') }}</h2>
                <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                    <flux:input wire:model="credit_amount" type="number" step="{{ $this->moneyStep() }}" :label="__('Credit Amount')" />
                    <div class="md:col-span-2 flex items-end justify-end">
                        <flux:button type="button" wire:click="applyCredit" variant="outline">{{ __('Create & Apply Credit Note') }}</flux:button>
                    </div>
                </div>
                @error('credit_amount') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>

            <div class="border-t border-neutral-200 pt-4 dark:border-neutral-700 space-y-3">
                <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Apply Advance') }}</h2>
                <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                    <div>
                        <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Advance Payment') }}</label>
                        <select wire:model="advance_payment_id" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                            <option value="">{{ __('Select') }}</option>
                            @foreach ($available_advances as $adv)
                                <option value="{{ $adv['id'] }}">#{{ $adv['id'] }} • {{ $adv['received_at'] }} • {{ $this->formatMoney($adv['remaining_cents']) }}</option>
                            @endforeach
                        </select>
                        @error('advance_payment_id') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <flux:input wire:model="advance_amount" type="number" step="{{ $this->moneyStep() }}" :label="__('Amount')" />
                    <div class="flex items-end justify-end">
                        <flux:button type="button" wire:click="applyAdvance" variant="primary">{{ __('Apply Advance') }}</flux:button>
                    </div>
                </div>
                @error('advance_amount') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror

                <div class="mt-2">
                    <div class="text-sm font-semibold text-neutral-800 dark:text-neutral-200">{{ __('Available Advances') }}</div>
                    <div class="mt-2 overflow-x-auto">
                        <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                                <tr>
                                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Payment #') }}</th>
                                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Date') }}</th>
                                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Method') }}</th>
                                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Remaining') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                                @forelse ($available_advances as $adv)
                                    <tr>
                                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">#{{ $adv['id'] }}</td>
                                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $adv['received_at'] }}</td>
                                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ strtoupper($adv['method'] ?? '—') }}</td>
                                        <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ $this->formatMoney($adv['remaining_cents']) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="px-3 py-3 text-sm text-neutral-600 dark:text-neutral-300 text-center">{{ __('No available advances.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endif

        <div class="border-t border-neutral-200 pt-4 dark:border-neutral-700">
            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Allocations') }}</h2>
            <div class="mt-3 space-y-2">
                @forelse ($invoice->paymentAllocations as $allocRow)
                    <div class="flex items-center justify-between rounded-md border border-neutral-200 p-3 text-sm dark:border-neutral-700">
                        <div class="text-neutral-700 dark:text-neutral-200">
                            {{ strtoupper($allocRow->payment?->method ?? '—') }}
                            <span class="text-xs text-neutral-500 dark:text-neutral-400">• {{ $allocRow->payment?->received_at?->format('Y-m-d H:i') }}</span>
                        </div>
                        <div class="font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($allocRow->amount_cents) }}</div>
                    </div>
                @empty
                    <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ __('No allocations.') }}</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
