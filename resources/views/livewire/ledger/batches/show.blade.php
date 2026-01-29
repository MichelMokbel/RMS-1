<?php

use App\Models\GlBatch;
use App\Services\Ledger\GlBatchPostingService;
use App\Services\Ledger\GlSummaryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public GlBatch $batch;
    public bool $close_period = true;

    public function mount(GlBatch $batch): void
    {
        $this->authorizeManager();
        $this->batch = $batch->load(['lines.account']);
    }

    public function regenerate(GlSummaryService $service): void
    {
        $this->authorizeManager();

        if ($this->batch->status !== 'open') {
            return;
        }

        $batch = $service->generateForPeriod(
            Carbon::parse($this->batch->period_start)->startOfDay(),
            Carbon::parse($this->batch->period_end)->startOfDay(),
            Auth::id()
        );

        $this->batch = $batch->load(['lines.account']);
        session()->flash('status', __('GL batch regenerated.'));
    }

    public function post(GlBatchPostingService $service): void
    {
        $this->authorizeManager();

        $this->batch = $service->post($this->batch, Auth::id(), $this->close_period)->load(['lines.account']);
        session()->flash('status', $this->close_period ? __('Batch posted and period closed.') : __('Batch posted.'));
    }

    private function authorizeManager(): void
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();

        if (! $user || (! $user->hasRole('admin') && ! $user->hasRole('manager'))) {
            abort(403);
        }
    }
}; ?>

<div class="w-full max-w-6xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('GL Batch') }} #{{ $batch->id }}</h1>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">
                {{ $batch->period_start?->format('Y-m-d') }} – {{ $batch->period_end?->format('Y-m-d') }}
            </p>
        </div>
        <div class="flex items-center gap-2">
            <flux:button :href="route('ledger.batches.index')" wire:navigate variant="ghost">{{ __('Back') }}</flux:button>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="text-sm text-neutral-700 dark:text-neutral-200">
                <span class="font-semibold">{{ __('Status') }}:</span>
                <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold bg-neutral-100 text-neutral-800 dark:bg-neutral-800 dark:text-neutral-50">
                    {{ $batch->status }}
                </span>
                <span class="ml-3">
                    <span class="font-semibold">{{ __('Generated') }}:</span> {{ $batch->generated_at?->format('Y-m-d H:i') ?? '—' }}
                </span>
                <span class="ml-3">
                    <span class="font-semibold">{{ __('Posted') }}:</span> {{ $batch->posted_at?->format('Y-m-d H:i') ?? '—' }}
                </span>
            </div>

            @if($batch->status === 'open')
                <div class="flex flex-wrap items-center gap-3">
                    <label class="flex items-center gap-2 text-sm text-neutral-700 dark:text-neutral-200">
                        <input type="checkbox" wire:model="close_period" class="rounded border-neutral-300 dark:border-neutral-700" />
                        {{ __('Close period (set lock date to period end)') }}
                    </label>
                    <flux:button type="button" wire:click="regenerate" variant="ghost">{{ __('Regenerate') }}</flux:button>
                    <flux:button type="button" wire:click="post" variant="primary">{{ __('Post Batch') }}</flux:button>
                </div>
            @endif
        </div>
    </div>

    @php
        $debits = round((float) $batch->lines->sum('debit_total'), 4);
        $credits = round((float) $batch->lines->sum('credit_total'), 4);
    @endphp

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Lines') }}</h3>
            <div class="text-sm text-neutral-700 dark:text-neutral-200">
                <span class="font-semibold">{{ __('Total Debits') }}:</span> {{ number_format($debits, 4) }}
                <span class="ml-3 font-semibold">{{ __('Total Credits') }}:</span> {{ number_format($credits, 4) }}
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
            <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                    <tr>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Account') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Branch') }}</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Debit') }}</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Credit') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    @forelse($batch->lines as $line)
                        <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                            <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">
                                {{ $line->account?->code ?? $line->account_id }} — {{ $line->account?->name ?? '' }}
                            </td>
                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                                {{ (int) $line->branch_id > 0 ? (int) $line->branch_id : '—' }}
                            </td>
                            <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">
                                {{ number_format((float)$line->debit_total, 4) }}
                            </td>
                            <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">
                                {{ number_format((float)$line->credit_total, 4) }}
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-3 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No lines.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
