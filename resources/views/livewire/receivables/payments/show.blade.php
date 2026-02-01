<?php

use App\Models\Payment;
use App\Support\Money\MinorUnits;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public Payment $payment;

    public function mount(Payment $payment): void
    {
        $this->payment = $payment->load(['customer', 'allocations.allocatable']);
    }

    public function formatMoney(?int $cents): string
    {
        return MinorUnits::format((int) ($cents ?? 0));
    }
}; ?>

<div class="w-full max-w-5xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Payment') }} #{{ $payment->id }}</h1>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ $payment->received_at?->format('Y-m-d H:i') }}</p>
        </div>
        <div class="flex items-center gap-2">
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
        </div>
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-2">
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Amount') }}: {{ $this->formatMoney($payment->amount_cents) }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Allocated') }}: {{ $this->formatMoney($allocated) }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Unallocated') }}: {{ $this->formatMoney($remaining) }}</p>
        </div>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <h3 class="text-sm font-semibold text-neutral-800 dark:text-neutral-200 mb-2">{{ __('Allocations') }}</h3>
        <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Invoice') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Amount') }}</th>
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
                        <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ $this->formatMoney($alloc->amount_cents) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="2" class="px-3 py-3 text-sm text-neutral-600 dark:text-neutral-300 text-center">{{ __('No allocations') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
