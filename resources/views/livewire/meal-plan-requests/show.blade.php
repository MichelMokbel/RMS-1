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
        $subscription = MealSubscription::query()
            ->where('meal_plan_request_id', $this->mealPlanRequest->id)
            ->orderByDesc('id')
            ->first();

        $orderIds = is_array($this->mealPlanRequest->order_ids) ? $this->mealPlanRequest->order_ids : [];
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
        $statusClasses = match ($mpr->status) {
            'new' => 'bg-neutral-100 text-neutral-800 dark:bg-neutral-800 dark:text-neutral-100',
            'contacted' => 'bg-blue-50 text-blue-700 dark:bg-blue-950 dark:text-blue-100',
            'converted' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-100',
            'closed' => 'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-100',
            default => 'bg-neutral-100 text-neutral-800 dark:bg-neutral-800 dark:text-neutral-100',
        };
    @endphp

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
        <div class="flex flex-wrap items-center gap-3">
            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {{ $statusClasses }}">
                {{ $statusLabel }}
            </span>
            <span class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Requested') }}: {{ $mpr->created_at?->format('Y-m-d H:i') }}</span>
            <span class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Plan') }}: {{ $mpr->plan_meals }} {{ __('meals') }}</span>
            <span class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Orders attached') }}: {{ is_array($mpr->order_ids) ? count($mpr->order_ids) : 0 }}</span>
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


