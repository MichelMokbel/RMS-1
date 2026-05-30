<?php

use App\Models\MealSubscription;
use App\Services\Subscriptions\SubscriptionPaymentLinkService;
use App\Support\Money\MinorUnits;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public MealSubscription $subscription;
    #[Url(as: 'show_only_issues', keep: true)]
    public bool $show_only_issues = true;

    public function formatMoney(?int $cents): string
    {
        return MinorUnits::format((int) ($cents ?? 0));
    }

    public function problemLabel(?string $problem): string
    {
        return match ($problem) {
            'wrong_plan_item' => __('Wrong plan item'),
            'subscription_meta_only' => __('Subscription meta only'),
            'missing_plan_item' => __('Missing plan item'),
            'no_expected_plan_item' => __('No expected plan item configured'),
            default => __('Correct'),
        };
    }

    public function problemBadgeClass(?string $problem): string
    {
        return match ($problem) {
            null => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-100',
            'wrong_plan_item' => 'bg-rose-100 text-rose-800 dark:bg-rose-900 dark:text-rose-100',
            'subscription_meta_only' => 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-100',
            'missing_plan_item' => 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-100',
            'no_expected_plan_item' => 'bg-neutral-200 text-neutral-800 dark:bg-neutral-700 dark:text-neutral-100',
            default => 'bg-neutral-200 text-neutral-800 dark:bg-neutral-700 dark:text-neutral-100',
        };
    }

    public function with(SubscriptionPaymentLinkService $service): array
    {
        $subscription = MealSubscription::query()
            ->with(['customer', 'sourcePayment'])
            ->findOrFail($this->subscription->id);

        $audit = $service->auditInvoicesForSubscription($subscription);
        $invoiceRows = collect($audit['invoices']);

        return [
            'subscription' => $subscription,
            'audit' => $audit,
            'invoiceRows' => $invoiceRows,
            'visibleRows' => $this->show_only_issues
                ? $invoiceRows->filter(fn (array $row) => $row['problem'] !== null)->values()
                : $invoiceRows,
        ];
    }
}; ?>

<div class="w-full max-w-7xl mx-auto px-4 space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Subscription Invoice Audit') }}</p>
            <h1 class="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ $subscription->subscription_code }}</h1>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">
                {{ $subscription->customer->name ?? '—' }}
                @if ($subscription->sourcePayment)
                    · {{ __('Payment') }} {{ $subscription->sourcePayment->reference ?: '#'.$subscription->sourcePayment->id }}
                @endif
            </p>
        </div>
        <div class="flex gap-2">
            <flux:button :href="route('subscriptions.show', $subscription)" wire:navigate variant="ghost">{{ __('Back to subscription') }}</flux:button>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ __('Expected plan item') }}</p>
            @if ($audit['expected_menu_item_id'])
                <p class="mt-2 text-lg font-semibold text-neutral-900 dark:text-neutral-100">
                    {{ $audit['expected_menu_item_code'] ?: '—' }}
                </p>
                <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ $audit['expected_menu_item_name'] ?: '—' }}</p>
            @else
                <p class="mt-2 text-sm text-amber-700 dark:text-amber-200">{{ __('No configured plan item for this subscription plan.') }}</p>
            @endif
        </div>
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ __('Invoices linked to payment') }}</p>
            <p class="mt-2 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ $invoiceRows->count() }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Allocated to this subscription payment.') }}</p>
        </div>
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ __('Invoices with issues') }}</p>
            <p class="mt-2 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ $invoiceRows->whereNotNull('problem')->count() }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('These invoices are not counted correctly for completion.') }}</p>
        </div>
    </div>

    @if (! $subscription->sourcePayment)
        <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100">
            {{ __('This subscription is not linked to a payment, so there are no payment-allocated invoices to audit.') }}
        </div>
    @else
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Invoice Issues') }}</h2>
                    <p class="text-sm text-neutral-600 dark:text-neutral-300">
                        {{ __('Invoices allocated to the linked payment are checked for the expected subscription item.') }}
                    </p>
                </div>
                <label class="inline-flex items-center gap-2 text-sm text-neutral-700 dark:text-neutral-200">
                    <input type="checkbox" wire:model.live="show_only_issues" class="rounded border-neutral-300 text-emerald-600 focus:ring-emerald-500">
                    <span>{{ __('Show only issues') }}</span>
                </label>
            </div>

            @if ($visibleRows->isEmpty())
                <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-100">
                    {{ $invoiceRows->isEmpty()
                        ? __('No invoices are allocated to this subscription payment yet.')
                        : __('No invoice item issues found for the current filter.') }}
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-700">
                        <thead class="bg-neutral-50 dark:bg-neutral-800/60">
                            <tr>
                                <th class="px-4 py-3 text-left font-medium text-neutral-600 dark:text-neutral-300">{{ __('Invoice') }}</th>
                                <th class="px-4 py-3 text-left font-medium text-neutral-600 dark:text-neutral-300">{{ __('Issue') }}</th>
                                <th class="px-4 py-3 text-left font-medium text-neutral-600 dark:text-neutral-300">{{ __('Expected item qty') }}</th>
                                <th class="px-4 py-3 text-left font-medium text-neutral-600 dark:text-neutral-300">{{ __('Allocated') }}</th>
                                <th class="px-4 py-3 text-left font-medium text-neutral-600 dark:text-neutral-300">{{ __('Actual items') }}</th>
                                <th class="px-4 py-3 text-left font-medium text-neutral-600 dark:text-neutral-300">{{ __('Action') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                            @foreach ($visibleRows as $row)
                                @php($invoice = $row['invoice'])
                                <tr class="align-top">
                                    <td class="px-4 py-3 text-neutral-900 dark:text-neutral-100">
                                        <div class="font-medium">{{ $invoice->invoice_number ?: '#'.$invoice->id }}</div>
                                        <div class="text-xs text-neutral-500 dark:text-neutral-400">
                                            {{ $invoice->issue_date?->format('Y-m-d') ?? '—' }} · {{ ucfirst($invoice->status) }}
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold {{ $this->problemBadgeClass($row['problem']) }}">
                                            {{ $this->problemLabel($row['problem']) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-neutral-700 dark:text-neutral-200">{{ rtrim(rtrim(number_format((float) $row['expected_item_qty'], 3, '.', ''), '0'), '.') ?: '0' }}</td>
                                    <td class="px-4 py-3 text-neutral-700 dark:text-neutral-200">{{ $this->formatMoney($row['allocated_cents']) }}</td>
                                    <td class="px-4 py-3 text-neutral-700 dark:text-neutral-200">
                                        <div class="space-y-1">
                                            @foreach ($row['actual_items'] as $item)
                                                <div class="rounded-md bg-neutral-50 px-3 py-2 text-xs dark:bg-neutral-800/60">
                                                    <span class="font-medium text-neutral-900 dark:text-neutral-100">{{ $item['description'] }}</span>
                                                    <span class="text-neutral-500 dark:text-neutral-400">
                                                        · {{ __('Qty') }} {{ $item['qty'] }}
                                                        @if ($item['sku'])
                                                            · {{ $item['sku'] }}
                                                        @endif
                                                        @if ($item['is_subscription_meta'])
                                                            · {{ __('Subscription meta') }}
                                                        @endif
                                                    </span>
                                                </div>
                                            @endforeach
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <a href="{{ route('invoices.show', $invoice) }}" wire:navigate class="text-sm font-medium text-emerald-700 underline decoration-transparent transition hover:decoration-current dark:text-emerald-300">
                                            {{ __('Open invoice') }}
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif
</div>
