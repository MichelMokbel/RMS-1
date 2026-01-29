<?php

use App\Models\ApPayment;
use App\Services\AP\ApPaymentVoidService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public ApPayment $payment;

    public function mount(ApPayment $payment): void
    {
        $this->payment = $payment->load(['supplier', 'allocations.invoice', 'voidedBy']);
    }

    public function voidPayment(ApPaymentVoidService $voidService): void
    {
        if ($this->payment->voided_at) {
            return;
        }

        $voidService->void($this->payment, Illuminate\Support\Facades\Auth::id());
        $this->payment = $this->payment->fresh(['supplier', 'allocations.invoice', 'voidedBy']);
        session()->flash('status', __('Payment voided.'));
    }
}; ?>

<div class="w-full max-w-5xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Payment') }} #{{ $payment->id }}</h1>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ $payment->payment_date?->format('Y-m-d') }}</p>
        </div>
        <div class="flex items-center gap-2">
            @if(! $payment->voided_at)
                <flux:button type="button" wire:click="voidPayment" variant="ghost">{{ __('Void') }}</flux:button>
            @endif
            <flux:button :href="route('payables.index')" wire:navigate variant="ghost">{{ __('Back') }}</flux:button>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-2">
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Supplier') }}: {{ $payment->supplier->name ?? '—' }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Method') }}: {{ $payment->payment_method ?? '—' }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Reference') }}: {{ $payment->reference ?? '—' }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Notes') }}: {{ $payment->notes ?? '—' }}</p>
            @if($payment->voided_at)
                <p class="text-sm text-amber-700 dark:text-amber-200">
                    {{ __('Voided at') }} {{ $payment->voided_at?->format('Y-m-d H:i') }}
                    @if($payment->voidedBy)
                        • {{ $payment->voidedBy->username ?? $payment->voidedBy->email }}
                    @endif
                </p>
            @endif
        </div>
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-2">
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Amount') }}: {{ number_format((float)$payment->amount, 2) }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Allocated') }}: {{ number_format((float)$payment->allocations->sum('allocated_amount'), 2) }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Unallocated') }}: {{ number_format((float)$payment->amount - (float)$payment->allocations->sum('allocated_amount'), 2) }}</p>
        </div>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <h3 class="text-sm font-semibold text-neutral-800 dark:text-neutral-200 mb-2">{{ __('Allocations') }}</h3>
        <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Invoice') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Amount') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                @forelse ($payment->allocations as $alloc)
                    <tr>
                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $alloc->invoice->invoice_number ?? $alloc->invoice_id }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ number_format((float)$alloc->allocated_amount, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="2" class="px-3 py-3 text-sm text-neutral-600 dark:text-neutral-300 text-center">{{ __('No allocations') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
