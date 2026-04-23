<?php

use App\Models\ArClearingSettlement;
use App\Services\AR\ArClearingSettlementService;
use App\Support\Money\MinorUnits;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public ArClearingSettlement $settlement;

    public string $void_reason = '';
    public bool $confirm_void = false;

    public function mount(ArClearingSettlement $settlement): void
    {
        $this->settlement = $settlement->load([
            'bankAccount',
            'items.payment.customer',
        ]);
    }

    public function voidSettlement(ArClearingSettlementService $service): void
    {
        if ($this->settlement->voided_at) {
            return;
        }

        try {
            $service->void($this->settlement, (int) Auth::id(), $this->void_reason !== '' ? $this->void_reason : null);

            $this->settlement = $this->settlement->fresh([
                'bankAccount',
                'items.payment.customer',
            ]);
            $this->confirm_void = false;
            $this->void_reason = '';

            session()->flash('status', __('Settlement #:id has been voided.', ['id' => $this->settlement->id]));
        } catch (ValidationException $e) {
            session()->flash('error', collect($e->errors())->flatten()->first());
        } catch (\Throwable $e) {
            session()->flash('error', __('Failed to void settlement: :msg', ['msg' => $e->getMessage()]));
        }
    }

    public function formatMoney(?int $cents): string
    {
        return MinorUnits::format((int) ($cents ?? 0));
    }
}; ?>

<div class="w-full max-w-6xl mx-auto px-4 space-y-6">

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                {{ __('Settlement') }} #{{ $settlement->id }}
                @if ($settlement->voided_at)
                    <span class="ml-2 inline-flex items-center rounded-full bg-red-100 px-2.5 py-0.5 text-sm font-semibold text-red-800 dark:bg-red-900/40 dark:text-red-300">
                        {{ __('Voided') }}
                    </span>
                @else
                    <span class="ml-2 inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-0.5 text-sm font-semibold text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300">
                        {{ __('Active') }}
                    </span>
                @endif
            </h1>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('AR Clearing Settlement') }}</p>
        </div>

        <div class="flex items-center gap-2">
            @if (! $settlement->voided_at)
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
                {{ __('Are you sure you want to void Settlement #:id? This action cannot be undone.', ['id' => $settlement->id]) }}
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

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
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
                <span class="font-medium">{{ __('Amount') }}:</span>
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
        </div>
    </div>

    @if ($settlement->voided_at)
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 dark:border-red-800 dark:bg-red-950/20 space-y-1">
            <p class="text-sm font-semibold text-red-800 dark:text-red-300">{{ __('This settlement has been voided.') }}</p>
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

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <h3 class="mb-1 text-sm font-semibold text-neutral-800 dark:text-neutral-200">{{ __('Accounting Entry') }}</h3>
        <p class="text-sm text-neutral-600 dark:text-neutral-400">
            {{ __('Settlement posted:') }}
            <span class="font-medium text-neutral-800 dark:text-neutral-200">DR Bank</span>
            /
            <span class="font-medium text-neutral-800 dark:text-neutral-200">
                {{ $settlement->settlement_method === 'card' ? __('CR Card Clearing') : __('CR Cheque Clearing') }}
            </span>
        </p>
    </div>
</div>
