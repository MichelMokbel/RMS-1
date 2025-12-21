<?php

use App\Models\DailyDishMenu;
use App\Models\MenuItem;
use App\Services\DailyDish\DailyDishMenuService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\Auth;

new #[Layout('components.layouts.app')] class extends Component {
    public int $branch_id = 1;
    public string $month;
    public string $year;

    public bool $showCloneModal = false;
    public ?string $clone_from = null;
    public ?string $clone_to = null;
    public ?int $clone_branch_id = null;

    public bool $showMenuDrawer = false;
    public ?string $drawer_service_date = null;
    public ?DailyDishMenu $drawer_menu = null;
    public string $drawer_status = 'draft';
    public ?string $drawer_notes = null;
    public array $drawer_items = [];

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
            'menuItems' => Schema::hasTable('menu_items')
                ? MenuItem::where('is_active', 1)->orderBy('name')->get()
                : collect(),
        ];
    }

    private function canManageMenus(): bool
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();
        return $user?->hasAnyRole(['admin', 'manager']) ?? false;
    }

    public function openMenuDrawer(string $serviceDate): void
    {
        if (! $this->canManageMenus()) {
            session()->flash('status', __('You do not have permission to edit menus.'));
            return;
        }

        $this->drawer_service_date = $serviceDate;
        $this->loadDrawerMenu();
        $this->showMenuDrawer = true;
    }

    public function closeMenuDrawer(): void
    {
        $this->showMenuDrawer = false;
        $this->drawer_service_date = null;
        $this->drawer_menu = null;
        $this->drawer_status = 'draft';
        $this->drawer_notes = null;
        $this->drawer_items = [];
        $this->resetValidation();
    }

    private function loadDrawerMenu(): void
    {
        if (! $this->drawer_service_date) {
            return;
        }

        $existing = DailyDishMenu::where('branch_id', $this->branch_id)
            ->whereDate('service_date', $this->drawer_service_date)
            ->with('items')
            ->first();

        if ($existing) {
            $this->drawer_menu = $existing;
            $this->drawer_status = $existing->status;
            $this->drawer_notes = $existing->notes;
            $this->drawer_items = $existing->items->map(fn ($item) => [
                'menu_item_id' => $item->menu_item_id,
                'role' => $item->role,
                'sort_order' => $item->sort_order,
                'is_required' => (bool) $item->is_required,
            ])->values()->toArray();
        } else {
            $this->drawer_menu = null;
            $this->drawer_status = 'draft';
            $this->drawer_notes = null;
            $this->drawer_items = [];
        }
    }

    public function addDrawerItem(): void
    {
        if (! $this->canManageMenus()) {
            return;
        }
        if ($this->drawer_status !== 'draft') {
            return;
        }

        $this->drawer_items[] = [
            'menu_item_id' => null,
            'role' => 'main',
            'sort_order' => 0,
            'is_required' => false,
        ];
    }

    public function removeDrawerItem(int $idx): void
    {
        if (! $this->canManageMenus()) {
            return;
        }
        if ($this->drawer_status !== 'draft') {
            return;
        }

        unset($this->drawer_items[$idx]);
        $this->drawer_items = array_values($this->drawer_items);
    }

    public function saveDrawerMenu(DailyDishMenuService $service): void
    {
        if (! $this->canManageMenus()) {
            session()->flash('status', __('You do not have permission to edit menus.'));
            return;
        }
        if ($this->drawer_status !== 'draft') {
            session()->flash('status', __('Menu is not editable.'));
            return;
        }
        if (! $this->drawer_service_date) {
            session()->flash('status', __('Missing service date.'));
            return;
        }

        $data = $this->validate([
            'drawer_notes' => ['nullable', 'string'],
            'drawer_items' => ['required', 'array', 'min:1'],
            'drawer_items.*.menu_item_id' => ['required', 'integer'],
            'drawer_items.*.role' => ['required', 'in:main,diet,vegetarian,salad,dessert,addon'],
            'drawer_items.*.sort_order' => ['nullable', 'integer'],
            'drawer_items.*.is_required' => ['boolean'],
        ]);

        try {
            $menu = $service->upsertMenu(
                $this->branch_id,
                $this->drawer_service_date,
                [
                    'notes' => $data['drawer_notes'] ?? null,
                    'items' => $data['drawer_items'],
                ],
                Auth::id()
            );

            $this->drawer_menu = $menu;
            $this->drawer_status = $menu->status;
            session()->flash('status', __('Menu saved.'));
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?? __('Save failed.');
            session()->flash('status', $message);
        }
    }

    public function publishDrawerMenu(DailyDishMenuService $service): void
    {
        if (! $this->canManageMenus()) {
            session()->flash('status', __('You do not have permission to edit menus.'));
            return;
        }
        if (! $this->drawer_menu) {
            session()->flash('status', __('Create menu first.'));
            return;
        }

        try {
            $menu = $service->publish($this->drawer_menu, Auth::id());
            $this->drawer_menu = $menu;
            $this->drawer_status = $menu->status;
            session()->flash('status', __('Menu published.'));
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?? __('Publish failed.');
            session()->flash('status', $message);
        }
    }

    public function unpublishDrawerMenu(DailyDishMenuService $service): void
    {
        if (! $this->canManageMenus()) {
            session()->flash('status', __('You do not have permission to edit menus.'));
            return;
        }
        if (! $this->drawer_menu) {
            session()->flash('status', __('Create menu first.'));
            return;
        }

        try {
            $menu = $service->unpublish($this->drawer_menu, Auth::id());
            $this->drawer_menu = $menu;
            $this->drawer_status = $menu->status;
            session()->flash('status', __('Menu reverted to draft.'));
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?? __('Unpublish failed.');
            session()->flash('status', $message);
        }
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
            $service->cloneMenu($from, $data['clone_to'], $data['clone_branch_id'], Auth::id());
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
        <!-- <div class="flex gap-2">
            <flux:button type="button" wire:click="$set('showCloneModal', true)">{{ __('Clone Menu') }}</flux:button>
        </div> -->
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
                    @if(auth()->user()?->hasAnyRole(['admin','manager']))
                        <flux:button size="sm" type="button" wire:click="openMenuDrawer('{{ $date }}')">
                            {{ $menu ? __('Edit') : __('Create') }}
                        </flux:button>
                        <flux:button size="sm" variant="ghost" :href="route('daily-dish.menus.edit', [$branch_id, $date])" wire:navigate>
                            {{ __('Full Page') }}
                        </flux:button>
                    @endif
                </div>
            </div>
        @endfor
    </div>

    {{-- Menu drawer (right slide-over) --}}
    <style>
        /* Daily Dish Menu Planner - Right Drawer (self-contained; does not rely on Tailwind utilities existing) */
        .dd-menu-drawer { position: fixed; inset: 0; z-index: 99999; pointer-events: none; }
        .dd-menu-drawer[data-open="1"] { pointer-events: auto; }
        .dd-menu-drawer__backdrop { position: absolute; inset: 0; background: rgba(0,0,0,.45); opacity: 0; transition: opacity 200ms ease; }
        .dd-menu-drawer[data-open="1"] .dd-menu-drawer__backdrop { opacity: 1; }
        .dd-menu-drawer__panel { position: absolute; top: 0; right: 0; height: 100%; width: min(42rem, 100%); transform: translateX(100%); transition: transform 250ms ease; overflow-y: auto; background: #fff; box-shadow: -20px 0 60px rgba(0,0,0,.2); border-left: 1px solid rgba(0,0,0,.08); }
        .dd-menu-drawer[data-open="1"] .dd-menu-drawer__panel { transform: translateX(0); }
        .dark .dd-menu-drawer__panel { background: rgb(23 23 23); border-left-color: rgba(255,255,255,.12); }
    </style>

    <div class="dd-menu-drawer" data-open="{{ $showMenuDrawer ? '1' : '0' }}" role="dialog" aria-modal="true" aria-hidden="{{ $showMenuDrawer ? 'false' : 'true' }}">
        <div class="dd-menu-drawer__backdrop" wire:click="closeMenuDrawer"></div>

        <div class="dd-menu-drawer__panel">
            <div class="sticky top-0 z-10 border-b border-neutral-200 bg-white/90 px-4 py-3 backdrop-blur dark:border-neutral-700 dark:bg-neutral-900/90">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-xs text-neutral-600 dark:text-neutral-300">{{ __('Daily Dish Menu') }}</p>
                        <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">
                            {{ __('Branch') }} {{ $branch_id }} · {{ $drawer_service_date }}
                        </h2>
                        <div class="mt-1">
                            <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold
                                {{ $drawer_status === 'draft' ? 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-100' : ($drawer_status === 'published' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-100' : 'bg-neutral-200 text-neutral-800 dark:bg-neutral-700 dark:text-neutral-100') }}">
                                {{ ucfirst($drawer_status) }}
                            </span>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        @if($drawer_service_date)
                            <flux:button size="sm" variant="ghost" :href="route('daily-dish.menus.edit', [$branch_id, $drawer_service_date])" wire:navigate>
                                {{ __('Open') }}
                            </flux:button>
                        @endif
                        <flux:button size="sm" type="button" variant="ghost" wire:click="closeMenuDrawer">{{ __('Close') }}</flux:button>
                    </div>
                </div>
            </div>

            <div class="p-4 space-y-4">
                <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
                    <flux:textarea wire:model="drawer_notes" :label="__('Notes')" rows="3" :disabled="$drawer_status !== 'draft'" />

                    <div class="flex flex-wrap gap-2">
                        @if($drawer_status === 'draft')
                            <flux:button type="button" wire:click="addDrawerItem">{{ __('Add Item') }}</flux:button>
                            <flux:button type="button" wire:click="saveDrawerMenu" variant="primary">{{ __('Save') }}</flux:button>
                            <flux:button type="button" wire:click="publishDrawerMenu" variant="primary">{{ __('Publish') }}</flux:button>
                        @elseif($drawer_status === 'published')
                            <flux:button type="button" wire:click="unpublishDrawerMenu">{{ __('Unpublish') }}</flux:button>
                        @endif
                    </div>

                    @if($errors->any())
                        <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-100">
                            {{ $errors->first() }}
                        </div>
                    @endif
                </div>

                <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
                    <h3 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Items') }}</h3>

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                                <tr>
                                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Menu Item') }}</th>
                                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Role') }}</th>
                                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Sort') }}</th>
                                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Required') }}</th>
                                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                                @forelse ($drawer_items as $index => $row)
                                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                                        <td class="px-3 py-2 text-sm">
                                            <select wire:model="drawer_items.{{ $index }}.menu_item_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" @disabled($drawer_status !== 'draft')>
                                                <option value="">{{ __('Select item') }}</option>
                                                @foreach($menuItems as $mi)
                                                    <option value="{{ $mi->id }}">{{ $mi->name }} ({{ $mi->price ?? '' }})</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td class="px-3 py-2 text-sm">
                                            <select wire:model="drawer_items.{{ $index }}.role" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" @disabled($drawer_status !== 'draft')>
                                                <option value="main">{{ __('Main') }}</option>
                                                <option value="diet">{{ __('Diet') }}</option>
                                                <option value="vegetarian">{{ __('Vegetarian') }}</option>
                                                <option value="salad">{{ __('Salad') }}</option>
                                                <option value="dessert">{{ __('Dessert') }}</option>
                                                <option value="addon">{{ __('Addon') }}</option>
                                            </select>
                                        </td>
                                        <td class="px-3 py-2 text-sm">
                                            <flux:input wire:model="drawer_items.{{ $index }}.sort_order" type="number" class="w-24" :disabled="$drawer_status !== 'draft'" />
                                        </td>
                                        <td class="px-3 py-2 text-sm">
                                            <flux:checkbox wire:model="drawer_items.{{ $index }}.is_required" :label="__('Required')" :disabled="$drawer_status !== 'draft'" />
                                        </td>
                                        <td class="px-3 py-2 text-sm text-right">
                                            @if($drawer_status === 'draft')
                                                <flux:button type="button" wire:click="removeDrawerItem({{ $index }})" variant="ghost">{{ __('Remove') }}</flux:button>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="px-4 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">
                                            {{ __('No items yet.') }}
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Clone modal --}}
    @if ($showCloneModal)
        <div class="fixed inset-0 z-[70] flex items-center justify-center bg-black/50 p-4 overflow-y-auto">
            <div class="w-full max-w-xl max-h-[calc(100dvh-2rem)] overflow-y-auto rounded-lg border border-neutral-200 bg-white p-4 shadow-lg dark:border-neutral-700 dark:bg-neutral-900" role="dialog" aria-modal="true" aria-labelledby="clone-menu-title">
                <div class="flex items-center justify-between mb-3">
                    <h3 id="clone-menu-title" class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Clone Menu') }}</h3>
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

