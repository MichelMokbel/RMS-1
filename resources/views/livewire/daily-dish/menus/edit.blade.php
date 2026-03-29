<?php

use App\Models\Category;
use App\Models\DailyDishMenu;
use App\Models\DailyDishMenuItem;
use App\Services\DailyDish\DailyDishMenuService;
use App\Services\DailyDish\DailyDishMenuEditQueryService;
use App\Support\DailyDish\DailyDishMenuEditRules;
use App\Support\DailyDish\DailyDishMenuSlots;
use App\Models\MenuItem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public int $branch;
    public string $serviceDate;
    public ?DailyDishMenu $menu = null;
    public string $status = 'draft';
    public array $items = [];
    public array $item_search = [];
    public bool $showMenuItemForm = false;
    public ?int $menu_item_target_index = null;
    public string $new_menu_item_name = '';
    public ?int $new_menu_item_category_id = null;
    public string $new_menu_item_category_search = '';
    public string $new_menu_item_price = '0.00';

    public function mount(): void
    {
        $this->loadMenu();
    }

    public function with(DailyDishMenuEditQueryService $queryService): array
    {
        return [
            'menuItems' => $queryService->menuItemsForBranch($this->branch),
            'categories' => Schema::hasTable('categories')
                ? Category::query()->orderBy('name')->get()
                : collect(),
        ];
    }

    private function loadMenu(?DailyDishMenuEditQueryService $queryService = null): void
    {
        $existing = ($queryService ?? app(DailyDishMenuEditQueryService::class))
            ->loadMenu($this->branch, $this->serviceDate);

        if ($existing) {
            $this->menu = $existing;
            $this->status = $existing->status;
            $this->items = DailyDishMenuSlots::normalizeRows($existing->items);
        } else {
            $this->items = DailyDishMenuSlots::defaultRows();
            $this->status = 'draft';
        }
        $this->item_search = collect($this->items)
            ->map(fn ($row) => $this->resolveMenuItemLabel((int) ($row['menu_item_id'] ?? 0)))
            ->toArray();

        $this->resetMenuItemForm();
    }

    public function selectMenuItemForSlot(int $index, int $menuItemId, string $label = ''): void
    {
        if (! isset($this->items[$index])) {
            return;
        }

        $this->items[$index]['menu_item_id'] = $menuItemId;
        $this->item_search[$index] = trim($label) !== '' ? $label : $this->resolveMenuItemLabel($menuItemId);
    }

    public function clearItem(int $idx): void
    {
        if (! $this->isEditable()) {
            return;
        }

        if (! isset($this->items[$idx])) {
            return;
        }

        $this->items[$idx]['menu_item_id'] = null;
        $this->item_search[$idx] = '';
    }

    public function openMenuItemForm(?int $targetIndex = null): void
    {
        if (! $this->isEditable()) {
            return;
        }

        $this->resetErrorBag([
            'new_menu_item_name',
            'new_menu_item_category_id',
            'new_menu_item_price',
        ]);
        $this->showMenuItemForm = true;
        $this->menu_item_target_index = $targetIndex;
        $this->new_menu_item_name = '';
        $this->new_menu_item_category_id = null;
        $this->new_menu_item_category_search = '';
        $this->new_menu_item_price = '0.00';
    }

    public function selectMenuItemCategory(int $categoryId, string $label = ''): void
    {
        $this->new_menu_item_category_id = $categoryId;
        $this->new_menu_item_category_search = trim($label) !== '' ? $label : $this->resolveCategoryLabel($categoryId);
    }

    public function clearMenuItemCategory(): void
    {
        $this->new_menu_item_category_id = null;
        $this->new_menu_item_category_search = '';
    }

    public function closeMenuItemForm(): void
    {
        $this->resetMenuItemForm();
    }

    public function createMenuItem(): void
    {
        if (! $this->isEditable()) {
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
                    'branch_id' => $this->branch,
                ],
                [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        if ($this->menu_item_target_index !== null && isset($this->items[$this->menu_item_target_index])) {
            $this->items[$this->menu_item_target_index]['menu_item_id'] = $menuItem->id;
            $this->item_search[$this->menu_item_target_index] = trim(($menuItem->code ?? '').' '.$menuItem->name);
        }

        $this->resetMenuItemForm();
        session()->flash('status', __('Menu item created.'));
    }

    public function save(DailyDishMenuService $service, DailyDishMenuEditRules $rules): void
    {
        if (! $this->isEditable()) {
            session()->flash('status', __('Menu is not editable.'));
            return;
        }

        try {
            $menu = $this->persistMenu($service, $rules);
            $this->menu = $menu;
            $this->status = $menu->status;
            session()->flash('status', __('Menu saved.'));
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?? __('Save failed.');
            session()->flash('status', $message);
        }
    }

    public function publish(DailyDishMenuService $service): void
    {
        try {
            $menu = $this->persistMenu($service, app(DailyDishMenuEditRules::class));
            $menu = $service->publish($menu, Auth::id());
            $this->menu = $menu;
            $this->status = $menu->status;
            session()->flash('status', __('Menu published.'));
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?? __('Publish failed.');
            session()->flash('status', $message);
        }
    }

    public function unpublish(DailyDishMenuService $service): void
    {
        if (! $this->menu) {
            session()->flash('status', __('Create menu first.'));
            return;
        }
        try {
            $menu = $service->unpublish($this->menu, Auth::id());
            $this->menu = $menu;
            $this->status = $menu->status;
            session()->flash('status', __('Menu reverted to draft.'));
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?? __('Unpublish failed.');
            session()->flash('status', $message);
        }
    }

    private function isEditable(): bool
    {
        return $this->status === 'draft';
    }

    private function persistMenu(DailyDishMenuService $service, DailyDishMenuEditRules $rules): DailyDishMenu
    {
        $data = $this->validate($rules->rules());

        return $service->upsertMenu(
            $this->branch,
            $this->serviceDate,
            [
                'items' => DailyDishMenuSlots::selectedItems($data['items']),
            ],
            Auth::id()
        );
    }

    private function resetMenuItemForm(): void
    {
        $this->showMenuItemForm = false;
        $this->menu_item_target_index = null;
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

<div class="w-full max-w-7xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Daily Dish Menu') }}</p>
            <h1 class="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">
                {{ __('Branch') }} {{ $branch }} · {{ $serviceDate }}
            </h1>
        </div>
        <div class="flex gap-2">
            <flux:button :href="route('daily-dish.menus.index')" wire:navigate variant="ghost">{{ __('Back') }}</flux:button>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100">
            {{ session('status') }}
        </div>
    @endif

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
        <div class="flex items-center gap-3">
            <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold
                {{ $status === 'draft' ? 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-100' : ($status === 'published' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-100' : 'bg-neutral-200 text-neutral-800 dark:bg-neutral-700 dark:text-neutral-100') }}">
                {{ ucfirst($status) }}
            </span>
        </div>

        <div class="flex gap-2">
            @if($status === 'draft')
                <flux:button type="button" wire:click="save" variant="primary">{{ __('Save') }}</flux:button>
            @endif
            @if($status === 'draft')
                <flux:button type="button" wire:click="publish" variant="primary">{{ __('Publish') }}</flux:button>
            @elseif($status === 'published')
                <flux:button type="button" wire:click="unpublish">{{ __('Unpublish') }}</flux:button>
            @endif
        </div>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
        <div class="flex items-center justify-between gap-3">
            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Items') }}</h2>
            @if($status === 'draft')
                <flux:button type="button" wire:click="openMenuItemForm" variant="ghost">{{ __('Create Menu Item') }}</flux:button>
            @endif
        </div>

        @if($showMenuItemForm && $status === 'draft')
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
                                selectMethod: 'selectMenuItemCategory',
                                clearMethod: 'clearMenuItemCategory'
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
                                    class="z-[9999] overflow-hidden rounded-md border border-neutral-200 bg-white shadow-lg dark:border-neutral-700 dark:bg-neutral-900"
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
                    <flux:button type="button" wire:click="closeMenuItemForm" variant="ghost">{{ __('Cancel') }}</flux:button>
                    <flux:button type="button" wire:click="createMenuItem" variant="primary">{{ __('Create') }}</flux:button>
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
                    @forelse ($items as $index => $row)
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
                                        initial: @js($item_search[$index] ?? ''),
                                        selectedId: @js($row['menu_item_id'] ?? null),
                                        searchUrl: '{{ route('orders.menu-items.search') }}',
                                        branchId: @js($branch)
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
                                        @disabled($status !== 'draft')
                                    />
                                    <template x-teleport="body">
                                        <div
                                            x-show="open"
                                            x-ref="panel"
                                            x-bind:style="panelStyle"
                                            class="z-[9999] overflow-hidden rounded-md border border-neutral-200 bg-white shadow-lg dark:border-neutral-700 dark:bg-neutral-900"
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
                                @if($status === 'draft')
                                    <div class="flex justify-end gap-2">
                                        <flux:button type="button" wire:click="clearItem({{ $index }})" variant="ghost">{{ __('Clear') }}</flux:button>
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

<script>
const registerDailyDishEditMenuItemLookup = () => {
    if (!window.Alpine || window.__dailyDishEditMenuItemLookupRegistered) {
        return;
    }
    window.__dailyDishEditMenuItemLookupRegistered = true;

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
                this.$wire.clearItem(this.index);
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
            this.$wire.selectMenuItemForSlot(this.index, item.id, label);
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
                'display: block',
            ].join('; ');
        },
    }));
};

if (window.Alpine) {
    registerDailyDishEditMenuItemLookup();
} else {
    document.addEventListener('alpine:init', registerDailyDishEditMenuItemLookup, { once: true });
}

const registerDailyDishEditCategoryLookup = () => {
    if (!window.Alpine || window.__dailyDishEditCategoryLookupRegistered) {
        return;
    }
    window.__dailyDishEditCategoryLookupRegistered = true;

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
                'display: block',
            ].join('; ');
        },
    }));
};

if (window.Alpine) {
    registerDailyDishEditCategoryLookup();
} else {
    document.addEventListener('alpine:init', registerDailyDishEditCategoryLookup, { once: true });
}
</script>
