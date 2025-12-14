<?php

use App\Models\MealSubscription;
use App\Services\Subscriptions\MealSubscriptionService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public MealSubscription $subscription;
    public bool $showPauseModal = false;
    public ?string $pause_start = null;
    public ?string $pause_end = null;
    public ?string $pause_reason = null;

    public function with(): array
    {
        $this->subscription->loadMissing(['days', 'pauses', 'customer']);
        return [
            'subscription' => $this->subscription,
        ];
    }

    public function pause(MealSubscriptionService $service): void
    {
        $data = $this->validate([
            'pause_start' => ['required', 'date'],
            'pause_end' => ['required', 'date'],
            'pause_reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $service->pause($this->subscription, [
                'pause_start' => $data['pause_start'],
                'pause_end' => $data['pause_end'],
                'reason' => $data['pause_reason'] ?? null,
            ], auth()->id());

            $this->reset(['pause_start', 'pause_end', 'pause_reason']);
            $this->showPauseModal = false;
            session()->flash('status', __('Subscription paused.'));
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?? __('Pause failed.');
            session()->flash('status', $message);
        }
    }

    public function resume(MealSubscriptionService $service): void
    {
        $service->resume($this->subscription);
        session()->flash('status', __('Subscription resumed.'));
    }

    public function cancel(MealSubscriptionService $service): void
    {
        $service->cancel($this->subscription);
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
        </div>
        <div class="text-sm text-neutral-800 dark:text-neutral-200">
            {{ $subscription->notes ?? __('No notes.') }}
        </div>
        <div class="flex gap-2">
            <flux:button type="button" wire:click="$set('showPauseModal', true)">{{ __('Pause') }}</flux:button>
            <flux:button type="button" wire:click="resume" variant="primary">{{ __('Resume') }}</flux:button>
            <flux:button type="button" wire:click="cancel" variant="ghost">{{ __('Cancel') }}</flux:button>
        </div>
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
    @if ($showPauseModal)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
            <div class="w-full max-w-xl rounded-lg border border-neutral-200 bg-white p-4 shadow-lg dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Pause Subscription') }}</h3>
                    <flux:button type="button" wire:click="$set('showPauseModal', false)" variant="ghost">{{ __('Close') }}</flux:button>
                </div>
                <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                    <flux:input wire:model="pause_start" type="date" :label="__('Pause Start')" />
                    <flux:input wire:model="pause_end" type="date" :label="__('Pause End')" />
                    <flux:input wire:model="pause_reason" :label="__('Reason')" />
                </div>
                <div class="mt-4 flex justify-end gap-2">
                    <flux:button type="button" wire:click="$set('showPauseModal', false)" variant="ghost">{{ __('Cancel') }}</flux:button>
                    <flux:button type="button" wire:click="pause" variant="primary">{{ __('Save Pause') }}</flux:button>
                </div>
            </div>
        </div>
    @endif
</div>

