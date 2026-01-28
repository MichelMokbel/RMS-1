<?php

use App\Services\Orders\SubscriptionOrderGenerationService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public string $service_date;
    public ?int $branch_id = null;
    public bool $dry_run = false;
    public ?array $lastResult = null;

    public function mount(): void
    {
        $this->service_date = now()->toDateString();
    }

    public function generate(SubscriptionOrderGenerationService $service): void
    {
        $branchRule = ['nullable', 'integer', 'min:1'];
        if (Schema::hasTable('branches')) {
            $branchRule[] = Rule::exists('branches', 'id');
        }

        $this->validate([
            'service_date' => ['required', 'date'],
            'branch_id' => $branchRule,
            'dry_run' => ['boolean'],
        ]);

        if ($this->branch_id === null) {
            // Determine distinct branches from subscriptions
            $branches = \App\Models\MealSubscription::query()->distinct()->pluck('branch_id');
        } else {
            $branches = collect([$this->branch_id]);
        }

        $summary = [
            'created' => 0,
            'skipped_existing' => 0,
            'skipped_no_menu' => 0,
            'skipped_no_items' => 0,
            'errors' => [],
        ];

        foreach ($branches as $branch) {
            $res = $service->generateForDate($this->service_date, (int) $branch, Illuminate\Support\Facades\Auth::id(), $this->dry_run);
            $summary['created'] += $res['created_count'];
            $summary['skipped_existing'] += $res['skipped_existing_count'];
            $summary['skipped_no_menu'] += $res['skipped_no_menu_count'];
            $summary['skipped_no_items'] += $res['skipped_no_items_count'];
            $summary['errors'] = array_merge($summary['errors'], $res['errors']);
        }

        $this->lastResult = $summary;
        session()->flash('status', __('Generation completed.'));
    }
}; ?>

<div class="w-full max-w-4xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Generate Subscription Orders') }}</h1>
        <flux:button :href="route('subscriptions.index')" wire:navigate variant="ghost">{{ __('Back') }}</flux:button>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <flux:input wire:model="service_date" type="date" :label="__('Service Date')" />
            <flux:input wire:model="branch_id" type="number" :label="__('Branch ID (optional)')" placeholder="{{ __('All branches') }}" />
            <div class="flex items-center gap-3 pt-6">
                <flux:checkbox wire:model="dry_run" :label="__('Dry Run (no orders)')" />
            </div>
        </div>
        <div class="flex justify-end">
            <flux:button type="button" wire:click="generate" variant="primary">{{ __('Generate') }}</flux:button>
        </div>
    </div>

    @if ($lastResult)
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-2">
            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Results') }}</h2>
            <ul class="text-sm text-neutral-800 dark:text-neutral-200 space-y-1">
                <li>{{ __('Created') }}: {{ $lastResult['created'] }}</li>
                <li>{{ __('Skipped existing') }}: {{ $lastResult['skipped_existing'] }}</li>
                <li>{{ __('Skipped no menu') }}: {{ $lastResult['skipped_no_menu'] }}</li>
                <li>{{ __('Skipped no items') }}: {{ $lastResult['skipped_no_items'] }}</li>
            </ul>
            @if (!empty($lastResult['errors']))
                <div class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100">
                    <ul class="list-disc pl-4">
                        @foreach ($lastResult['errors'] as $err)
                            <li>{{ $err }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    @endif
</div>
