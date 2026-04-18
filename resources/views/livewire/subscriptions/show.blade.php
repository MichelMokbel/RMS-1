<?php

use App\Models\MealSubscription;
use App\Models\MealSubscriptionOrder;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Orders\OrderWorkflowService;
use App\Services\Subscriptions\MealSubscriptionService;
use App\Services\Subscriptions\SubscriptionPaymentLinkService;
use App\Support\Money\MinorUnits;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public MealSubscription $subscription;
    public bool $showPauseModal = false;
    public bool $showResumeModal = false;
    public bool $showCancelModal = false;
    public ?string $pause_start = null;
    public ?string $pause_end = null;
    public ?string $pause_reason = null;
    public bool $pause_cancel_generated_orders = false;
    public ?int $link_payment_id = null;

    public function formatMoney(?int $cents): string
    {
        return MinorUnits::format((int) ($cents ?? 0));
    }

    public function with(): array
    {
        $this->subscription->loadMissing(['days', 'pauses', 'customer', 'sourcePayment']);
        return [
            'subscription'    => $this->subscription,
            'sourcePayment'   => $this->subscription->sourcePayment,
            'linkablePayments' => Payment::where('customer_id', $this->subscription->customer_id)
                ->whereNull('voided_at')
                ->whereDoesntHave('mealSubscriptions')
                ->orderByDesc('received_at')
                ->limit(20)
                ->get(),
        ];
    }

    public function linkPayment(SubscriptionPaymentLinkService $service): void
    {
        $this->resetErrorBag();

        $userId = Auth::id();
        if (! $userId) {
            abort(403);
        }

        $this->validate(['link_payment_id' => ['required', 'integer']]);

        try {
            $payment = Payment::findOrFail((int) $this->link_payment_id);
            $service->linkPaymentToSubscription($payment, $this->subscription, $userId);
            $this->link_payment_id = null;
            $this->subscription = $this->subscription->fresh(['days', 'pauses', 'customer', 'sourcePayment']);
            session()->flash('status', __('Payment linked.'));
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError('link_payment', $message);
                }
            }
        }
    }

    public function resyncMeals(SubscriptionPaymentLinkService $service): void
    {
        if (! Auth::user()?->hasRole('admin')) {
            abort(403);
        }

        // Enable invoice tracking if not already active, so future invoices are counted in real time.
        if (! $this->subscription->uses_invoice_tracking) {
            $this->subscription->uses_invoice_tracking = true;
            $this->subscription->save();
        }

        $count = $service->resyncMealsUsed($this->subscription);
        $this->subscription = $this->subscription->fresh(['days', 'pauses', 'customer', 'sourcePayment']);
        session()->flash('status', __('Resynced. Meals used: :n', ['n' => $count]));
    }

    public function pause(MealSubscriptionService $service, OrderWorkflowService $workflow): void
    {
        $data = $this->validate([
            'pause_start' => ['required', 'date'],
            'pause_end' => ['required', 'date'],
            'pause_reason' => ['nullable', 'string', 'max:255'],
            'pause_cancel_generated_orders' => ['boolean'],
        ]);

        try {
            $actorId = Illuminate\Support\Facades\Auth::id();
            if (! $actorId) {
                throw ValidationException::withMessages(['auth' => __('Authentication required.')]);
            }

            $this->subscription = $service->pause($this->subscription, [
                'pause_start' => $data['pause_start'],
                'pause_end' => $data['pause_end'],
                'reason' => $data['pause_reason'] ?? null,
            ], $actorId);

            $cancelledCount = 0;
            $skippedCount = 0;

            if (! empty($data['pause_cancel_generated_orders'])) {
                $start = Carbon::parse($data['pause_start'])->toDateString();
                $end = Carbon::parse($data['pause_end'])->toDateString();

                $orderIds = MealSubscriptionOrder::query()
                    ->where('subscription_id', $this->subscription->id)
                    ->whereDate('service_date', '>=', $start)
                    ->whereDate('service_date', '<=', $end)
                    ->pluck('order_id')
                    ->unique()
                    ->values()
                    ->all();

                if (! empty($orderIds)) {
                    /** @var \Illuminate\Support\Collection<int, Order> $orders */
                    $orders = Order::query()->whereIn('id', $orderIds)->get();

                    foreach ($orders as $o) {
                        try {
                            $workflow->advanceOrder($o, 'Cancelled', $actorId);
                            $cancelledCount++;
                        } catch (\Throwable $e) {
                            $skippedCount++;
                        }
                    }

                    // Restore quota for cancelled generated days.
                    // Only applies to generation-time tracking; invoice-tracking subscriptions are decremented when the invoice is voided.
                    if ($cancelledCount > 0 && $this->subscription->plan_meals_total !== null && ! $this->subscription->uses_invoice_tracking) {
                        DB::transaction(function () use ($cancelledCount) {
                            $sub = MealSubscription::query()->lockForUpdate()->find($this->subscription->id);
                            if (! $sub) {
                                return;
                            }
                            $sub->meals_used = max(0, (int) $sub->meals_used - $cancelledCount);
                            if ($sub->status === 'expired' && $sub->plan_meals_total !== null && $sub->meals_used < $sub->plan_meals_total) {
                                // If it was marked expired purely due to quota, revive it.
                                $sub->status = 'active';
                                $sub->end_date = null;
                            }
                            $sub->save();
                            $this->subscription = $sub->fresh(['days', 'pauses', 'customer']);
                        });
                    }
                }
            }

            $this->reset(['pause_start', 'pause_end', 'pause_reason', 'pause_cancel_generated_orders']);
            $this->showPauseModal = false;

            $msg = __('Subscription paused.');
            if ($cancelledCount > 0 || $skippedCount > 0) {
                $msg .= ' ' . __('Cancelled :c order(s). Skipped :s.', ['c' => $cancelledCount, 's' => $skippedCount]);
            }
            session()->flash('status', $msg);
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?? __('Pause failed.');
            session()->flash('status', $message);
        }
    }

    public function resume(MealSubscriptionService $service): void
    {
        $service->resume($this->subscription);
        $this->showResumeModal = false;
        session()->flash('status', __('Subscription resumed.'));
    }

    public function cancel(MealSubscriptionService $service): void
    {
        $service->cancel($this->subscription);
        $this->showCancelModal = false;
        session()->flash('status', __('Subscription cancelled.'));
    }
}; ?>

