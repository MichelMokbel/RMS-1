<?php

use App\Models\User;
use App\Services\PastryOrders\PastryDisplayQueryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public int $branch;
    public string $date;
    public ?int $selectedOrderId = null;
    public int $imageIndex = 0;

    public function mount(int $branch, string $date, PastryDisplayQueryService $query): void
    {
        $this->branch = $branch;
        $this->date = $this->normalizeDate($date);
        $orders = $query->ordersForDay($this->actor(), $this->branch, $this->date);
        $this->selectedOrderId = $orders->first()['id'] ?? null;
    }

    public function selectOrder(int $orderId, PastryDisplayQueryService $query): void
    {
        abort_unless($query->orderForDay($this->actor(), $this->branch, $this->date, $orderId), 404);
        $this->selectedOrderId = $orderId;
        $this->imageIndex = 0;
    }

    public function previousDay(): void
    {
        $this->date = Carbon::parse($this->date)->subDay()->toDateString();
        $this->resetSelection();
    }

    public function nextDay(): void
    {
        $this->date = Carbon::parse($this->date)->addDay()->toDateString();
        $this->resetSelection();
    }

    public function today(): void
    {
        $this->date = now()->toDateString();
        $this->resetSelection();
    }

    public function updatedDate(string $date): void
    {
        $this->date = $this->normalizeDate($date);
        $this->resetSelection();
    }

    public function nextImage(int $imageCount): void
    {
        if ($imageCount > 1) {
            $this->imageIndex = ($this->imageIndex + 1) % $imageCount;
        }
    }

    public function previousImage(int $imageCount): void
    {
        if ($imageCount > 1) {
            $this->imageIndex = ($this->imageIndex - 1 + $imageCount) % $imageCount;
        }
    }

    public function selectImage(int $index, int $imageCount): void
    {
        abort_unless($index >= 0 && $index < $imageCount, 422);
        $this->imageIndex = $index;
    }

    public function with(PastryDisplayQueryService $query): array
    {
        $actor = $this->actor();
        $branches = $query->availableBranches($actor);
        $orders = $query->ordersForDay($actor, $this->branch, $this->date);
        $selected = $orders->firstWhere('id', $this->selectedOrderId) ?? $orders->first();

        if ($selected && $this->imageIndex >= count($selected['images'])) {
            $this->imageIndex = 0;
        }

        return [
            'branches' => $branches,
            'orders' => $orders,
            'selected' => $selected,
            'branchName' => (string) ($branches->firstWhere('id', $this->branch)?->name ?? __('Pastry')),
            'refreshedAt' => now()->format('H:i:s'),
        ];
    }

    public function formatQuantity(string|int|float|null $quantity): string
    {
        $formatted = number_format((float) $quantity, 3, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }

    private function resetSelection(): void
    {
        $this->selectedOrderId = null;
        $this->imageIndex = 0;
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

<main class="app-page min-h-[calc(100vh-2rem)] space-y-4" wire:poll.30s aria-labelledby="pastry-display-title">
    <header class="rounded-2xl border border-neutral-200 bg-white px-4 py-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 sm:px-6">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.16em] text-primary-700 dark:text-primary-300">{{ __('Pastry display') }}</p>
                <h1 id="pastry-display-title" class="mt-1 text-3xl font-bold tracking-tight text-neutral-950 dark:text-white">{{ $branchName }}</h1>
                <p class="mt-1 text-base text-neutral-600 dark:text-neutral-300">{{ Carbon::parse($date)->translatedFormat('l, j F Y') }}</p>
            </div>

            <div class="flex flex-wrap items-end gap-2">
                @if ($branches->count() > 1)
                    <div>
                        <label for="pastry-branch" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-neutral-600 dark:text-neutral-300">{{ __('Branch') }}</label>
                        <select id="pastry-branch" wire:model.live="branch" class="min-h-11 rounded-lg border border-neutral-300 bg-white px-3 py-2 text-base text-neutral-900 focus:border-primary-600 focus:ring-2 focus:ring-primary-600 dark:border-neutral-600 dark:bg-neutral-800 dark:text-white">
                            @foreach ($branches as $availableBranch)
                                <option value="{{ $availableBranch->id }}">{{ $availableBranch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div>
                    <label for="pastry-date" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-neutral-600 dark:text-neutral-300">{{ __('Service date') }}</label>
                    <input id="pastry-date" wire:model.live="date" type="date" class="min-h-11 rounded-lg border border-neutral-300 bg-white px-3 py-2 text-base text-neutral-900 focus:border-primary-600 focus:ring-2 focus:ring-primary-600 dark:border-neutral-600 dark:bg-neutral-800 dark:text-white" />
                </div>

                <flux:button type="button" wire:click="previousDay" variant="ghost" class="min-h-11" aria-label="{{ __('Previous day') }}" icon="chevron-left" />
                <flux:button type="button" wire:click="today" variant="ghost" class="min-h-11">{{ __('Today') }}</flux:button>
                <flux:button type="button" wire:click="nextDay" variant="ghost" class="min-h-11" aria-label="{{ __('Next day') }}" icon="chevron-right" />
            </div>
        </div>
    </header>

    <div class="flex items-center justify-between gap-3 text-sm text-neutral-600 dark:text-neutral-300" role="status" aria-live="polite">
        <p>{{ trans_choice(':count pastry order|:count pastry orders', $orders->count(), ['count' => $orders->count()]) }}</p>
        <p>
            <span wire:loading.remove>{{ __('Updated at :time', ['time' => $refreshedAt]) }}</span>
            <span wire:loading>{{ __('Refreshing…') }}</span>
        </p>
    </div>

    @if ($selected)
        <div class="grid min-h-[70vh] gap-4 xl:grid-cols-[18rem_minmax(0,1fr)]">
            <nav aria-label="{{ __('Orders for selected date') }}" class="order-2 overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900 xl:order-1">
                <ul class="flex gap-2 overflow-x-auto p-3 xl:max-h-[78vh] xl:flex-col xl:overflow-y-auto">
                    @foreach ($orders as $order)
                        <li wire:key="pastry-display-order-{{ $order['id'] }}" class="min-w-64 xl:min-w-0">
                            <button type="button" wire:click="selectOrder({{ $order['id'] }})" @class([
                                'min-h-20 w-full rounded-xl border px-4 py-3 text-left transition focus:outline-none focus:ring-2 focus:ring-primary-600 focus:ring-offset-2 dark:focus:ring-offset-neutral-900',
                                'border-primary-700 bg-primary-50 text-primary-950 dark:border-primary-400 dark:bg-primary-950 dark:text-primary-50' => $selected['id'] === $order['id'],
                                'border-neutral-200 bg-white text-neutral-900 hover:border-primary-300 hover:bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-white dark:hover:border-primary-700 dark:hover:bg-neutral-800' => $selected['id'] !== $order['id'],
                            ])>
                                <span class="flex items-center justify-between gap-3">
                                    <span class="font-bold">{{ $order['order_number'] }}</span>
                                    <span class="text-sm tabular-nums">{{ $order['scheduled_time'] ? Carbon::parse($order['scheduled_time'])->format('H:i') : '—' }}</span>
                                </span>
                                <span class="mt-1 block truncate text-sm opacity-80">{{ $order['customer_name'] }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            </nav>

            <article x-data x-ref="workspace" class="order-1 overflow-hidden rounded-2xl border border-neutral-200 bg-neutral-950 text-white shadow-sm xl:order-2">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-white/15 px-4 py-3 sm:px-5">
                    <div>
                        <p class="text-sm uppercase tracking-wide text-neutral-300">{{ __('Order') }}</p>
                        <h2 class="text-2xl font-black tracking-tight">{{ $selected['order_number'] }}</h2>
                    </div>
                    <button type="button" x-on:click="$refs.workspace.requestFullscreen?.()" class="min-h-11 rounded-lg border border-white/25 px-4 py-2 text-sm font-semibold hover:bg-white/10 focus:outline-none focus:ring-2 focus:ring-white">
                        {{ __('Full screen') }}
                    </button>
                </div>

                <div class="grid lg:grid-cols-[minmax(0,1fr)_22rem]">
                    <section aria-label="{{ __('Reference images') }}" class="flex min-h-[48vh] flex-col items-center justify-center bg-black p-4 sm:p-6 lg:min-h-[70vh]">
                        @if (count($selected['images']) > 0 && ($selected['images'][$imageIndex]['url'] ?? null))
                            <img
                                src="{{ $selected['images'][$imageIndex]['url'] }}"
                                alt="{{ __('Reference for order :order, image :current of :total', ['order' => $selected['order_number'], 'current' => $imageIndex + 1, 'total' => count($selected['images'])]) }}"
                                class="h-auto max-h-[64vh] w-auto max-w-full object-contain"
                            />

                            @if (count($selected['images']) > 1)
                                <div class="mt-4 flex items-center justify-center gap-3">
                                    <button type="button" wire:click="previousImage({{ count($selected['images']) }})" class="flex size-11 items-center justify-center rounded-full border border-white/30 hover:bg-white/10 focus:outline-none focus:ring-2 focus:ring-white" aria-label="{{ __('Previous image') }}">
                                        <flux:icon.chevron-left class="size-5" aria-hidden="true" />
                                    </button>
                                    <span class="min-w-16 text-center text-sm tabular-nums text-neutral-300">{{ $imageIndex + 1 }} / {{ count($selected['images']) }}</span>
                                    <button type="button" wire:click="nextImage({{ count($selected['images']) }})" class="flex size-11 items-center justify-center rounded-full border border-white/30 hover:bg-white/10 focus:outline-none focus:ring-2 focus:ring-white" aria-label="{{ __('Next image') }}">
                                        <flux:icon.chevron-right class="size-5" aria-hidden="true" />
                                    </button>
                                </div>
                            @endif
                        @else
                            <div class="max-w-sm text-center">
                                <flux:icon.photo class="mx-auto size-14 text-neutral-500" aria-hidden="true" />
                                <h3 class="mt-4 text-xl font-bold">{{ __('No reference image') }}</h3>
                                <p class="mt-2 text-sm text-neutral-400">{{ __('Use the order details beside the image area.') }}</p>
                            </div>
                        @endif
                    </section>

                    <section aria-label="{{ __('Order details') }}" class="space-y-6 bg-neutral-900 p-5 sm:p-6">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-neutral-400">{{ __('Customer') }}</p>
                            <p class="mt-1 text-2xl font-bold leading-tight">{{ $selected['customer_name'] }}</p>
                        </div>

                        <dl class="grid grid-cols-2 gap-4 text-sm">
                            <div>
                                <dt class="text-neutral-400">{{ __('Date') }}</dt>
                                <dd class="mt-1 font-semibold">{{ Carbon::parse($selected['scheduled_date'])->format('d M Y') }}</dd>
                            </div>
                            <div>
                                <dt class="text-neutral-400">{{ __('Time') }}</dt>
                                <dd class="mt-1 font-semibold">{{ $selected['scheduled_time'] ? Carbon::parse($selected['scheduled_time'])->format('H:i') : '—' }}</dd>
                            </div>
                            <div class="col-span-2">
                                <dt class="text-neutral-400">{{ __('Type') }}</dt>
                                <dd class="mt-1 font-semibold">{{ __($selected['type']) }}</dd>
                            </div>
                            @if ($selected['destination'])
                                <div class="col-span-2">
                                    <dt class="text-neutral-400">{{ __('Destination') }}</dt>
                                    <dd class="mt-1 whitespace-pre-line font-semibold leading-relaxed">{{ $selected['destination'] }}</dd>
                                </div>
                            @endif
                        </dl>

                        <div>
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-neutral-400">{{ __('Items') }}</h3>
                            <ul class="mt-2 divide-y divide-white/10 rounded-xl border border-white/10">
                                @foreach ($selected['items'] as $item)
                                    <li class="grid grid-cols-[auto_minmax(0,1fr)] gap-3 px-3 py-3">
                                        <span class="text-xl font-black tabular-nums text-primary-300">{{ $this->formatQuantity($item['quantity']) }}×</span>
                                        <span class="text-base font-semibold leading-tight">{{ $item['description'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>

                        @if ($selected['notes'])
                            <div class="rounded-xl bg-amber-300 px-4 py-3 text-neutral-950">
                                <p class="text-xs font-bold uppercase tracking-wide">{{ __('Notes') }}</p>
                                <p class="mt-1 whitespace-pre-line text-base font-semibold leading-relaxed">{{ $selected['notes'] }}</p>
                            </div>
                        @endif
                    </section>
                </div>
            </article>
        </div>
    @else
        <section class="flex min-h-[65vh] flex-col items-center justify-center rounded-2xl border border-dashed border-neutral-300 bg-white px-6 text-center dark:border-neutral-700 dark:bg-neutral-900">
            <flux:icon.cake class="size-14 text-primary-600 dark:text-primary-300" aria-hidden="true" />
            <h2 class="mt-4 text-2xl font-bold text-neutral-900 dark:text-white">{{ __('No pastry orders') }}</h2>
            <p class="mt-2 max-w-md text-base text-neutral-600 dark:text-neutral-300">{{ __('There are no active pastry orders scheduled for this date.') }}</p>
        </section>
    @endif
</main>
