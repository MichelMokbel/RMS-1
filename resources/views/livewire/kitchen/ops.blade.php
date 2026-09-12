<?php

use App\Models\User;
use App\Services\Orders\KitchenPreparationQueryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public int $branch;
    public string $date;

    public function mount(int $branch, string $date, KitchenPreparationQueryService $query): void
    {
        $this->branch = $branch;
        $this->date = $this->normalizeDate($date);
        $query->assertCanView($this->actor(), $this->branch);
    }

    public function previousDay(): void
    {
        $this->date = Carbon::parse($this->date)->subDay()->toDateString();
    }

    public function nextDay(): void
    {
        $this->date = Carbon::parse($this->date)->addDay()->toDateString();
    }

    public function today(): void
    {
        $this->date = now()->toDateString();
    }

    public function updatedDate(string $date): void
    {
        $this->date = $this->normalizeDate($date);
    }

    public function with(KitchenPreparationQueryService $query): array
    {
        $actor = $this->actor();
        $branches = $query->availableBranches($actor);
        $totals = $query->totalsForDay($actor, $this->branch, $this->date);
        $branchName = (string) ($branches->firstWhere('id', $this->branch)?->name ?? __('Kitchen'));

        return [
            'branches' => $branches,
            'totals' => $totals,
            'branchName' => $branchName,
            'groupedTotals' => $totals->groupBy(fn ($row) => (string) ($row->role ?: __('Other'))),
            'refreshedAt' => now()->format('H:i:s'),
        ];
    }

    public function formatQuantity(string|int|float|null $quantity): string
    {
        $formatted = number_format((float) $quantity, 3, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }

    private function actor(): User
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function normalizeDate(string $date): string
    {
        try {
            $parsed = Carbon::createFromFormat('Y-m-d', $date);
        } catch (\Throwable) {
            abort(404);
        }

        abort_unless($parsed && $parsed->format('Y-m-d') === $date, 404);

        return $date;
    }
}; ?>

<main class="app-page min-h-[calc(100vh-2rem)] space-y-5" wire:poll.15s aria-labelledby="kitchen-display-title">
    <header class="rounded-2xl bg-primary-900 px-5 py-5 text-white shadow-sm sm:px-7">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.16em] text-primary-200">{{ __('Kitchen preparation') }}</p>
                <h1 id="kitchen-display-title" class="mt-1 text-3xl font-bold tracking-tight sm:text-4xl">{{ $branchName }}</h1>
                <p class="mt-2 text-lg text-primary-100">{{ Carbon::parse($date)->translatedFormat('l, j F Y') }}</p>
            </div>

            <div class="flex flex-wrap items-end gap-2">
                @if ($branches->count() > 1)
                    <div>
                        <label for="kitchen-branch" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-primary-100">{{ __('Branch') }}</label>
                        <select id="kitchen-branch" wire:model.live="branch" class="min-h-11 rounded-lg border border-primary-600 bg-primary-800 px-3 py-2 text-base text-white focus:border-white focus:ring-2 focus:ring-white">
                            @foreach ($branches as $availableBranch)
                                <option value="{{ $availableBranch->id }}">{{ $availableBranch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div>
                    <label for="kitchen-date" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-primary-100">{{ __('Service date') }}</label>
                    <input id="kitchen-date" wire:model.live="date" type="date" class="min-h-11 rounded-lg border border-primary-600 bg-primary-800 px-3 py-2 text-base text-white focus:border-white focus:ring-2 focus:ring-white" />
                </div>

                <flux:button type="button" wire:click="previousDay" variant="ghost" class="min-h-11 !text-white hover:!bg-primary-800" aria-label="{{ __('Previous day') }}" icon="chevron-left" />
                <flux:button type="button" wire:click="today" variant="ghost" class="min-h-11 !text-white hover:!bg-primary-800">{{ __('Today') }}</flux:button>
                <flux:button type="button" wire:click="nextDay" variant="ghost" class="min-h-11 !text-white hover:!bg-primary-800" aria-label="{{ __('Next day') }}" icon="chevron-right" />
            </div>
        </div>
    </header>

    <div class="flex items-center justify-between gap-3 text-sm text-neutral-600 dark:text-neutral-300" role="status" aria-live="polite">
        <p>{{ trans_choice(':count preparation item|:count preparation items', $totals->count(), ['count' => $totals->count()]) }}</p>
        <p>
            <span wire:loading.remove>{{ __('Updated at :time', ['time' => $refreshedAt]) }}</span>
            <span wire:loading>{{ __('Refreshing…') }}</span>
        </p>
    </div>

    @forelse ($groupedTotals as $role => $rows)
        <section aria-labelledby="kitchen-group-{{ \Illuminate\Support\Str::slug($role) }}" class="overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="border-b border-neutral-200 bg-neutral-50 px-5 py-3 dark:border-neutral-700 dark:bg-neutral-800">
                <h2 id="kitchen-group-{{ \Illuminate\Support\Str::slug($role) }}" class="text-lg font-bold uppercase tracking-wide text-neutral-800 dark:text-neutral-100">{{ $role }}</h2>
            </div>
            <ul class="divide-y divide-neutral-200 dark:divide-neutral-700">
                @foreach ($rows as $row)
                    <li wire:key="kitchen-total-{{ $row->menu_item_id ?: 'snapshot' }}-{{ md5((string) $row->description_snapshot) }}" class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-5 px-5 py-5 sm:px-7">
                        <span class="text-xl font-semibold leading-tight text-neutral-900 dark:text-white sm:text-2xl">{{ $row->description_snapshot }}</span>
                        <span class="min-w-20 rounded-xl bg-primary-50 px-4 py-2 text-center text-3xl font-black tabular-nums text-primary-900 dark:bg-primary-950 dark:text-primary-100 sm:text-4xl">
                            {{ $this->formatQuantity($row->total_quantity) }}
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    @empty
        <section class="flex min-h-72 flex-col items-center justify-center rounded-2xl border border-dashed border-neutral-300 bg-white px-6 text-center dark:border-neutral-700 dark:bg-neutral-900">
            <flux:icon.check-circle class="size-12 text-emerald-600" aria-hidden="true" />
            <h2 class="mt-4 text-2xl font-bold text-neutral-900 dark:text-white">{{ __('Nothing to prepare') }}</h2>
            <p class="mt-2 max-w-md text-base text-neutral-600 dark:text-neutral-300">{{ __('There are no active orders scheduled for this date.') }}</p>
        </section>
    @endforelse
</main>
