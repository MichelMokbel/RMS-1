<?php

use App\Models\PaymentConsistencyFinding;
use App\Services\Payments\PaymentConsistencyManualService;
use App\Services\Payments\PaymentConsistencyQueryService;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public int $finding_id;
    public string $operation_uuid = '';

    public function mount(PaymentConsistencyFinding $finding, PaymentConsistencyQueryService $queries): void
    {
        $finding = $queries->finding(auth()->user(), (int) $finding->id);
        $this->finding_id = (int) $finding->id;
        $this->operation_uuid = (string) Str::uuid();
    }

    public function recheck(PaymentConsistencyQueryService $queries, PaymentConsistencyManualService $manual): void
    {
        $finding = $queries->finding(auth()->user(), $this->finding_id);
        $manual->recheck($finding, auth()->user(), strtolower($this->operation_uuid));
        $this->operation_uuid = (string) Str::uuid();
        session()->flash('status', __('Consistency recheck queued.'));
    }

    public function with(PaymentConsistencyQueryService $queries): array
    {
        $finding = $queries->finding(auth()->user(), $this->finding_id);

        return [
            'finding' => $finding,
            'sourceUrl' => $queries->sourceUrl(auth()->user(), $finding),
        ];
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ Str::headline($finding->rule_code) }}</h1>
            <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-300">{{ Str::headline($finding->subject_type) }} #{{ $finding->subject_id }} · {{ __('Episode') }} {{ $finding->episode_uuid }}</p>
        </div>
        <div class="flex gap-2">
            @if($sourceUrl)
                <flux:button :href="$sourceUrl" variant="ghost" wire:navigate>{{ __('Open source record') }}</flux:button>
            @endif
            @if(config('payment_consistency.enabled') && $finding->state === 'open' && auth()->user()?->isAdmin() && auth()->user()?->can('payments.consistency.run'))
                <flux:button wire:click="recheck" wire:loading.attr="disabled">{{ __('Recheck') }}</flux:button>
            @endif
            <flux:button :href="route('receivables.payments.consistency.index')" variant="ghost" wire:navigate>{{ __('Back') }}</flux:button>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">{{ session('status') }}</div>
    @endif
    @error('consistency')
        <div class="rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 dark:border-rose-900 dark:bg-rose-950 dark:text-rose-100">{{ $message }}</div>
    @enderror

    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
        <div class="rounded-lg border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900"><p class="text-xs uppercase text-neutral-500">{{ __('State') }}</p><p class="mt-2 font-semibold">{{ __(Str::headline($finding->state)) }}</p></div>
        <div class="rounded-lg border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900"><p class="text-xs uppercase text-neutral-500">{{ __('First seen') }}</p><p class="mt-2 font-semibold">{{ $finding->first_seen_at?->format('Y-m-d H:i') }}</p></div>
        <div class="rounded-lg border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900"><p class="text-xs uppercase text-neutral-500">{{ __('Last seen') }}</p><p class="mt-2 font-semibold">{{ $finding->last_seen_at?->format('Y-m-d H:i') }}</p></div>
        <div class="rounded-lg border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900"><p class="text-xs uppercase text-neutral-500">{{ __('Last run') }}</p><p class="mt-2 font-semibold">#{{ $finding->last_run_id }} · {{ __(Str::headline($finding->lastRun?->state ?? 'unknown')) }}</p></div>
        <div class="rounded-lg border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900"><p class="text-xs uppercase text-neutral-500">{{ __('Administrator alert') }}</p><p class="mt-2 font-semibold">{{ __(Str::headline((string) data_get($finding->alert_dispatch, 'state', 'not required'))) }}</p></div>
    </div>

    <section class="rounded-lg border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-neutral-900">
        <h2 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Detected differences') }}</h2>
        <div class="mt-4 space-y-2">
            @forelse((array) data_get($finding->observed, 'issues', []) as $issue)
                <div class="rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900 dark:border-rose-900 dark:bg-rose-950 dark:text-rose-100">
                    <span class="font-semibold">{{ Str::headline((string) ($issue['code'] ?? 'unknown')) }}</span>
                    <span class="ml-2">{{ Str::headline((string) ($issue['subject_type'] ?? 'record')) }} #{{ (int) ($issue['subject_id'] ?? 0) }}</span>
                </div>
            @empty
                <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('No current difference is stored.') }}</p>
            @endforelse
        </div>
    </section>

    <div class="grid gap-4 xl:grid-cols-2">
        <section class="rounded-lg border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-neutral-900">
            <h2 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Expected') }}</h2>
            <pre class="mt-4 overflow-x-auto whitespace-pre-wrap break-words rounded-md bg-neutral-50 p-4 text-xs text-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">{{ json_encode($finding->expected, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
        </section>
        <section class="rounded-lg border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-neutral-900">
            <h2 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Observed') }}</h2>
            <pre class="mt-4 overflow-x-auto whitespace-pre-wrap break-words rounded-md bg-neutral-50 p-4 text-xs text-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">{{ json_encode($finding->observed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
        </section>
    </div>
</div>
