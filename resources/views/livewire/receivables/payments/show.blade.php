<?php

use App\Models\ArInvoice;
use App\Models\MealSubscription;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Services\AR\ArPaymentDeleteService;
use App\Services\AR\ArPaymentService;
use App\Services\Subscriptions\SubscriptionPaymentLinkService;
use App\Support\Money\MinorUnits;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public Payment $payment;
    public array $allocations = [];
    public bool $select_all_allocations = false;
    public string $invoice_date_from = '';
    public string $invoice_date_to = '';
    public ?int $link_subscription_id = null;
    public string $new_subscription_plan = '';

    public function mount(Payment $payment): void
    {
        $this->refreshPayment($payment);
        $this->loadInvoices();
    }

    public function with(): array
    {
        // Always reload payment with fresh relations so re-renders after Livewire hydration
        // (e.g., wire:model.live changes) show up-to-date allocations.
        $this->payment = Payment::query()
            ->with(['customer', 'allocations.allocatable'])
            ->findOrFail($this->payment->id);

        $settlementItem = \App\Models\ArClearingSettlementItem::where('payment_id', $this->payment->id)
            ->whereHas('settlement', fn($q) => $q->whereNull('voided_at'))
            ->with('settlement')
            ->first();

        return [
            'settlementItem'        => $settlementItem,
            'linkedSubscriptions'   => $this->payment->mealSubscriptions()->with('customer')->get(),
            'linkableSubscriptions' => $this->payment->customer_id
                ? MealSubscription::where('customer_id', $this->payment->customer_id)
                    ->whereNull('source_payment_id')
                    ->whereIn('status', ['active', 'paused'])
                    ->orderByDesc('start_date')
                    ->get()
                : collect(),
            'detectedPlans' => $this->payment->customer_id
                ? app(SubscriptionPaymentLinkService::class)->detectPlanFromPayment($this->payment)
                : [],
        ];
    }

    public function linkSubscription(SubscriptionPaymentLinkService $service): void
    {
        $this->resetErrorBag();

        $userId = Auth::id();
        if (! $userId) {
            abort(403);
        }

        $this->validate(['link_subscription_id' => ['required', 'integer']]);

        try {
            $sub = MealSubscription::findOrFail((int) $this->link_subscription_id);
            $service->linkPaymentToSubscription($this->payment, $sub, $userId);
            $this->link_subscription_id = null;
            session()->flash('status', __('Subscription linked.'));
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError('link_subscription', $message);
                }
            }
        }
    }

    public function formatMoney(?int $cents): string
    {
        return MinorUnits::format((int) ($cents ?? 0));
    }

    public function canDeletePayment(): bool
    {
        $user = Auth::user();

        return (bool) ($user && method_exists($user, 'hasRole') && $user->hasRole('admin'));
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

    public function loadInvoices(): void
    {
        if (
            $this->payment->source !== 'ar'
            || ! $this->payment->customer_id
            || $this->payment->unallocatedCents() <= 0
        ) {
            $this->allocations = [];
            $this->select_all_allocations = false;
            return;
        }

        $query = ArInvoice::query()
            ->where('customer_id', $this->payment->customer_id)
            ->whereIn('status', ['issued', 'partially_paid']);

        if ($this->payment->branch_id) {
            $query->where('branch_id', $this->payment->branch_id);
        }

        if ($this->payment->currency) {
            $query->where(function ($inner) {
                $inner->whereNull('currency')
                    ->orWhere('currency', $this->payment->currency);
            });
        }

        if ($this->invoice_date_from !== '') {
            $query->whereDate('issue_date', '>=', $this->invoice_date_from);
        }
        if ($this->invoice_date_to !== '') {
            $query->whereDate('issue_date', '<=', $this->invoice_date_to);
        }

        $this->allocations = $query
            ->orderByDesc('issue_date')
            ->get()
            ->map(function (ArInvoice $invoice) {
                $outstanding = (int) ($invoice->balance_cents ?? 0);

                return [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number ?: '#'.$invoice->id,
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

        $this->syncSelectAllAllocations();
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
        foreach ($this->allocations as $idx => $allocation) {
            $this->allocations[$idx]['selected'] = $selected;
        }
    }

    public function updated($property): void
    {
        if (str_starts_with((string) $property, 'allocations.')) {
            $this->syncSelectAllAllocations();
        }
    }

    public function allocateInvoices(ArPaymentService $payments): void
    {
        $this->resetErrorBag();

        $userId = Auth::id();
        if (! $userId) {
            abort(403);
        }

        if ($this->payment->unallocatedCents() <= 0) {
            $this->addError('allocations', __('This payment has no remaining balance to allocate.'));
            return;
        }

        $rows = [];
        $requestedTotal = 0;

        foreach ($this->allocations as $row) {
            if (! ($row['selected'] ?? false)) {
                continue;
            }

            try {
                $amountCents = MinorUnits::parsePos((string) ($row['amount'] ?? ''));
            } catch (\InvalidArgumentException $e) {
                $this->addError('allocations', __('Invalid allocation amount.'));
                return;
            }

            if ($amountCents <= 0) {
                continue;
            }

            $rows[] = [
                'invoice_id' => (int) ($row['invoice_id'] ?? 0),
                'amount_cents' => $amountCents,
            ];
            $requestedTotal += $amountCents;
        }

        if ($rows === []) {
            $this->addError('allocations', __('Select at least one invoice.'));
            return;
        }

        if ($requestedTotal > $this->payment->unallocatedCents()) {
            $this->addError('allocations', __('Allocations exceed remaining payment amount.'));
            return;
        }

        try {
            $payments->applyExistingPaymentAllocations($this->payment->id, $rows, $userId);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $target = in_array($field, ['amount_cents', 'invoice', 'payment'], true) ? 'allocations' : $field;
                foreach ($messages as $message) {
                    $this->addError($target, $message);
                }
            }
            return;
        }

        $this->refreshPayment();
        $this->loadInvoices();
        session()->flash('status', __('Payment allocated.'));
    }

    public function removeAllocation(int $allocationId, ArPaymentDeleteService $service): void
    {
        if (! Auth::user()?->hasRole('admin')) {
            abort(403);
        }

        $allocation = PaymentAllocation::findOrFail($allocationId);

        try {
            $service->removeAllocation($allocation, Auth::id());
        } catch (\Illuminate\Validation\ValidationException $e) {
            foreach ($e->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->addError('allocation', $message);
                }
            }
            return;
        }

        $this->refreshPayment();
        $this->loadInvoices();
        session()->flash('status', __('Allocation removed.'));
    }

    private function refreshPayment(?Payment $payment = null): void
    {
        $model = $payment ?? $this->payment;
        $this->payment = Payment::query()
            ->with(['customer', 'allocations.allocatable'])
            ->findOrFail($model->id);
    }

    private function syncSelectAllAllocations(): void
    {
        $this->select_all_allocations = count($this->allocations) > 0
            && collect($this->allocations)->every(fn ($allocation) => (bool) ($allocation['selected'] ?? false));
    }
}; ?>

<div class="w-full max-w-5xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Payment') }} #{{ $payment->id }}</h1>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ $payment->received_at?->format('Y-m-d H:i') }}</p>
        </div>
        <div class="flex items-center gap-2">
            @if($this->canDeletePayment())
                <form method="POST" action="{{ route('receivables.payments.destroy', $payment) }}">
                    @csrf
                    @method('DELETE')
                    <flux:button type="submit" variant="ghost" class="text-rose-600" onclick="return confirm('{{ __('Delete this payment? This action cannot be undone.') }}')">
                        {{ __('Delete Payment') }}
                    </flux:button>
                </form>
            @endif
            <flux:button :href="route('receivables.payments.create', ['branch_id' => $payment->branch_id])" wire:navigate variant="ghost">{{ __('Create New Payment') }}</flux:button>
            <flux:button :href="route('receivables.payments.print', $payment)" target="_blank" variant="ghost">{{ __('Print Receipt') }}</flux:button>
            <flux:button :href="route('receivables.payments.index')" wire:navigate variant="ghost">{{ __('Back') }}</flux:button>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    @php
        $allocated = (int) $payment->allocations->sum('amount_cents');
        $remaining = (int) $payment->amount_cents - $allocated;
    @endphp

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-2">
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Customer') }}: {{ $payment->customer?->name ?? '—' }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Method') }}: {{ strtoupper($payment->method ?? '—') }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Reference') }}: {{ $payment->reference ?? '—' }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Notes') }}: {{ $payment->notes ?? '—' }}</p>
            @if($payment->clearing_settled_at)
                <p class="text-sm text-emerald-700 dark:text-emerald-300">
                    {{ __('Cleared to bank') }}: {{ $payment->clearing_settled_at?->format('Y-m-d H:i') }}
                    @if($settlementItem)
                        · <a href="{{ route('accounting.ar-clearing-show', $settlementItem->settlement_id) }}" wire:navigate class="underline">{{ __('View Settlement') }}</a>
                    @endif
                </p>
            @elseif(in_array($payment->method, ['card', 'cheque']) && ! $payment->voided_at)
                <p class="text-sm text-amber-700 dark:text-amber-300">
                    {{ __('Pending clearing') }} ·
                    <a href="{{ route('accounting.ar-clearing') }}" wire:navigate class="underline">{{ __('Go to Clearing Workbench') }}</a>
                </p>
            @endif
        </div>
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-2">
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Amount') }}: {{ $this->formatMoney($payment->amount_cents) }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Allocated') }}: {{ $this->formatMoney($allocated) }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Unallocated') }}: {{ $this->formatMoney($remaining) }}</p>
        </div>
    </div>

    @if($payment->source === 'ar' && $payment->customer_id && $payment->unallocatedCents() > 0)
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-semibold text-neutral-800 dark:text-neutral-200">{{ __('Allocate Payment') }}</h3>
                <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Remaining') }}: {{ $this->formatMoney($payment->unallocatedCents()) }}</p>
            </div>

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
                        @forelse ($allocations as $idx => $allocation)
                            <tr>
                                <td class="px-3 py-2 text-sm">
                                    <input type="checkbox" wire:model.live="allocations.{{ $idx }}.selected" class="rounded border-neutral-300 text-primary-600 shadow-sm focus:ring-primary-500">
                                </td>
                                <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $allocation['invoice_number'] }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $allocation['issue_date'] }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $allocation['due_date'] }}</td>
                                <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ $this->formatMoney($allocation['outstanding_cents']) }}</td>
                                <td class="px-3 py-2 text-sm text-right">
                                    <flux:input wire:model.live="allocations.{{ $idx }}.amount" type="number" step="{{ $this->moneyStep() }}" min="0" />
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-3 py-3 text-sm text-center text-neutral-600 dark:text-neutral-300">{{ __('No open invoices for customer') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="flex justify-end">
                <flux:button type="button" wire:click="allocateInvoices" variant="primary">{{ __('Apply Allocations') }}</flux:button>
            </div>
        </div>
    @endif

    {{-- Linked Subscriptions --}}
    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
        <h3 class="text-sm font-semibold text-neutral-800 dark:text-neutral-200">{{ __('Linked Subscriptions') }}</h3>

        @forelse ($linkedSubscriptions as $sub)
            <div class="flex items-center justify-between text-sm">
                <span class="text-neutral-800 dark:text-neutral-100">{{ $sub->subscription_code }} · {{ $sub->customer?->name ?? '—' }} · {{ $sub->plan_meals_total ? $sub->plan_meals_total . ' ' . __('meals') : __('Unlimited') }}</span>
                <flux:button :href="route('subscriptions.show', $sub)" wire:navigate size="sm" variant="ghost">{{ __('View') }}</flux:button>
            </div>
        @empty
            <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ __('No subscriptions linked.') }}</p>
        @endforelse

        @error('link_subscription') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror

        @if($linkableSubscriptions->isNotEmpty())
            <div class="flex items-center gap-2">
                <select wire:model="link_subscription_id" class="flex-1 rounded-md border border-neutral-200 bg-white px-3 py-1.5 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="">{{ __('Select subscription to link…') }}</option>
                    @foreach($linkableSubscriptions as $sub)
                        <option value="{{ $sub->id }}">{{ $sub->subscription_code }} ({{ $sub->start_date?->format('Y-m-d') }} · {{ $sub->plan_meals_total ? $sub->plan_meals_total . ' meals' : 'Unlimited' }})</option>
                    @endforeach
                </select>
                <flux:button wire:click="linkSubscription" size="sm">{{ __('Link') }}</flux:button>
            </div>
        @endif

        @if($payment->customer_id)
            @php
                $planOptions = collect(config('subscriptions.plan_menu_item_ids', []))->keys()->sort()->values();
                $detectedMeals = collect($detectedPlans)->pluck('plan_meals_total')->first();
            @endphp
            <div class="flex items-center gap-2">
                <select wire:model.live="new_subscription_plan" class="rounded-md border border-neutral-200 bg-white px-3 py-1.5 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="">{{ __('Select plan size…') }}</option>
                    @foreach($planOptions as $meals)
                        <option value="{{ $meals }}" @selected($detectedMeals == $meals)>{{ $meals }} {{ __('meals') }}</option>
                    @endforeach
                    <option value="0">{{ __('Unlimited') }}</option>
                </select>
                @if($new_subscription_plan !== '')
                    <flux:button
                        :href="route('subscriptions.create', [
                            'customer_id'       => $payment->customer_id,
                            'plan_meals_total'  => $new_subscription_plan > 0 ? $new_subscription_plan : null,
                            'source_payment_id' => $payment->id,
                        ])"
                        wire:navigate size="sm" variant="primary">
                        {{ __('Create Subscription') }}
                    </flux:button>
                @else
                    <flux:button size="sm" variant="primary" disabled>{{ __('Create Subscription') }}</flux:button>
                @endif
            </div>
        @endif
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <h3 class="text-sm font-semibold text-neutral-800 dark:text-neutral-200 mb-2">{{ __('Allocations') }}</h3>
        @error('allocation') <p class="text-xs text-rose-600 mb-2">{{ $message }}</p> @enderror
        <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Invoice') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Invoice Date') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Amount') }}</th>
                    @if(auth()->user()?->hasRole('admin'))
                        <th class="px-3 py-2"></th>
                    @endif
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                @forelse ($payment->allocations as $alloc)
                    @php
                        $invoice = $alloc->allocatable;
                    @endphp
                    <tr>
                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">
                            {{ $invoice?->invoice_number ?: ($invoice ? '#'.$invoice->id : '—') }}
                        </td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                            {{ $invoice?->issue_date?->format('Y-m-d') ?? '—' }}
                        </td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ $this->formatMoney($alloc->amount_cents) }}</td>
                        @if(auth()->user()?->hasRole('admin'))
                            <td class="px-3 py-2 text-right">
                                <flux:button
                                    wire:click="removeAllocation({{ $alloc->id }})"
                                    wire:confirm="{{ __('Remove this allocation? The invoice balance will be restored.') }}"
                                    size="xs" variant="ghost" class="text-rose-600 hover:text-rose-700">
                                    {{ __('Remove') }}
                                </flux:button>
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="{{ auth()->user()?->hasRole('admin') ? 4 : 3 }}" class="px-3 py-3 text-sm text-neutral-600 dark:text-neutral-300 text-center">{{ __('No allocations') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
