<?php

use App\Models\MenuItem;
use App\Models\InventoryItem;
use App\Services\Recipes\RecipeFormQueryService;
use App\Services\Recipes\RecipePersistService;
use App\Support\Recipes\RecipeRules;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public ?int $menu_item_id = null;
    public string $menu_item_search = '';
    public string $name = '';
    public ?string $description = null;
    public ?int $category_id = null;
    public float $yield_quantity = 0.0;
    public string $yield_unit = '';
    public float $overhead_pct = 0.0;
    public ?float $selling_price_per_unit = null;

    public array $items = [];
    public array $ingredient_item_search = [];

    public function mount(): void
    {
        $this->items = [
            [
                'source_type' => 'inventory_item',
                'inventory_item_id' => null,
                'sub_recipe_id' => null,
                'quantity' => 0,
                'unit' => '',
                'quantity_type' => 'unit',
                'cost_type' => 'ingredient',
            ],
        ];
        $this->ingredient_item_search = [''];
    }

    public function selectMenuItemPayload(int $menuItemId, string $label = ''): void
    {
        $menuItem = MenuItem::query()->find($menuItemId);
        if (! $menuItem) {
            $this->menu_item_id = null;
            $this->menu_item_search = '';

            return;
        }

        $this->menu_item_id = (int) $menuItem->id;
        $this->menu_item_search = trim($label) !== '' ? $label : (string) $menuItem->name;
        if (trim($this->name) === '') {
            $this->name = (string) $menuItem->name;
        }

        $this->selling_price_per_unit = $menuItem->selling_price_per_unit !== null
            ? (float) $menuItem->selling_price_per_unit
            : null;
    }

    public function clearMenuItemSelection(): void
    {
        $this->menu_item_id = null;
        $this->menu_item_search = '';
    }

    public function addItem(): void
    {
        $this->items[] = [
            'source_type' => 'inventory_item',
            'inventory_item_id' => null,
            'sub_recipe_id' => null,
            'quantity' => 0,
            'unit' => '',
            'quantity_type' => 'unit',
            'cost_type' => 'ingredient',
        ];
        $this->ingredient_item_search[] = '';
    }

    public function removeItem(int $idx): void
    {
        unset($this->items[$idx]);
        unset($this->ingredient_item_search[$idx]);
        $this->items = array_values($this->items);
        $this->ingredient_item_search = array_values($this->ingredient_item_search);
    }

    public function selectIngredientItemPayload(int $index, int $inventoryItemId, string $label = ''): void
    {
        if (! array_key_exists($index, $this->items)) {
            return;
        }

        $item = InventoryItem::query()
            ->where('status', 'active')
            ->find($inventoryItemId);

        if (! $item) {
            $this->clearIngredientItemSelection($index);

            return;
        }

        $this->items[$index]['inventory_item_id'] = (int) $item->id;
        if (trim((string) ($this->items[$index]['unit'] ?? '')) === '' && $item->unit_of_measure) {
            $this->items[$index]['unit'] = (string) $item->unit_of_measure;
        }

        $this->ingredient_item_search[$index] = trim($label) !== ''
            ? $label
            : trim((string) ($item->item_code ?? '').' '.(string) $item->name);
    }

    public function clearIngredientItemSelection(int $index): void
    {
        if (! array_key_exists($index, $this->items)) {
            return;
        }

        $this->items[$index]['inventory_item_id'] = null;
        $this->ingredient_item_search[$index] = '';
    }

    public function with(RecipeFormQueryService $formQuery): array
    {
        return [
            'categories' => $formQuery->categories(),
            'inventoryItems' => $formQuery->inventoryItems(),
            'subRecipes' => $formQuery->subRecipes(),
        ];
    }

    public function saveDraft(RecipePersistService $persist, RecipeRules $rules): void
    {
        $this->persistWithStatus($persist, $rules, 'draft');
    }

    public function save(RecipePersistService $persist, RecipeRules $rules): void
    {
        $this->persistWithStatus($persist, $rules, 'published');
    }

    private function persistWithStatus(RecipePersistService $persist, RecipeRules $rules, string $status): void
    {
        $data = $this->validate(array_merge($rules->rules(), [
            'menu_item_id' => ['nullable', 'integer', 'exists:menu_items,id'],
        ]));

        $menuItemId = (int) ($data['menu_item_id'] ?? 0);
        unset($data['menu_item_id']);
        $data['status'] = $status;
        $recipe = $persist->create($data);
        if ($menuItemId > 0) {
            MenuItem::query()->whereKey($menuItemId)->update(['recipe_id' => $recipe->id]);
        }

        session()->flash(
            'status',
            $status === 'draft' ? __('Recipe saved as draft.') : __('Recipe created.')
        );
        $this->redirectRoute('recipes.show', $recipe, navigate: true);
    }
}; ?>

