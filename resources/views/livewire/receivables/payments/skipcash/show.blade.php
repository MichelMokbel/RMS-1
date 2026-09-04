<?php

use App\Models\AccountingAuditLog;
use App\Models\PaymentCheckoutAttempt;
use App\Services\Payments\PaymentOperationsQueryService;
use App\Services\Payments\PaymentOperationsRecoveryService;
use App\Support\Money\MinorUnits;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public int $attemptId;

    public function mount(PaymentCheckoutAttempt $checkout, PaymentOperationsQueryService $queries): void
    {
        $this->attemptId = $queries->find(Auth::user(), (int) $checkout->id)->id;
    }

    public function retryProcessing(PaymentOperationsRecoveryService $recovery): void
    {
        $result = $recovery->retry($this->attemptId, (string) Str::uuid(), Auth::user());
        session()->flash('status', $result['state'] === 'queued'
            ? __('Payment processing retry queued.')
            : __('Payment processing retry is :state.', ['state' => $result['state']]));
    }

    public function with(PaymentOperationsQueryService $queries): array
    {
        $checkout = $queries->find(Auth::user(), $this->attemptId);

        return [
            'checkout' => $checkout,
            'history' => AccountingAuditLog::query()
                ->where('subject_type', PaymentCheckoutAttempt::class)
                ->where('subject_id', $checkout->id)
                ->where('action', 'like', 'payment.operations.%')
                ->latest('id')
                ->limit(20)
                ->get(),
        ];
    }

    public function formatMoney(?int $cents): string
    {
        return MinorUnits::format((int) ($cents ?? 0));
    }
}; ?>

@php
    $provider = $checkout->providerTransactions->first();
    $payment = $provider?->payment;
    $allocated = $payment?->allocations?->sum('amount_cents');
    $issue = $checkout->operations_tracking['issues']['processing'] ?? null;
    $recovery = $checkout->operations_tracking['recovery'] ?? null;
    $canRetry = $checkout->state === 'paid_processing' && is_array($issue) && empty($issue['resolved_at']);
@endphp

