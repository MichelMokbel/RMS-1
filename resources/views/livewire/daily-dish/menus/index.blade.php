<?php

use App\Models\DailyDishMenu;
use App\Models\MenuItem;
use App\Services\DailyDish\DailyDishMenuService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public int $branch_id = 1;
    public string $month;
    public string $year;

    public bool $showCloneModal = false;
    public ?string $clone_from = null;
    public ?string $clone_to = null;
    public ?int $clone_branch_id = null;

    public function mount(): void
    {
        $today = now();
        $this->month = (string) $today->format('m');
        $this->year = (string) $today->format('Y');
        $this->clone_branch_id = $this->branch_id;
    }

    public function with(): array
    {
        $menus = DailyDishMenu::query()
            ->withCount('items')
            ->whereYear('service_date', $this->year)
            ->whereMonth('service_date', $this->month)
            ->where('branch_id', $this->branch_id)
            ->orderBy('service_date')
            ->get()
            ->keyBy(fn ($m) => $m->service_date->format('Y-m-d'));

        return [
            'menus' => $menus,
        ];
    }

    public function cloneMenu(DailyDishMenuService $service): void
    {
        $data = $this->validate([
            'clone_from' => ['required', 'date'],
            'clone_to' => ['required', 'date'],
            'clone_branch_id' => ['required', 'integer'],
        ]);

        $from = DailyDishMenu::where('branch_id', $this->branch_id)
            ->whereDate('service_date', $data['clone_from'])
            ->with('items')
            ->first();

        if (! $from) {
            session()->flash('status', __('Source menu not found.'));
            return;
        }

        try {
            $service->cloneMenu($from, $data['clone_to'], $data['clone_branch_id'], auth()->id());
            session()->flash('status', __('Menu cloned.'));
            $this->showCloneModal = false;
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?? __('Clone failed.');
            session()->flash('status', $message);
        }
    }
}; ?>

<div class="w-full max-w-6xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Daily Dish Planner') }}</h1>
        <div class="flex gap-2">
            <flux:button type="button" wire:click="$set('showCloneModal', true)">{{ __('Clone Menu') }}</flux:button>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100">
            {{ session('status') }}
        </div>
    @endif

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Branch ID') }}</label>
                <flux:input wire:model="branch_id" type="number" min="1" class="w-32" />
            </div>
            <div>
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Month') }}</label>
                <select wire:model="month" class="rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    @for($m=1;$m<=12;$m++)
                        <option value="{{ str_pad($m,2,'0',STR_PAD_LEFT) }}">{{ \Carbon\Carbon::create()->month($m)->format('F') }}</option>
                    @endfor
                </select>
            </div>
            <div>
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Year') }}</label>
                <select wire:model="year" class="rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    @for($y = now()->year - 1; $y <= now()->year + 1; $y++)
                        <option value="{{ $y }}">{{ $y }}</option>
                    @endfor
                </select>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
        @php
            $start = \Carbon\Carbon::create($year, $month, 1);
            $daysInMonth = (int) $start->daysInMonth;
        @endphp
        @for($day=1; $day <= $daysInMonth; $day++)
            @php
                $date = \Carbon\Carbon::create($year, $month, $day)->format('Y-m-d');
                $menu = $menus[$date] ?? null;
                $status = $menu?->status ?? 'none';
            @endphp
            <div class="rounded-lg border border-neutral-200 bg-white p-3 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-2">
                <div class="flex items-center justify-between">
                    <p class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ $date }}</p>
                    @if($menu)
                        <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold
                            {{ $menu->isDraft() ? 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-100' : ($menu->isPublished() ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-100' : 'bg-neutral-200 text-neutral-800 dark:bg-neutral-700 dark:text-neutral-100') }}">
                            {{ ucfirst($menu->status) }}
                        </span>
                    @else
                        <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                            {{ __('None') }}
                        </span>
                    @endif
                </div>
                <div class="text-sm text-neutral-700 dark:text-neutral-200">
                    {{ __('Items') }}: {{ $menu?->items_count ?? 0 }}
                </div>
                <div class="flex flex-wrap gap-2">
                    <flux:button size="sm" :href="route('daily-dish.menus.edit', [$branch_id, $date])" wire:navigate>
                        {{ $menu ? __('Edit') : __('Create') }}
                    </flux:button>
                </div>
            </div>
        @endfor
    </div>

    {{-- Clone modal --}}
    @if ($showCloneModal)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
            <div class="w-full max-w-xl rounded-lg border border-neutral-200 bg-white p-4 shadow-lg dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Clone Menu') }}</h3>
                    <flux:button type="button" wire:click="$set('showCloneModal', false)" variant="ghost">{{ __('Close') }}</flux:button>
                </div>

                <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                    <flux:input wire:model="clone_from" type="date" :label="__('From Date')" />
                    <flux:input wire:model="clone_to" type="date" :label="__('To Date')" />
                    <flux:input wire:model="clone_branch_id" type="number" min="1" :label="__('Target Branch ID')" />
                </div>

                <div class="mt-4 flex justify-end gap-2">
                    <flux:button type="button" wire:click="$set('showCloneModal', false)" variant="ghost">{{ __('Cancel') }}</flux:button>
                    <flux:button type="button" wire:click="cloneMenu" variant="primary">{{ __('Clone') }}</flux:button>
                </div>
            </div>
        </div>
    @endif
</div>