<div class="w-full max-w-5xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Create Recipe') }}</h1>
        <flux:button :href="route('recipes.index')" wire:navigate variant="ghost">{{ __('Back') }}</flux:button>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100">
            {{ session('status') }}
        </div>
    @endif

    <div class="space-y-4 rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div>
            <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Menu Item') }}</label>
            <div
                class="relative mt-1"
                wire:ignore
                x-data="recipeMenuItemLookup({
                    initial: @js($menu_item_search),
                    selectedId: @js($menu_item_id),
                    searchUrl: '{{ route('recipes.menu-items.search') }}'
                })"
                x-on:keydown.escape.stop="close()"
                x-on:click.outside="close()"
            >
                <input
                    type="text"
                    class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50"
                    x-model="query"
                    x-on:input.debounce.200ms="onInput()"
                    x-on:focus="onInput(true)"
                    placeholder="{{ __('Search menu item') }}"
                />
                <template x-if="open">
                    <div
                        x-ref="panel"
                        x-bind:style="panelStyle"
                        class="mb-1 overflow-hidden rounded-md border border-neutral-200 bg-white shadow-lg dark:border-neutral-700 dark:bg-neutral-900"
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
            @error('menu_item_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <flux:input wire:model="name" :label="__('Name')" />
            <div>
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Category') }}</label>
                <select wire:model="category_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="">{{ __('None') }}</option>
                    @foreach($categories as $cat)
                        <option value="{{ $cat->id }}">{{ $cat->fullName() }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <flux:textarea wire:model="description" :label="__('Description')" rows="3" />

        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <flux:input wire:model="yield_quantity" type="number" step="0.001" min="0.001" :label="__('Yield Quantity')" />
            <flux:input wire:model="yield_unit" :label="__('Yield Unit')" />
            <flux:input wire:model="overhead_pct" type="number" step="0.0001" min="0" :label="__('Overhead %')" />
        </div>
        <flux:input wire:model="selling_price_per_unit" type="number" step="0.01" min="0" :label="__('Selling Price / Unit')" />
    </div>

    <div class="space-y-3 rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="flex items-center justify-between">
            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Ingredients') }}</h2>
            <flux:button type="button" wire:click="addItem">{{ __('Add Row') }}</flux:button>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                    <tr>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Source') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Item') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Quantity') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Unit') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Qty Type') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Cost Type') }}</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    @foreach ($items as $index => $row)
                        <tr class="align-top">
                            <td class="px-3 py-2 text-sm">
                                <select wire:model.live="items.{{ $index }}.source_type" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                    <option value="inventory_item">{{ __('Inventory Item') }}</option>
                                    <option value="sub_recipe">{{ __('Sub Recipe') }}</option>
                                </select>
                            </td>
                            <td class="px-3 py-2 text-sm">
                                @if (($row['source_type'] ?? 'inventory_item') === 'sub_recipe')
                                    <select wire:model="items.{{ $index }}.sub_recipe_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                        <option value="">{{ __('Select recipe') }}</option>
                                        @foreach($subRecipes as $subRecipe)
                                            <option value="{{ $subRecipe->id }}">{{ $subRecipe->name }}</option>
                                        @endforeach
                                    </select>
                                    @error("items.$index.sub_recipe_id") <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                @else
                                    <div
                                        class="relative"
                                        wire:ignore
                                        x-data="recipeIngredientLookup({
                                            index: {{ $index }},
                                            initial: @js($ingredient_item_search[$index] ?? ''),
                                            selectedId: @js($row['inventory_item_id'] ?? null),
                                            searchUrl: '{{ route('recipes.inventory-items.search') }}'
                                        })"
                                        x-on:keydown.escape.stop="close()"
                                        x-on:click.outside="close()"
                                    >
                                        <input
                                            type="text"
                                            class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50"
                                            x-model="query"
                                            x-on:input.debounce.200ms="onInput()"
                                            x-on:focus="onInput(true)"
                                            placeholder="{{ __('Search item') }}"
                                        />
                                        <template x-if="open">
                                            <div
                                                x-ref="panel"
                                                x-bind:style="panelStyle"
                                                class="mb-1 overflow-hidden rounded-md border border-neutral-200 bg-white shadow-lg dark:border-neutral-700 dark:bg-neutral-900"
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
                                                            <div class="text-xs text-neutral-500 dark:text-neutral-400" x-show="item.unit" x-text="item.unit"></div>
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
                                    @error("items.$index.inventory_item_id") <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                @endif
                            </td>
                            <td class="px-3 py-2 text-sm">
                                <flux:input wire:model="items.{{ $index }}.quantity" type="number" step="0.001" min="0" />
                            </td>
                            <td class="px-3 py-2 text-sm">
                                <flux:input wire:model="items.{{ $index }}.unit" />
                            </td>
                            <td class="px-3 py-2 text-sm">
                                <select wire:model="items.{{ $index }}.quantity_type" @if (($row['source_type'] ?? 'inventory_item') === 'sub_recipe') disabled @endif class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 disabled:bg-neutral-100 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50 dark:disabled:bg-neutral-700">
                                    <option value="unit">{{ __('Unit') }}</option>
                                    <option value="package">{{ __('Package') }}</option>
                                </select>
                            </td>
                            <td class="px-3 py-2 text-sm">
                                <select wire:model="items.{{ $index }}.cost_type" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                    <option value="ingredient">{{ __('Ingredient') }}</option>
                                    <option value="packaging">{{ __('Packaging') }}</option>
                                    <option value="labour">{{ __('Labour') }}</option>
                                    <option value="transport">{{ __('Transport') }}</option>
                                    <option value="other">{{ __('Other') }}</option>
                                </select>
                            </td>
                            <td class="px-3 py-2 text-sm text-right">
                                <flux:button type="button" wire:click="removeItem({{ $index }})" variant="ghost">{{ __('Remove') }}</flux:button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="flex justify-end gap-3">
        <flux:button :href="route('recipes.index')" wire:navigate variant="ghost">{{ __('Cancel') }}</flux:button>
        <flux:button type="button" wire:click="saveDraft" variant="filled">{{ __('Save Draft') }}</flux:button>
        <flux:button type="button" wire:click="save" variant="primary">{{ __('Save & Publish') }}</flux:button>
    </div>