<div class="w-full max-w-4xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Subscription') }}</p>
            <h1 class="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ $subscription->subscription_code }}</h1>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ $subscription->customer->name ?? '—' }} · {{ $subscription->branch_id }}</p>
        </div>
        <div class="flex gap-2">
            <flux:button :href="route('subscriptions.edit', $subscription)" wire:navigate>{{ __('Edit') }}</flux:button>
            <flux:button :href="route('subscriptions.index')" wire:navigate variant="ghost">{{ __('Back') }}</flux:button>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100">
            {{ session('status') }}
        </div>
    @endif

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-2">
        <div class="flex flex-wrap gap-3 items-center">
            <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold
                {{ $subscription->status === 'active' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-100'
                    : ($subscription->status === 'paused' ? 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-100'
                    : 'bg-neutral-200 text-neutral-800 dark:bg-neutral-700 dark:text-neutral-100') }}">
                {{ ucfirst($subscription->status) }}
            </span>
            <span class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Start') }}: {{ $subscription->start_date?->format('Y-m-d') }}</span>
            <span class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('End') }}: {{ $subscription->end_date?->format('Y-m-d') ?? '—' }}</span>
            <span class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Order Type') }}: {{ $subscription->default_order_type }}</span>
            <span class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Preferred') }}: {{ ucfirst($subscription->preferred_role) }}</span>
            <span class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Salad/Dessert') }}: {{ $subscription->include_salad ? __('Yes') : __('No') }}/{{ $subscription->include_dessert ? __('Yes') : __('No') }}</span>
            @if ($subscription->plan_meals_total !== null)
                <span class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Plan') }}: {{ $subscription->plan_meals_total }} {{ __('meals') }}</span>
                <span class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Meals used') }}: {{ $subscription->meals_used ?? 0 }} / {{ $subscription->plan_meals_total }}</span>
            @else
                <span class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Plan') }}: {{ __('Unlimited') }}</span>
            @endif
        </div>
        <div class="text-sm text-neutral-800 dark:text-neutral-200">
            {{ $subscription->notes ?? __('No notes.') }}
        </div>
        <div class="flex gap-2">
            @if ($subscription->status === 'active')
                <flux:button type="button" wire:click="$set('showPauseModal', true)">{{ __('Pause') }}</flux:button>
                <flux:button type="button" wire:click="$set('showCancelModal', true)" variant="ghost">{{ __('Cancel') }}</flux:button>
            @elseif ($subscription->status === 'paused')
                <flux:button type="button" wire:click="$set('showResumeModal', true)" variant="primary">{{ __('Resume') }}</flux:button>
                <flux:button type="button" wire:click="$set('showCancelModal', true)" variant="ghost">{{ __('Cancel') }}</flux:button>
            @elseif (in_array($subscription->status, ['cancelled', 'expired']))
                <span class="text-sm text-neutral-600 dark:text-neutral-400">{{ __('No actions available') }}</span>
            @endif
        </div>
    </div>

    {{-- Source Payment --}}
    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
        <h3 class="text-sm font-semibold text-neutral-800 dark:text-neutral-200">{{ __('Source Payment') }}</h3>

        @if ($sourcePayment)
            <div class="flex items-center justify-between text-sm">
                <span class="text-neutral-800 dark:text-neutral-100">
                    {{ $this->formatMoney($sourcePayment->amount_cents) }}
                    · {{ $sourcePayment->received_at?->format('Y-m-d') ?? '—' }}
                    · {{ strtoupper($sourcePayment->method ?? '—') }}
                </span>
                <flux:button :href="route('receivables.payments.show', $sourcePayment)" wire:navigate size="sm" variant="ghost">{{ __('View Payment') }}</flux:button>
            </div>
        @else
            <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ __('No payment linked.') }}</p>

            @error('link_payment') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror

            @if($linkablePayments->isNotEmpty())
                <div class="flex items-center gap-2">
                    <select wire:model="link_payment_id" class="flex-1 rounded-md border border-neutral-200 bg-white px-3 py-1.5 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="">{{ __('Select payment to link…') }}</option>
                        @foreach($linkablePayments as $pmt)
                            <option value="{{ $pmt->id }}">
                                #{{ $pmt->id }} · {{ $this->formatMoney($pmt->amount_cents) }} · {{ $pmt->received_at?->format('Y-m-d') }}
                            </option>
                        @endforeach
                    </select>
                    <flux:button wire:click="linkPayment" size="sm">{{ __('Link') }}</flux:button>
                </div>
            @endif
        @endif

        @if(auth()->user()?->hasRole('admin') && $subscription->plan_meals_total !== null)
            <flux:button wire:click="resyncMeals" size="sm" variant="ghost">{{ __('Resync Meals from Invoices') }}</flux:button>
        @endif
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-2">
        <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Schedule (Weekdays)') }}</h2>
        <div class="flex flex-wrap gap-2">
            @php
                $weekdayNames = collect(range(1,7))->map(fn($d)=> \Carbon\Carbon::create()->startOfWeek()->addDays($d-1)->format('D'));
            @endphp
            @foreach ($weekdayNames as $idx => $name)
                @php $num = $idx+1; @endphp
                <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold
                    {{ $subscription->weekdayEnabled($num) ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-100' : 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300' }}">
                    {{ $name }}
                </span>
            @endforeach
        </div>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-2">
        <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Pauses') }}</h2>
        <div class="overflow-x-auto">
            <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                    <tr>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Start') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('End') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Reason') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    @forelse ($subscription->pauses as $pause)
                        <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                            <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $pause->pause_start?->format('Y-m-d') }}</td>
                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $pause->pause_end?->format('Y-m-d') }}</td>
                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $pause->reason ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="px-4 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">
                                {{ __('No pauses recorded.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Pause modal --}}
    <flux:modal
        name="pause-subscription"
        wire:model="showPauseModal"
        focusable
        class="max-w-lg max-h-[calc(100dvh-2rem)] overflow-y-auto"
    >
        <form wire:submit="pause" class="space-y-4">
            <div class="space-y-1">
                <flux:heading size="lg">{{ __('Pause Subscription') }}</flux:heading>
                <flux:subheading>{{ __('Set a pause range. Orders will not be generated during this period.') }}</flux:subheading>
            </div>

            <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                <flux:input wire:model="pause_start" type="date" :label="__('Pause Start')" />
                <flux:input wire:model="pause_end" type="date" :label="__('Pause End')" />
                <flux:input wire:model="pause_reason" :label="__('Reason')" />
            </div>

            <div class="rounded-md border border-neutral-200 p-3 text-sm text-neutral-700 dark:border-neutral-700 dark:text-neutral-200">
                <flux:checkbox
                    wire:model="pause_cancel_generated_orders"
                    :label="__('Cancel already-generated orders within this pause range')"
                />
                <div class="mt-1 text-xs text-neutral-600 dark:text-neutral-300">
                    {{ __('This will cancel linked subscription orders in the selected date range (if any). Delivered/cancelled orders will be skipped.') }}
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save Pause') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Resume confirmation modal --}}
    <flux:modal
        name="resume-subscription"
        wire:model="showResumeModal"
        focusable
        class="max-w-md"
    >
        <div class="space-y-4">
            <div class="space-y-1">
                <flux:heading size="lg">{{ __('Resume Subscription') }}</flux:heading>
                <flux:subheading>{{ __('Are you sure you want to resume this subscription? Orders will be generated again according to the schedule.') }}</flux:subheading>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="button" wire:click="resume" variant="primary">{{ __('Resume') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Cancel confirmation modal --}}
    <flux:modal
        name="cancel-subscription"
        wire:model="showCancelModal"
        focusable
        class="max-w-md"
    >
        <div class="space-y-4">
            <div class="space-y-1">
                <flux:heading size="lg">{{ __('Cancel Subscription') }}</flux:heading>
                <flux:subheading>{{ __('Are you sure you want to cancel this subscription? This action will stop all future order generation. Existing orders will not be affected.') }}</flux:subheading>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="button" wire:click="cancel" variant="danger">{{ __('Confirm Cancel') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
