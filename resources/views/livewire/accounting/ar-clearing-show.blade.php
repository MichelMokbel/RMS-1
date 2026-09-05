<?php

use App\Models\ArClearingSettlement;
use App\Models\BankTransaction;
use App\Models\User;
use App\Services\AR\ArClearingSettlementService;
use App\Services\Payments\GatewaySettlementReviewService;
use App\Support\Money\MinorUnits;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public ArClearingSettlement $settlement;

    public string $void_reason = '';

    public bool $confirm_void = false;

    public ?BankTransaction $bookTransaction = null;

    public function mount(ArClearingSettlement $settlement, GatewaySettlementReviewService $reviews): void
    {
        if ($settlement->settlement_method === 'skipcash') {
            $actor = Auth::user();
            abort_unless($actor instanceof User && $settlement->gatewayImport, 404);
            $reviews->authorizeImport($settlement->gatewayImport, $actor);
        }

        $this->settlement = $settlement;
        $this->refreshSettlement();
    }

    public function voidSettlement(ArClearingSettlementService $service): void
    {
        if ($this->settlement->voided_at) {
            return;
        }

        try {
            $service->void($this->settlement, (int) Auth::id(), $this->void_reason !== '' ? $this->void_reason : null);

            $this->refreshSettlement();
            $this->confirm_void = false;
            $this->void_reason = '';

            session()->flash('status', __('Settlement #:id has been voided.', ['id' => $this->settlement->id]));
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (ValidationException $e) {
            session()->flash('error', collect($e->errors())->flatten()->first());
        } catch (\Throwable $e) {
            report($e);
            session()->flash('error', __('The settlement could not be voided. No financial record was changed.'));
        }
    }

    public function formatMoney(?int $cents): string
    {
        return MinorUnits::format((int) ($cents ?? 0));
    }

    private function refreshSettlement(): void
    {
        $this->settlement = $this->settlement->fresh([
            'bankAccount',
            'gatewayImport',
            'evidenceBankTransaction.matchedTransaction',
            'adjustments.expenseAccount',
            'items.payment.customer',
            'items.providerTransaction',
        ]);
        $this->bookTransaction = BankTransaction::query()
            ->where('source_type', ArClearingSettlement::class)
            ->where('source_id', $this->settlement->id)
            ->where('transaction_type', 'ar_clearing_settlement')
            ->first();
    }
}; ?>

