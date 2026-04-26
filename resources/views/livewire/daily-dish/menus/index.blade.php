<?php

use App\Models\Category;
use App\Models\DailyDishMenu;
use App\Models\MenuItem;
use App\Services\DailyDish\DailyDishMenuService;
use App\Support\DailyDish\DailyDishMenuSlots;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\Auth;

new #[Layout('components.layouts.app')] class extends Component {
    public int $branch_id = 1;
    public string $month;
    public string $year;
    public int $filter_branch_id = 1;
    public string $filter_month;
    public string $filter_year;

    public bool $showCloneModal = false;
    public ?string $clone_from = null;
    public ?string $clone_to = null;
    public ?int $clone_branch_id = null;

    public bool $showMenuDrawer = false;
    public ?string $drawer_service_date = null;
    public ?DailyDishMenu $drawer_menu = null;
    public string $drawer_status = 'draft';
    public array $drawer_items = [];
    public array $drawer_item_search = [];
    public bool $showDrawerMenuItemForm = false;
    public ?int $drawer_menu_item_target_index = null;
    public string $new_menu_item_name = '';
    public ?int $new_menu_item_category_id = null;
    public string $new_menu_item_category_search = '';
    public string $new_menu_item_price = '0.00';

    public function mount(): void
    {
        $today = now();
        $this->month = (string) $today->format('m');
        $this->year = (string) $today->format('Y');
        $this->filter_branch_id = $this->branch_id;
        $this->filter_month = $this->month;
        $this->filter_year = $this->year;
        $this->clone_branch_id = $this->branch_id;
    }

    public function applyFilters(): void
    {
        $data = $this->validate([
            'filter_branch_id' => ['required', 'integer', 'min:1'],
            'filter_month' => ['required', 'regex:/^(0[1-9]|1[0-2]|[1-9])$/'],
            'filter_year' => ['required', 'digits:4'],
        ]);

        $this->branch_id = (int) $data['filter_branch_id'];
        $this->month = str_pad((string) $data['filter_month'], 2, '0', STR_PAD_LEFT);
        $this->year = (string) $data['filter_year'];
    }

    public function publishAll(DailyDishMenuService $service): void
    {
        if (! $this->canManageMenus()) {
            session()->flash('status', __('You do not have permission to publish menus.'));
            return;
        }

        $result = $service->publishAllDraftsForMonth(
            $this->branch_id,
            (int) $this->year,
            (int) $this->month,
            (int) Auth::id()
        );

        if ($this->showMenuDrawer && $this->drawer_service_date) {
            $drawerMonth = now()->parse($this->drawer_service_date)->format('Y-m');
            $currentMonth = sprintf('%04d-%02d', (int) $this->year, (int) $this->month);
            if ($drawerMonth === $currentMonth) {
                $this->loadDrawerMenu();
            }
        }

        if ($result['draft_count'] === 0) {
            session()->flash('status', __('No draft menus found for this month.'));
            return;
        }

        if ($result['published'] === $result['draft_count']) {
            session()->flash('status', __('Published :count menus.', ['count' => $result['published']]));
            return;
        }

        if ($result['published'] === 0) {
            session()->flash(
                'status',
                __('No menus were published. Fix these dates first: :dates', ['dates' => implode(', ', $result['failed_dates'])])
            );
            return;
        }

        session()->flash(
            'status',
            __('Published :published of :total draft menus. These dates still need fixes: :dates', [
                'published' => $result['published'],
                'total' => $result['draft_count'],
                'dates' => implode(', ', $result['failed_dates']),
            ])
        );
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
            'categories' => Schema::hasTable('categories')
                ? Category::query()->orderBy('name')->get()
                : collect(),
            'menuItems' => Schema::hasTable('menu_items')
                ? MenuItem::where('is_active', 1)->availableInBranch($this->branch_id)->orderBy('name')->get()
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
        $this->drawer_items = DailyDishMenuSlots::defaultRows();
        $this->drawer_item_search = array_fill(0, count($this->drawer_items), '');
        $this->resetDrawerMenuItemForm();
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
            $this->drawer_items = DailyDishMenuSlots::normalizeRows($existing->items);
        } else {
            $this->drawer_menu = null;
            $this->drawer_status = 'draft';
            $this->drawer_items = DailyDishMenuSlots::defaultRows();
        }

        $this->drawer_item_search = collect($this->drawer_items)
            ->map(fn ($row) => $this->resolveMenuItemLabel((int) ($row['menu_item_id'] ?? 0)))
            ->toArray();

        $this->resetDrawerMenuItemForm();
    }

    public function selectDrawerMenuItem(int $index, int $menuItemId, string $label = ''): void
    {
        if (! isset($this->drawer_items[$index])) {
            return;
        }

        $this->drawer_items[$index]['menu_item_id'] = $menuItemId;
        $this->drawer_item_search[$index] = trim($label) !== '' ? $label : $this->resolveMenuItemLabel($menuItemId);
    }

    public function clearDrawerItem(int $idx): void
    {
        if (! $this->canManageMenus()) {
            return;
        }
        if ($this->drawer_status !== 'draft') {
            return;
        }

        if (! isset($this->drawer_items[$idx])) {
            return;
        }

        $this->drawer_items[$idx]['menu_item_id'] = null;
        $this->drawer_item_search[$idx] = '';
    }

    public function openDrawerMenuItemForm(?int $targetIndex = null): void
    {
        if (! $this->canManageMenus() || $this->drawer_status !== 'draft') {
            return;
        }

        $this->resetErrorBag([
            'new_menu_item_name',
            'new_menu_item_category_id',
            'new_menu_item_price',
        ]);
        $this->showDrawerMenuItemForm = true;
        $this->drawer_menu_item_target_index = $targetIndex;
        $this->new_menu_item_name = '';
        $this->new_menu_item_category_id = null;
        $this->new_menu_item_category_search = '';
        $this->new_menu_item_price = '0.00';
    }

    public function selectDrawerMenuItemCategory(int $categoryId, string $label = ''): void
    {
        $this->new_menu_item_category_id = $categoryId;
        $this->new_menu_item_category_search = trim($label) !== '' ? $label : $this->resolveCategoryLabel($categoryId);
    }

    public function clearDrawerMenuItemCategory(): void
    {
        $this->new_menu_item_category_id = null;
        $this->new_menu_item_category_search = '';
    }

    public function closeDrawerMenuItemForm(): void
    {
        $this->resetDrawerMenuItemForm();
    }

    public function createDrawerMenuItem(): void
    {
        if (! $this->canManageMenus() || $this->drawer_status !== 'draft') {
            return;
        }

        $categoryRules = Schema::hasTable('categories')
            ? ['nullable', 'integer', 'exists:categories,id']
            : ['nullable', 'integer'];

        $data = $this->validate([
            'new_menu_item_name' => ['required', 'string', 'max:255'],
            'new_menu_item_category_id' => $categoryRules,
            'new_menu_item_price' => ['required', 'numeric', 'min:0'],
        ]);

        $menuItem = MenuItem::create([
            'name' => $data['new_menu_item_name'],
            'arabic_name' => null,
            'category_id' => $data['new_menu_item_category_id'],
            'recipe_id' => null,
            'selling_price_per_unit' => $data['new_menu_item_price'],
            'unit' => MenuItem::UNIT_EACH,
            'tax_rate' => 0,
            'is_active' => true,
            'display_order' => ((int) MenuItem::query()->max('display_order')) + 1,
        ]);

        if (Schema::hasTable('menu_item_branches')) {
            DB::table('menu_item_branches')->updateOrInsert(
                [
                    'menu_item_id' => $menuItem->id,
                    'branch_id' => $this->branch_id,
                ],
                [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        if ($this->drawer_menu_item_target_index !== null && isset($this->drawer_items[$this->drawer_menu_item_target_index])) {
            $this->drawer_items[$this->drawer_menu_item_target_index]['menu_item_id'] = $menuItem->id;
            $this->drawer_item_search[$this->drawer_menu_item_target_index] = trim(($menuItem->code ?? '').' '.$menuItem->name);
        }

        $this->resetDrawerMenuItemForm();
        session()->flash('status', __('Menu item created.'));
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

        try {
            $menu = $this->persistDrawerMenu($service);
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

        try {
            $menu = $this->persistDrawerMenu($service);
            $menu = $service->publish($menu, Auth::id());
            $this->drawer_menu = $menu;
            $this->drawer_status = $menu->status;
            session()->flash('status', __('Menu published.'));
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?? __('Publish failed.');
            session()->flash('status', $message);
        }
    }

    public function publishDrawerMenuAndNextDate(DailyDishMenuService $service): void
    {
        if (! $this->canManageMenus()) {
            session()->flash('status', __('You do not have permission to edit menus.'));
            return;
        }

        try {
            $menu = $this->persistDrawerMenu($service);
            $menu = $service->publish($menu, Auth::id());
            $this->drawer_menu = $menu;
            $this->drawer_status = $menu->status;

            $nextDate = now()->parse($this->drawer_service_date)->addDay();
            $this->month = $nextDate->format('m');
            $this->year = $nextDate->format('Y');
            $this->filter_month = $this->month;
            $this->filter_year = $this->year;
            $this->filter_branch_id = $this->branch_id;
            $this->drawer_service_date = $nextDate->format('Y-m-d');
            $this->loadDrawerMenu();
            $this->showMenuDrawer = true;

            session()->flash('status', __('Menu published. Ready for next date.'));
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
        $branchRule = ['required', 'integer'];
        if (Schema::hasTable('branches')) {
            $branchRule[] = Rule::exists('branches', 'id');
        }

        $data = $this->validate([
            'clone_from' => ['required', 'date'],
            'clone_to' => ['required', 'date'],
            'clone_branch_id' => $branchRule,
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

    private function persistDrawerMenu(DailyDishMenuService $service): DailyDishMenu
    {
        $data = $this->validate([
            'drawer_items' => ['required', 'array', 'size:5'],
            'drawer_items.*.menu_item_id' => ['nullable', 'integer'],
            'drawer_items.*.role' => ['required', 'in:main,salad,dessert'],
            'drawer_items.*.sort_order' => ['required', 'integer'],
            'drawer_items.*.is_required' => ['boolean'],
        ]);

        return $service->upsertMenu(
            $this->branch_id,
            $this->drawer_service_date,
            [
                'items' => DailyDishMenuSlots::selectedItems($data['drawer_items']),
            ],
            Auth::id()
        );
    }

    private function resetDrawerMenuItemForm(): void
    {
        $this->showDrawerMenuItemForm = false;
        $this->drawer_menu_item_target_index = null;
        $this->new_menu_item_name = '';
        $this->new_menu_item_category_id = null;
        $this->new_menu_item_category_search = '';
        $this->new_menu_item_price = '0.00';
    }

    private function resolveCategoryLabel(int $categoryId): string
    {
        if ($categoryId <= 0) {
            return '';
        }

        $category = Category::query()->find($categoryId);

        return $category?->name ?? '';
    }

    private function resolveMenuItemLabel(int $menuItemId): string
    {
        if ($menuItemId <= 0) {
            return '';
        }

        $item = MenuItem::query()->find($menuItemId);
        if (! $item) {
            return '';
        }

        return trim(($item->code ?? '').' '.$item->name);
    }
}; ?>

<div class="w-full max-w-6xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Daily Dish Planner') }}</h1>
        @if(auth()->user()?->hasAnyRole(['admin','manager']))
            <div class="flex gap-2">
                <flux:button type="button" wire:click="publishAll" wire:loading.attr="disabled" wire:target="publishAll" variant="primary">
                    {{ __('Publish All') }}
                </flux:button>
            </div>
        @endif
    </div>

    @if (session('status'))
        <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100">
            {{ session('status') }}
        </div>
    @endif

    <form wire:submit="applyFilters" class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
        <div class="app-filter-grid">
            <div>
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Branch ID') }}</label>
                <flux:input wire:model.defer="filter_branch_id" type="number" min="1" class="w-32" />
            </div>
            <div>
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Month') }}</label>
                <select wire:model.defer="filter_month" class="rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    @for($m=1;$m<=12;$m++)
                        <option value="{{ str_pad($m,2,'0',STR_PAD_LEFT) }}">{{ \Carbon\Carbon::create()->month($m)->format('F') }}</option>
                    @endfor
                </select>
            </div>
            <div>
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Year') }}</label>
                <select wire:model.defer="filter_year" class="rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    @for($y = now()->year - 1; $y <= now()->year + 1; $y++)
                        <option value="{{ $y }}">{{ $y }}</option>
                    @endfor
                </select>
            </div>
            <div>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="applyFilters">
                    {{ __('Submit') }}
                </flux:button>
            </div>
        </div>
    </form>

    <div wire:loading.flex wire:target="applyFilters" class="items-center gap-2 rounded-lg border border-neutral-200 bg-white px-4 py-3 text-sm text-neutral-700 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
        <svg class="h-4 w-4 animate-spin text-neutral-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z"></path>
        </svg>
        <span>{{ __('Loading daily dish planner...') }}</span>
    </div>

    <div wire:loading.remove wire:target="applyFilters" class="grid grid-cols-1 gap-4 md:grid-cols-3">
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
        .dd-menu-drawer__panel { position: absolute; top: 0; right: 0; height: 100%; width: min(78rem, calc(100vw - 1rem)); transform: translateX(100%); transition: transform 250ms ease; overflow-y: auto; background: #fff; box-shadow: -20px 0 60px rgba(0,0,0,.2); border-left: 1px solid rgba(0,0,0,.08); }
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
                    <div class="flex flex-wrap gap-2">
                        @if($drawer_status === 'draft')
                            <flux:button type="button" wire:click="saveDrawerMenu" variant="primary">{{ __('Save') }}</flux:button>
                            <flux:button type="button" wire:click="publishDrawerMenu" variant="primary">{{ __('Publish') }}</flux:button>
                            <flux:button type="button" wire:click="publishDrawerMenuAndNextDate" variant="primary">{{ __('Publish & Next Date') }}</flux:button>
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
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Items') }}</h3>
                        @if($drawer_status === 'draft')
                            <flux:button type="button" wire:click="openDrawerMenuItemForm" variant="ghost">{{ __('Create Menu Item') }}</flux:button>
                        @endif
                    </div>

                    @if($showDrawerMenuItemForm && $drawer_status === 'draft')
                        <div class="rounded-lg border border-neutral-200 bg-neutral-50 p-4 dark:border-neutral-700 dark:bg-neutral-800/60">
                            <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                                <flux:input wire:model="new_menu_item_name" :label="__('Name')" required class="md:col-span-2" />
                                <div>
                                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Category') }}</label>
                                    <div
                                        class="relative mt-1"
                                        wire:ignore
                                        x-data="dailyDishCategoryLookup({
                                            initial: @js($new_menu_item_category_search),
                                            selectedId: @js($new_menu_item_category_id),
                                            options: @js($categories->map(fn ($category) => ['id' => $category->id, 'name' => $category->name])->values()),
                                            selectMethod: 'selectDrawerMenuItemCategory',
                                            clearMethod: 'clearDrawerMenuItemCategory'
                                        })"
                                        x-init="init()"
                                        x-on:keydown.escape.stop="close()"
                                        x-on:click.outside="close()"
                                    >
                                        <input
                                            x-ref="input"
                                            type="text"
                                            class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50"
                                            x-model="query"
                                            x-on:input.debounce.150ms="onInput()"
                                            x-on:focus="onInput(true)"
                                            placeholder="{{ __('Search category') }}"
                                        />
                                        <template x-teleport="body">
                                            <div
                                                x-show="open"
                                                x-ref="panel"
                                                x-bind:style="panelStyle"
                                                class="z-[100000] overflow-hidden rounded-md border border-neutral-200 bg-white shadow-lg dark:border-neutral-700 dark:bg-neutral-900"
                                            >
                                                <div class="max-h-60 overflow-auto">
                                                    <button
                                                        type="button"
                                                        class="w-full px-3 py-2 text-left text-sm text-neutral-500 hover:bg-neutral-50 dark:text-neutral-300 dark:hover:bg-neutral-800/80"
                                                        x-on:click="clearSelection()"
                                                    >
                                                        {{ __('None') }}
                                                    </button>
                                                    <template x-for="item in results" :key="item.id">
                                                        <button
                                                            type="button"
                                                            class="w-full px-3 py-2 text-left text-sm text-neutral-800 hover:bg-neutral-50 dark:text-neutral-100 dark:hover:bg-neutral-800/80"
                                                            x-on:click="choose(item)"
                                                            x-text="item.name"
                                                        ></button>
                                                    </template>
                                                    <div x-show="!results.length" class="px-3 py-2 text-sm text-neutral-500 dark:text-neutral-400">
                                                        {{ __('No categories found.') }}
                                                    </div>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                                <flux:input wire:model="new_menu_item_price" type="number" step="0.001" min="0" :label="__('Price')" />
                            </div>
                            <div class="mt-3 flex justify-end gap-2">
                                <flux:button type="button" wire:click="closeDrawerMenuItemForm" variant="ghost">{{ __('Cancel') }}</flux:button>
                                <flux:button type="button" wire:click="createDrawerMenuItem" variant="primary">{{ __('Create') }}</flux:button>
                            </div>
                            @error('new_menu_item_name') <p class="mt-2 text-xs text-rose-600">{{ $message }}</p> @enderror
                            @error('new_menu_item_category_id') <p class="mt-2 text-xs text-rose-600">{{ $message }}</p> @enderror
                            @error('new_menu_item_price') <p class="mt-2 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[980px] table-fixed divide-y divide-neutral-200 dark:divide-neutral-800">
                            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                                <tr>
                                    <th class="w-32 px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Slot') }}</th>
                                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Menu Item') }}</th>
                                    <th class="w-32 px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Price') }}</th>
                                    <th class="w-52 px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100 whitespace-nowrap">{{ __('Actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                                @forelse ($drawer_items as $index => $row)
                                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                                        <td class="px-3 py-2 text-sm">
                                            <span class="font-medium text-neutral-900 dark:text-neutral-100">{{ $row['slot_label'] }}</span>
                                        </td>
                                        <td class="px-3 py-2 text-sm">
                                            <div
                                                class="relative"
                                                wire:ignore
                                                x-data="dailyDishMenuItemLookup({
                                                    index: {{ $index }},
                                                    initial: @js($drawer_item_search[$index] ?? ''),
                                                    selectedId: @js($row['menu_item_id'] ?? null),
                                                    searchUrl: '{{ route('orders.menu-items.search') }}',
                                                    branchId: @js($branch_id)
                                                })"
                                                x-init="init()"
                                                x-on:keydown.escape.stop="close()"
                                                x-on:click.outside="close()"
                                            >
                                                <input
                                                    x-ref="input"
                                                    type="text"
                                                    class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50"
                                                    x-model="query"
                                                    x-on:input.debounce.200ms="onInput()"
                                                    x-on:focus="onInput(true)"
                                                    placeholder="{{ __('Search item') }}"
                                                    @disabled($drawer_status !== 'draft')
                                                />
                                                <template x-teleport="body">
                                                    <div
                                                        x-show="open"
                                                        x-ref="panel"
                                                        x-bind:style="panelStyle"
                                                        class="z-[100000] overflow-hidden rounded-md border border-neutral-200 bg-white shadow-lg dark:border-neutral-700 dark:bg-neutral-900"
                                                    >
                                                        <div class="max-h-60 overflow-auto">
                                                            <template x-for="item in results" :key="item.id">
                                                                <button
                                                                    type="button"
                                                                    class="w-full px-3 py-2 text-left text-sm text-neutral-800 hover:bg-neutral-50 dark:text-neutral-100 dark:hover:bg-neutral-800/80"
                                                                    x-on:click="choose(item)"
                                                                >
                                                                    <div class="flex items-center justify-between gap-2">
                                                                        <span class="font-medium" x-text="item.name"></span>
                                                                        <span class="text-xs text-neutral-500 dark:text-neutral-400" x-show="item.code" x-text="item.code"></span>
                                                                    </div>
                                                                    <div class="text-xs text-neutral-500 dark:text-neutral-400" x-show="item.price_formatted" x-text="item.price_formatted"></div>
                                                                </button>
                                                            </template>
                                                            <div x-show="loading" class="px-3 py-2 text-sm text-neutral-500 dark:text-neutral-400">
                                                                {{ __('Searching...') }}
                                                            </div>
                                                            <div x-show="!loading && hasSearched && results.length === 0" class="px-3 py-2 text-sm text-neutral-500 dark:text-neutral-400">
                                                                {{ __('No items found.') }}
                                                            </div>
                                                        </div>
                                                    </div>
                                                </template>
                                            </div>
                                        </td>
                                        <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">
                                            @php
                                                $selectedItem = $menuItems->firstWhere('id', $row['menu_item_id']);
                                            @endphp
                                            {{ $selectedItem ? number_format((float) ($selectedItem->selling_price_per_unit ?? 0), 3, '.', '') : '—' }}
                                        </td>
                                        <td class="px-3 py-2 text-sm text-right whitespace-nowrap">
                                            @if($drawer_status === 'draft')
                                                <div class="flex justify-end gap-2">
                                                    <flux:button type="button" wire:click="clearDrawerItem({{ $index }})" variant="ghost">{{ __('Clear') }}</flux:button>
                                                </div>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="px-4 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">
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

<script>
const registerDailyDishMenuItemLookup = () => {
    if (!window.Alpine || window.__dailyDishMenuItemLookupRegistered) {
        return;
    }
    window.__dailyDishMenuItemLookupRegistered = true;

    window.Alpine.data('dailyDishMenuItemLookup', ({ index, initial, selectedId, searchUrl, branchId }) => ({
        index,
        query: initial || '',
        selectedId: selectedId || null,
        selectedLabel: initial || '',
        searchUrl,
        branchId,
        results: [],
        loading: false,
        open: false,
        hasSearched: false,
        panelStyle: 'display: none;',
        controller: null,
        repositionHandler: null,
        init() {
            this.repositionHandler = () => {
                if (this.open) {
                    this.positionDropdown();
                }
            };
            window.addEventListener('resize', this.repositionHandler);
            window.addEventListener('scroll', this.repositionHandler, true);
        },
        onInput(force = false) {
            if (this.selectedId !== null && this.query !== this.selectedLabel) {
                this.selectedId = null;
                this.selectedLabel = '';
                this.$wire.clearDrawerItem(this.index);
            }

            const term = this.query.trim();
            if (!force && term.length < 2) {
                this.open = false;
                this.results = [];
                this.hasSearched = false;
                return;
            }
            if (term.length < 2) {
                this.open = false;
                this.results = [];
                this.hasSearched = false;
                return;
            }

            this.fetchResults(term);
        },
        fetchResults(term) {
            this.loading = true;
            this.hasSearched = true;
            this.open = true;
            this.positionDropdown();
            if (this.controller) {
                this.controller.abort();
            }
            this.controller = new AbortController();
            const params = new URLSearchParams({ q: term });
            if (this.branchId) {
                params.append('branch_id', this.branchId);
            }
            fetch(this.searchUrl + '?' + params.toString(), {
                headers: { 'Accept': 'application/json' },
                signal: this.controller.signal,
                credentials: 'same-origin',
            })
                .then((response) => response.ok ? response.json() : [])
                .then((data) => {
                    this.results = Array.isArray(data) ? data : [];
                    this.loading = false;
                    this.$nextTick(() => this.positionDropdown());
                })
                .catch((error) => {
                    if (error.name === 'AbortError') {
                        return;
                    }
                    this.loading = false;
                    this.results = [];
                });
        },
        choose(item) {
            const label = item.label || item.name || '';
            this.query = label;
            this.selectedLabel = label;
            this.selectedId = item.id;
            this.open = false;
            this.results = [];
            this.loading = false;
            this.$wire.selectDrawerMenuItem(this.index, item.id, label);
        },
        close() {
            this.open = false;
            this.panelStyle = 'display: none;';
        },
        positionDropdown() {
            if (!this.$refs.input) {
                return;
            }

            const rect = this.$refs.input.getBoundingClientRect();
            this.panelStyle = [
                'position: fixed',
                `top: ${rect.bottom + 4}px`,
                `left: ${rect.left}px`,
                `width: ${rect.width}px`,
                'z-index: 100000',
                'display: block',
            ].join('; ');
        },
    }));
};

if (window.Alpine) {
    registerDailyDishMenuItemLookup();
} else {
    document.addEventListener('alpine:init', registerDailyDishMenuItemLookup, { once: true });
}

const registerDailyDishCategoryLookup = () => {
    if (!window.Alpine || window.__dailyDishCategoryLookupRegistered) {
        return;
    }
    window.__dailyDishCategoryLookupRegistered = true;

    window.Alpine.data('dailyDishCategoryLookup', ({ initial, selectedId, options, selectMethod, clearMethod }) => ({
        query: initial || '',
        selectedId: selectedId || null,
        selectedLabel: initial || '',
        options: options || [],
        selectMethod,
        clearMethod,
        results: [],
        open: false,
        panelStyle: 'display: none;',
        repositionHandler: null,
        init() {
            this.results = this.options;
            this.repositionHandler = () => {
                if (this.open) {
                    this.positionDropdown();
                }
            };
            window.addEventListener('resize', this.repositionHandler);
            window.addEventListener('scroll', this.repositionHandler, true);
        },
        onInput(force = false) {
            if (this.selectedId !== null && this.query !== this.selectedLabel) {
                this.selectedId = null;
                this.selectedLabel = '';
                this.$wire[this.clearMethod]();
            }

            const term = this.query.trim().toLowerCase();
            if (!force && term.length < 1) {
                this.results = this.options;
                this.close();
                return;
            }

            this.results = this.options.filter((item) => item.name.toLowerCase().includes(term));
            this.open = true;
            this.$nextTick(() => this.positionDropdown());
        },
        choose(item) {
            this.selectedId = item.id;
            this.selectedLabel = item.name;
            this.query = item.name;
            this.$wire[this.selectMethod](item.id, item.name);
            this.close();
        },
        clearSelection() {
            this.selectedId = null;
            this.selectedLabel = '';
            this.query = '';
            this.results = this.options;
            this.$wire[this.clearMethod]();
            this.close();
        },
        close() {
            this.open = false;
            this.panelStyle = 'display: none;';
        },
        positionDropdown() {
            if (!this.$refs.input) {
                return;
            }

            const rect = this.$refs.input.getBoundingClientRect();
            this.panelStyle = [
                'position: fixed',
                `top: ${rect.bottom + 4}px`,
                `left: ${rect.left}px`,
                `width: ${rect.width}px`,
                'z-index: 100000',
                'display: block',
            ].join('; ');
        },
    }));
};

if (window.Alpine) {
    registerDailyDishCategoryLookup();
} else {
    document.addEventListener('alpine:init', registerDailyDishCategoryLookup, { once: true });
}
</script>