<div class="app-page space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('SkipCash checkout') }}</h1>
            <p class="mt-1 break-all text-sm text-neutral-600 dark:text-neutral-300">{{ $checkout->reference }}</p>
        </div>
        <flux:button :href="route('receivables.payments.skipcash.index')" variant="ghost" wire:navigate>{{ __('Back to SkipCash') }}</flux:button>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">{{ session('status') }}</div>
    @endif

    @error('retry')
        <div class="rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 dark:border-rose-900 dark:bg-rose-950 dark:text-rose-100">{{ $message }}</div>
    @enderror

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <section class="rounded-lg border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
            <h2 class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Provider payment') }}</h2>
            <dl class="mt-3 space-y-2 text-sm">
                <div><dt class="text-neutral-500">{{ __('Status') }}</dt><dd>{{ $provider?->verified_paid_at ? __('Paid verified') : ($provider?->normalized_status ?? __('Not recorded')) }}</dd></div>
                <div><dt class="text-neutral-500">{{ __('Provider reference') }}</dt><dd class="break-all">{{ $provider?->provider_payment_id ?? __('Not recorded') }}</dd></div>
                <div><dt class="text-neutral-500">{{ __('Accepted amount') }}</dt><dd>{{ $provider?->verified_paid_at ? $this->formatMoney($provider->verified_amount_cents) : __('Not recorded') }}</dd></div>
            </dl>
        </section>

        <section class="rounded-lg border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
            <h2 class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('RMS completion') }}</h2>
            <dl class="mt-3 space-y-2 text-sm">
                <div><dt class="text-neutral-500">{{ __('Result') }}</dt><dd>{{ (string) str($checkout->state)->replace('_', ' ')->title() }}</dd></div>
                <div><dt class="text-neutral-500">{{ __('Receipt') }}</dt><dd>{{ $payment ? '#'.$payment->id : __('Not recorded') }}</dd></div>
                <div><dt class="text-neutral-500">{{ __('Receipt amount') }}</dt><dd>{{ $payment ? $this->formatMoney($payment->amount_cents) : __('Not recorded') }}</dd></div>
                <div><dt class="text-neutral-500">{{ __('Allocated') }}</dt><dd>{{ $payment ? $this->formatMoney((int) $allocated) : __('Not recorded') }}</dd></div>
            </dl>
        </section>

        <section class="rounded-lg border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
            <h2 class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Confirmation email') }}</h2>
            <dl class="mt-3 space-y-2 text-sm">
                <div><dt class="text-neutral-500">{{ __('Customer') }}</dt><dd>{{ data_get($checkout->notification_dispatch, 'customer_confirmation.state', __('Not created')) }}</dd></div>
                <div><dt class="text-neutral-500">{{ __('Administrator') }}</dt><dd>{{ data_get($checkout->notification_dispatch, 'admin_confirmation.state', __('Not created')) }}</dd></div>
            </dl>
        </section>

        <section class="rounded-lg border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
            <h2 class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Settlement') }}</h2>
            <div class="mt-3 text-sm">
                @if ($provider?->activeClearingSettlement)
                    <a class="font-medium text-primary-700 underline dark:text-primary-300" href="{{ route('accounting.ar-clearing-show', $provider->activeClearingSettlement) }}" wire:navigate>{{ __('View settlement #:id', ['id' => $provider->activeClearingSettlement->id]) }}</a>
                @else
                    <span>{{ __('Not settled') }}</span>
                @endif
            </div>
        </section>
    </div>

    <section class="rounded-lg border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Current processing issue') }}</h2>
                @if (is_array($issue) && empty($issue['resolved_at']))
                    <p class="mt-2 text-sm text-amber-800 dark:text-amber-200">{{ (string) str($issue['reason_code'])->replace('_', ' ')->title() }}</p>
                    <p class="mt-1 text-xs text-neutral-500">{{ __('First observed :time', ['time' => \Illuminate\Support\Carbon::parse($issue['first_seen_at'])->format('Y-m-d H:i:s')]) }}</p>
                    <p class="mt-1 text-xs text-neutral-500">{{ __('Administrator alert: :state', ['state' => data_get($issue, 'alert.state', __('Waiting'))]) }}</p>
                @else
                    <p class="mt-2 text-sm text-neutral-600 dark:text-neutral-300">{{ __('No unresolved processing issue.') }}</p>
                @endif
                @if (is_array($recovery))
                    <p class="mt-2 text-xs text-neutral-500">{{ __('Latest staff retry: :state', ['state' => $recovery['state'] ?? __('Unknown')]) }}</p>
                @endif
            </div>
            @if ($canRetry && (Auth::user()?->isAdmin() || Auth::user()?->can('payments.support.recover')))
                <flux:button type="button" variant="primary" wire:click="retryProcessing" wire:loading.attr="disabled" wire:target="retryProcessing">{{ __('Retry processing') }}</flux:button>
            @endif
        </div>
    </section>

    <section class="rounded-lg border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
        <h2 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Orders and invoices') }}</h2>
        <div class="mt-3 overflow-x-auto">
            <table class="w-full min-w-[620px] text-sm">
                <thead><tr class="border-b border-neutral-200 text-left dark:border-neutral-700"><th class="py-2">{{ __('Service date') }}</th><th>{{ __('Order') }}</th><th>{{ __('Invoice') }}</th><th>{{ __('Invoice status') }}</th><th class="text-right">{{ __('Amount') }}</th></tr></thead>
                <tbody>
                    @foreach ($checkout->targets as $target)
                        <tr class="border-b border-neutral-100 dark:border-neutral-800"><td class="py-2">{{ $target->service_date?->format('Y-m-d') }}</td><td>{{ $target->order_id ? '#'.$target->order_id : __('Not recorded') }}</td><td>{{ $target->invoice_id ? '#'.$target->invoice_id : __('Not recorded') }}</td><td>{{ $target->invoice?->status ?? __('Not recorded') }}</td><td class="text-right">{{ $this->formatMoney($target->expected_amount_cents) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="rounded-lg border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
        <h2 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Operations history') }}</h2>
        <div class="mt-3 space-y-2">
            @forelse ($history as $entry)
                <div class="flex flex-wrap justify-between gap-2 border-b border-neutral-100 py-2 text-sm dark:border-neutral-800"><span>{{ (string) str($entry->action)->after('payment.operations.')->replace('_', ' ')->title() }}</span><span class="text-neutral-500">{{ $entry->created_at?->format('Y-m-d H:i:s') }}</span></div>
            @empty
                <p class="text-sm text-neutral-500">{{ __('No operations activity recorded.') }}</p>
            @endforelse
        </div>
    </section>
</div>