<div class="w-full max-w-6xl mx-auto px-4 space-y-6">

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                {{ __('Settlement') }} #{{ $settlement->id }}
                @if ($settlement->voided_at)
                    <span class="ml-2 inline-flex items-center rounded-full bg-red-100 px-2.5 py-0.5 text-sm font-semibold text-red-800 dark:bg-red-900/40 dark:text-red-300">
                        {{ $settlement->settlement_method === 'skipcash' ? __('Voided · correction required') : __('Voided') }}
                    </span>
                @else
                    <span class="ml-2 inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-0.5 text-sm font-semibold text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300">
                        {{ __('Active') }}
                    </span>
                @endif
            </h1>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">
                {{ $settlement->settlement_method === 'skipcash' ? __('SkipCash payout clearing') : __('AR Clearing Settlement') }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @can('finance.write')
                <flux:button :href="route('accounting.ar-clearing')" wire:navigate variant="primary" class="touch-target">{{ __('Create New Settlement') }}</flux:button>
            @endcan
            @if (! $settlement->voided_at && ($settlement->settlement_method !== 'skipcash' || Auth::user()?->can('gateway_settlements.void')))
                <flux:button type="button" wire:click="$set('confirm_void', true)" variant="ghost">
                    {{ __('Void') }}
                </flux:button>
            @endif

            <flux:button :href="route('accounting.ar-clearing')" wire:navigate variant="ghost">
                {{ __('Back') }}
            </flux:button>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    @if (session('error'))
        <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-100">
            {{ session('error') }}
        </div>
    @endif

    @if ($confirm_void && ! $settlement->voided_at)
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950/30">
            <p class="mb-3 text-sm font-medium text-amber-900 dark:text-amber-200">
                @if ($settlement->settlement_method === 'skipcash')
                    {{ __('Voiding Settlement #:id creates an accounting reversal and releases its SkipCash payment claims. The original customer receipts, invoices, and allocations remain unchanged, and this payout will require a correction.', ['id' => $settlement->id]) }}
                @else
                    {{ __('Are you sure you want to void Settlement #:id? This action cannot be undone.', ['id' => $settlement->id]) }}
                @endif
            </p>
            <div class="mb-3">
                <label class="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-200">
                    {{ __('Void Reason') }} <span class="text-neutral-500 dark:text-neutral-400">({{ __('optional') }})</span>
                </label>
                <textarea
                    wire:model="void_reason"
                    rows="2"
                    placeholder="{{ __('Enter reason for voiding…') }}"
                    class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 placeholder-neutral-400 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50 dark:placeholder-neutral-500"
                ></textarea>
            </div>
            <div class="flex gap-2">
                <flux:button type="button" variant="primary" wire:click="voidSettlement" wire:loading.attr="disabled">
                    {{ __('Confirm Void') }}
                </flux:button>
                <flux:button type="button" variant="ghost" wire:click="$set('confirm_void', false)">
                    {{ __('Cancel') }}
                </flux:button>
            </div>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 {{ $settlement->settlement_method === 'skipcash' ? 'xl:grid-cols-3' : '' }}">
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-2">
            <h3 class="text-sm font-semibold text-neutral-800 dark:text-neutral-200">{{ __('Settlement Details') }}</h3>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">
                <span class="font-medium">{{ __('Method') }}:</span>
                {{ strtoupper($settlement->settlement_method ?? '—') }}
            </p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">
                <span class="font-medium">{{ __('Settlement Date') }}:</span>
                {{ $settlement->settlement_date?->format('Y-m-d') ?? '—' }}
            </p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">
                <span class="font-medium">{{ $settlement->settlement_method === 'skipcash' ? __('Gross sales') : __('Amount') }}:</span>
                {{ $this->formatMoney($settlement->amount_cents) }}
            </p>
            @if ($settlement->reference)
                <p class="text-sm text-neutral-700 dark:text-neutral-200">
                    <span class="font-medium">{{ __('Reference') }}:</span>
                    {{ $settlement->reference }}
                </p>
            @endif
            @if ($settlement->notes)
                <p class="text-sm text-neutral-700 dark:text-neutral-200">
                    <span class="font-medium">{{ __('Notes') }}:</span>
                    {{ $settlement->notes }}
                </p>
            @endif
        </div>

        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-2">
            <h3 class="text-sm font-semibold text-neutral-800 dark:text-neutral-200">{{ __('Banking') }}</h3>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">
                <span class="font-medium">{{ __('Bank Account') }}:</span>
                {{ $settlement->bankAccount?->name ?? '—' }}
            </p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">
                <span class="font-medium">{{ __('Bank Ledger Account') }}:</span>
                {{ $settlement->bankAccount?->ledger_account_id ?? '—' }}
            </p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">
                <span class="font-medium">{{ __('Items Count') }}:</span>
                {{ $settlement->items->count() }}
            </p>
            @if ($settlement->settlement_method === 'skipcash')
                <p class="text-sm text-neutral-700 dark:text-neutral-200">
                    <span class="font-medium">{{ __('Bank evidence') }}:</span>
                    @if ($settlement->evidence_bank_transaction_id)
                        {{ __('Statement deposit #:id', ['id' => $settlement->evidence_bank_transaction_id]) }}
                    @else
                        {{ __('Retained remittance file') }}
                    @endif
                </p>
                <p class="text-sm text-neutral-700 dark:text-neutral-200">
                    <span class="font-medium">{{ __('Reconciliation') }}:</span>
                    @if ($bookTransaction?->matched_bank_transaction_id || $bookTransaction?->is_cleared)
                        <span class="font-medium text-emerald-700 dark:text-emerald-300">{{ __('Matched') }}</span>
                    @else
                        <span class="font-medium text-amber-700 dark:text-amber-300">{{ __('Not matched') }}</span>
                    @endif
                </p>
            @endif
        </div>

        @if ($settlement->settlement_method === 'skipcash')
            <div class="space-y-2 rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <h3 class="text-sm font-semibold text-neutral-800 dark:text-neutral-200">{{ __('Payout Breakdown') }}</h3>
                <p class="flex justify-between gap-3 text-sm text-neutral-700 dark:text-neutral-200"><span>{{ __('Gross sales') }}</span><span class="font-medium">{{ $this->formatMoney($settlement->amount_cents) }}</span></p>
                <p class="flex justify-between gap-3 text-sm text-neutral-700 dark:text-neutral-200"><span>{{ __('Commission') }}</span><span>− {{ $this->formatMoney($settlement->commission_cents) }}</span></p>
                <p class="flex justify-between gap-3 text-sm text-neutral-700 dark:text-neutral-200"><span>{{ __('Settlement fees') }}</span><span>− {{ $this->formatMoney($settlement->settlement_fee_cents) }}</span></p>
                <p class="flex justify-between gap-3 border-t border-neutral-200 pt-2 text-sm font-semibold text-neutral-900 dark:border-neutral-700 dark:text-neutral-100"><span>{{ __('Net bank deposit') }}</span><span>{{ $this->formatMoney($settlement->net_cents) }}</span></p>
            </div>
        @endif
    </div>

    @if ($settlement->voided_at)
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 dark:border-red-800 dark:bg-red-950/20 space-y-1">
            <p class="text-sm font-semibold text-red-800 dark:text-red-300">
                {{ $settlement->settlement_method === 'skipcash' ? __('This settlement was reversed and requires a correction. Its retained history cannot be posted again as a new payout.') : __('This settlement has been voided.') }}
            </p>
            <p class="text-sm text-red-700 dark:text-red-400">
                <span class="font-medium">{{ __('Voided at') }}:</span>
                {{ $settlement->voided_at?->format('Y-m-d H:i') ?? '—' }}
            </p>
            @if ($settlement->void_reason)
                <p class="text-sm text-red-700 dark:text-red-400">
                    <span class="font-medium">{{ __('Reason') }}:</span>
                    {{ $settlement->void_reason }}
                </p>
            @endif
        </div>
    @endif

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <h3 class="mb-2 text-sm font-semibold text-neutral-800 dark:text-neutral-200">{{ __('Settled Payments') }}</h3>

        <div class="app-table-shell">
            <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                    <tr>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Payment #') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Date') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Customer') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Reference') }}</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Amount') }}</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    @forelse ($settlement->items as $item)
                        <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                            <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $item->payment_id }}</td>
                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                                {{ $item->payment?->received_at?->format('Y-m-d') ?? '—' }}
                            </td>
                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $item->payment?->customer?->name ?? '—' }}</td>
                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $item->payment?->reference ?? '—' }}</td>
                            <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($item->amount_cents) }}</td>
                            <td class="px-3 py-2 text-sm text-right">
                                @if ($item->payment)
                                    <flux:button size="xs" :href="route('receivables.payments.show', $item->payment)" wire:navigate>
                                        {{ __('View Payment') }}
                                    </flux:button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">
                                {{ __('No settlement items found.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($settlement->settlement_method === 'skipcash')
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <h3 class="mb-2 text-sm font-semibold text-neutral-800 dark:text-neutral-200">{{ __('Provider Deductions') }}</h3>
            <div class="app-table-shell overflow-x-auto">
                <table class="w-full min-w-[640px] divide-y divide-neutral-200 dark:divide-neutral-800">
                    <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                        <tr>
                            <th scope="col" class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Type') }}</th>
                            <th scope="col" class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Expense account') }}</th>
                            <th scope="col" class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Amount') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        @forelse ($settlement->adjustments as $adjustment)
                            <tr>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ str($adjustment->adjustment_type)->replace('_', ' ')->title() }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $adjustment->expenseAccount?->code }} · {{ $adjustment->expenseAccount?->name }}</td>
                                <td class="px-3 py-2 text-right text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($adjustment->amount_cents) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No provider deductions were recorded.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <h3 class="mb-1 text-sm font-semibold text-neutral-800 dark:text-neutral-200">{{ __('Accounting Entry') }}</h3>
        @if ($settlement->settlement_method === 'skipcash')
            <p class="text-sm text-neutral-600 dark:text-neutral-400">{{ __('The posted entry debits the bank for the net payout, debits commission and settlement-fee expenses, and credits SkipCash clearing for gross sales.') }}</p>
            <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-2">
                <div class="flex justify-between gap-3 rounded-md bg-neutral-50 px-3 py-2 dark:bg-neutral-800"><dt>{{ __('DR Bank') }}</dt><dd class="font-medium">{{ $this->formatMoney($settlement->net_cents) }}</dd></div>
                <div class="flex justify-between gap-3 rounded-md bg-neutral-50 px-3 py-2 dark:bg-neutral-800"><dt>{{ __('DR Commission expense') }}</dt><dd class="font-medium">{{ $this->formatMoney($settlement->commission_cents) }}</dd></div>
                <div class="flex justify-between gap-3 rounded-md bg-neutral-50 px-3 py-2 dark:bg-neutral-800"><dt>{{ __('DR Settlement-fee expense') }}</dt><dd class="font-medium">{{ $this->formatMoney($settlement->settlement_fee_cents) }}</dd></div>
                <div class="flex justify-between gap-3 rounded-md bg-neutral-50 px-3 py-2 dark:bg-neutral-800"><dt>{{ __('CR SkipCash clearing') }}</dt><dd class="font-medium">{{ $this->formatMoney($settlement->amount_cents) }}</dd></div>
            </dl>
        @else
            <p class="text-sm text-neutral-600 dark:text-neutral-400">
                {{ __('Settlement posted:') }}
                <span class="font-medium text-neutral-800 dark:text-neutral-200">DR Bank</span>
                /
                <span class="font-medium text-neutral-800 dark:text-neutral-200">
                    {{ $settlement->settlement_method === 'card' ? __('CR Card Clearing') : __('CR Cheque Clearing') }}
                </span>
            </p>
        @endif
    </div>
</div>
