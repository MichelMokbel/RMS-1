<?php

use App\Models\MealPlanRequest;
use App\Models\MealSubscription;
use App\Models\Order;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public MealPlanRequest $mealPlanRequest;

    public function with(): array
    {
        $this->mealPlanRequest->loadMissing(['promotion', 'promotionRedemption']);
        $subscription = MealSubscription::query()
            ->where('meal_plan_request_id', $this->mealPlanRequest->id)
            ->orderByDesc('id')
            ->first();

        $orderIds = $this->mealPlanRequest->linkedOrderIds();
        $orders = empty($orderIds)
            ? collect()
            : Order::query()
                ->whereIn('id', $orderIds)
                ->orderByDesc('scheduled_date')
                ->get();

        return [
            'mpr' => $this->mealPlanRequest,
            'subscription' => $subscription,
            'orders' => $orders,
        ];
    }
}; ?>

<div class="w-full max-w-5xl mx-auto px-4 space-y-6">
    <div class="flex items-start justify-between gap-4">
        <div class="space-y-1">
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Meal Plan Request') }} #{{ $mpr->id }}</p>
            <h1 class="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ $mpr->customer_name }}</h1>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ $mpr->customer_phone }} · {{ $mpr->customer_email ?? '—' }}</p>
        </div>
        <div class="flex flex-wrap items-center justify-end gap-2">
            <flux:button :href="route('meal-plan-requests.print', $mpr)" variant="filled">
                {{ __('Print Report') }}
            </flux:button>
            @if($subscription)
                <flux:button :href="route('subscriptions.show', $subscription)" wire:navigate variant="primary">
                    {{ __('View Subscription') }}
                </flux:button>
            @endif
            <flux:button :href="route('meal-plan-requests.index')" wire:navigate variant="ghost">
                {{ __('Back') }}
            </flux:button>
        </div>
    </div>

    @php
        $statusLabel = match ($mpr->status) {
            'new' => __('New'),
            'contacted' => __('Contacted'),
            'converted' => __('Converted'),
            'closed' => __('Closed'),
            default => (string) $mpr->status,
        };
        if ((int) $mpr->plan_meals <= 0 && $mpr->status === 'converted') {
            $statusLabel = __('Accepted');
        }
        $statusClasses = match ($mpr->status) {
            'new' => 'bg-neutral-100 text-neutral-800 dark:bg-neutral-800 dark:text-neutral-100',
            'contacted' => 'bg-blue-50 text-blue-700 dark:bg-blue-950 dark:text-blue-100',
            'converted' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-100',
            'closed' => 'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-100',
            default => 'bg-neutral-100 text-neutral-800 dark:bg-neutral-800 dark:text-neutral-100',
        };
    @endphp

    @if($mpr->submission_kind === 'promo_request')
        @php
            $proposal = is_array($mpr->proposed_selections_snapshot) ? $mpr->proposed_selections_snapshot : [];
            $promotionTerms = is_array($mpr->promotion_terms_snapshot) ? $mpr->promotion_terms_snapshot : [];
            $offer = is_array($promotionTerms['offer'] ?? null) ? $promotionTerms['offer'] : [];
            $notificationDispatch = is_array($mpr->notification_dispatch) ? $mpr->notification_dispatch : [];
            $proposedDays = array_values((array) ($proposal['selections'] ?? []));
            $excludedToday = array_values((array) ($proposal['excluded_today'] ?? []));
        @endphp

        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 shadow-sm dark:border-amber-800 dark:bg-amber-950/30 space-y-4">
            <div>
                <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Promotional Membership Request') }}</h2>
                <p class="mt-1 text-sm text-neutral-700 dark:text-neutral-200">{{ __('No payment, subscription or meal allowance was created automatically.') }}</p>
            </div>

            <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div><dt class="text-neutral-600 dark:text-neutral-300">{{ __('Promotion') }}</dt><dd class="font-semibold text-neutral-900 dark:text-neutral-100">{{ $offer['code'] ?? '—' }}</dd></div>
                <div><dt class="text-neutral-600 dark:text-neutral-300">{{ __('Package price') }}</dt><dd class="font-semibold text-neutral-900 dark:text-neutral-100">QAR {{ number_format(((int) ($mpr->promotionRedemption?->gross_cents ?? 0)) / 100, 2) }}</dd></div>
                <div><dt class="text-neutral-600 dark:text-neutral-300">{{ __('Promotion saving') }}</dt><dd class="font-semibold text-neutral-900 dark:text-neutral-100">QAR {{ number_format(((int) ($mpr->promotionRedemption?->discount_cents ?? 0)) / 100, 2) }}</dd></div>
                <div><dt class="text-neutral-600 dark:text-neutral-300">{{ __('Proposed meals') }}</dt><dd class="font-semibold text-neutral-900 dark:text-neutral-100">{{ (int) ($proposal['main_quantity'] ?? 0) }}</dd></div>
                <div><dt class="text-neutral-600 dark:text-neutral-300">{{ __('Customer confirmation') }}</dt><dd class="font-semibold text-neutral-900 dark:text-neutral-100">{{ data_get($notificationDispatch, 'customer_confirmation.state', 'pending') }}</dd></div>
                <div><dt class="text-neutral-600 dark:text-neutral-300">{{ __('Administrator confirmation') }}</dt><dd class="font-semibold text-neutral-900 dark:text-neutral-100">{{ data_get($notificationDispatch, 'admin_confirmation.state', 'pending') }}</dd></div>
            </dl>

            @if($proposedDays !== [])
                <div class="space-y-2">
                    <h3 class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Proposed future choices') }}</h3>
                    @foreach($proposedDays as $day)
                        <div class="rounded-md border border-amber-200 bg-white px-3 py-2 text-sm dark:border-amber-800 dark:bg-neutral-900">
                            <span class="font-semibold">{{ $day['key'] ?? '—' }}</span>
                            <span class="text-neutral-600 dark:text-neutral-300"> · {{ collect($day['mains'] ?? [])->sum(fn ($main) => (int) ($main['qty'] ?? 0)) }} {{ __('main dish(es)') }}</span>
                        </div>
                    @endforeach
                </div>
            @endif

            @if($excludedToday !== [])
                <p class="text-sm text-amber-800 dark:text-amber-200">{{ __('Same day choices were excluded and were not saved as proposed future meals. Contact the customer if today still needs attention.') }}</p>
            @endif
        </div>
    @endif

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
        <div class="flex flex-wrap items-center gap-3">
            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {{ $statusClasses }}">
                {{ $statusLabel }}
            </span>
            <span class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Requested') }}: {{ $mpr->created_at?->format('Y-m-d H:i') }}</span>
            <span class="text-sm text-neutral-700 dark:text-neutral-200">
                {{ __('Plan') }}:
                {{ $mpr->plan_meals > 0 ? $mpr->plan_meals.' '.__('meals') : __('No plan') }}
            </span>
            <span class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Orders attached') }}: {{ $orders->count() }}</span>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div class="space-y-1">
                <div class="text-xs font-semibold uppercase tracking-wider text-neutral-600 dark:text-neutral-300">{{ __('Delivery Address') }}</div>
                <div class="text-sm text-neutral-900 dark:text-neutral-100 whitespace-pre-wrap">{{ $mpr->delivery_address ?? '—' }}</div>
            </div>
            <div class="space-y-1">
                <div class="text-xs font-semibold uppercase tracking-wider text-neutral-600 dark:text-neutral-300">{{ __('Notes') }}</div>
                <div class="text-sm text-neutral-900 dark:text-neutral-100 whitespace-pre-wrap">{{ $mpr->notes ?? '—' }}</div>
            </div>
        </div>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
        <div class="flex items-center justify-between gap-3">
            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Related Orders') }}</h2>
            <div class="text-sm text-neutral-600 dark:text-neutral-300">{{ $orders->count() }} {{ __('order(s)') }}</div>
        </div>

        @if($orders->isEmpty())
            <div class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('No orders attached to this request.') }}</div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                    <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('ID') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Date') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Status') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Source') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Action') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        @foreach($orders as $o)
                            <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                                <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">#{{ $o->id }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $o->scheduled_date?->format('Y-m-d') ?? '—' }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $o->status }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $o->source }}</td>
                                <td class="px-3 py-2 text-sm text-right">
                                    <flux:button size="sm" variant="ghost" :href="route('orders.edit', $o)" wire:navigate>
                                        {{ __('View') }}
                                    </flux:button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