</div>

@once
    <script>
        const registerRecipeLookups = () => {
            if (!window.Alpine || window.__recipeMenuItemLookupRegistered) {
                return;
            }
            window.__recipeMenuItemLookupRegistered = true;

            window.Alpine.data('recipeMenuItemLookup', ({ initial, selectedId, searchUrl }) => ({
                query: initial || '',
                selectedId: selectedId || null,
                selectedLabel: initial || '',
                searchUrl,
                results: [],
                loading: false,
                open: false,
                hasSearched: false,
                panelStyle: '',
                controller: null,
                onInput(force = false) {
                    if (this.selectedId !== null && this.query !== this.selectedLabel) {
                        this.selectedId = null;
                        this.selectedLabel = '';
                        this.$wire.clearMenuItemSelection();
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
                    if (this.controller) {
                        this.controller.abort();
                    }
                    this.controller = new AbortController();
                    const params = new URLSearchParams({ q: term });
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
                    this.$wire.selectMenuItemPayload(item.id, label);
                },
                close() {
                    this.open = false;
                },
                positionDropdown() {
                    const input = this.$el.querySelector('input');
                    if (!input) {
                        return;
                    }
                    const rect = input.getBoundingClientRect();
                    this.panelStyle = [
                        'position: fixed',
                        'left: ' + rect.left + 'px',
                        'top: ' + rect.bottom + 'px',
                        'width: ' + rect.width + 'px',
                        'z-index: 9999',
                    ].join('; ');
                },
            }));

            window.Alpine.data('recipeIngredientLookup', ({ index, initial, selectedId, searchUrl }) => ({
                index,
                query: initial || '',
                selectedId: selectedId || null,
                selectedLabel: initial || '',
                searchUrl,
                results: [],
                loading: false,
                open: false,
                hasSearched: false,
                panelStyle: '',
                controller: null,
                onInput(force = false) {
                    if (this.selectedId !== null && this.query !== this.selectedLabel) {
                        this.selectedId = null;
                        this.selectedLabel = '';
                        this.$wire.clearIngredientItemSelection(this.index);
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
                    if (this.controller) {
                        this.controller.abort();
                    }
                    this.controller = new AbortController();
                    const params = new URLSearchParams({ q: term });
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
                    this.$wire.selectIngredientItemPayload(this.index, item.id, label);
                },
                close() {
                    this.open = false;
                },
                positionDropdown() {
                    const input = this.$el.querySelector('input');
                    if (!input) {
                        return;
                    }
                    const rect = input.getBoundingClientRect();
                    this.panelStyle = [
                        'position: fixed',
                        'left: ' + rect.left + 'px',
                        'top: ' + rect.bottom + 'px',
                        'width: ' + rect.width + 'px',
                        'z-index: 9999',
                    ].join('; ');
                },
            }));
        };

        if (window.Alpine) {
            registerRecipeLookups();
        }
        document.addEventListener('alpine:init', registerRecipeLookups);
        document.addEventListener('livewire:navigated', registerRecipeLookups);
    </script>
@endonce
