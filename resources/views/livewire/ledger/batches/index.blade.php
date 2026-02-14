<?php

use App\Models\GlBatch;
use App\Services\Ledger\GlSummaryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public string $period_start;
    public string $period_end;

    public function mount(): void
    {
        $this->authorizeManager();
        $this->period_start = now()->startOfMonth()->toDateString();
        $this->period_end = now()->endOfMonth()->toDateString();
    }

    public function with(): array
    {
        return [
            'batches' => GlBatch::query()
                ->withCount('lines')
                ->orderByDesc('period_start')
                ->limit(50)
                ->get(),
        ];
    }

    public function generate(GlSummaryService $service): void
    {
        $this->authorizeManager();

        $data = $this->validate([
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
        ]);

        $batch = $service->generateForPeriod(
            Carbon::parse($data['period_start'])->startOfDay(),
            Carbon::parse($data['period_end'])->startOfDay(),
            Auth::id()
        );

        session()->flash('status', __('GL batch generated.'));
        $this->redirectRoute('ledger.batches.show', ['batch' => $batch->id], navigate: true);
    }

    private function authorizeManager(): void
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();

        if (! $user || (! $user->hasRole('admin') && ! $user->hasRole('manager') && ! $user->can('finance.access'))) {
            abort(403);
        }
    }
}; ?>

<div class="w-full max-w-6xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Ledger Batches') }}</h1>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Generate and post GL summaries from subledger.') }}</p>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <flux:input wire:model="period_start" type="date" :label="__('Period start')" />
            <flux:input wire:model="period_end" type="date" :label="__('Period end')" />
            <div class="flex items-end justify-end">
                <flux:button type="button" wire:click="generate" variant="primary">{{ __('Generate') }}</flux:button>
            </div>
        </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Period') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Status') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Generated') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Lines') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                @forelse($batches as $b)
                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">
                            {{ $b->period_start?->format('Y-m-d') }} – {{ $b->period_end?->format('Y-m-d') }}
                        </td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                            <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold bg-neutral-100 text-neutral-800 dark:bg-neutral-800 dark:text-neutral-50">
                                {{ $b->status }}
                            </span>
                        </td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                            {{ $b->generated_at?->format('Y-m-d H:i') ?? '—' }}
                        </td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">
                            {{ (int) $b->lines_count }}
                        </td>
                        <td class="px-3 py-2 text-sm">
                            <flux:button size="xs" :href="route('ledger.batches.show', $b)" wire:navigate>{{ __('View') }}</flux:button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-3 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No batches yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
