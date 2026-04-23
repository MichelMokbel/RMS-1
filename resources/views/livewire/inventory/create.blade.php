<?php

use App\Services\Inventory\InventoryItemFormQueryService;
use App\Services\Inventory\InventoryItemPersistService;
use App\Support\Inventory\InventoryItemRules;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.app')] class extends Component {
    use WithFileUploads;

    public string $item_code = '';
    public string $name = '';
    public ?string $description = null;
    public ?int $category_id = null;
    public ?int $supplier_id = null;
    public ?int $branch_id = null;
    public float $units_per_package = 1.0;
    public ?string $package_label = null;
    public ?string $unit_of_measure = null;
    public float $minimum_stock = 0.0;
    public float $current_stock = 0.0;
    public ?float $cost_per_unit = null;
    public ?string $location = null;
    public ?string $status = 'active';
    public ?string $image = null;

    public function mount(): void
    {
        $this->branch_id = (int) config('inventory.default_branch_id', 1);
        $this->item_code = app(InventoryItemPersistService::class)->nextItemCode();
    }

    public function with(InventoryItemFormQueryService $formQuery): array
    {
        return [
            'categories' => $formQuery->categories(),
            'suppliers' => $formQuery->suppliers(),
            'branches' => $formQuery->branches(),
        ];
    }

    public function save(InventoryItemPersistService $persist, InventoryItemRules $rules): void
    {
        $data = $this->validate($rules->createRules());
        $persist->createFromForm($data, $this->image, Auth::id());

        session()->flash('status', __('Item created.'));
        $this->redirectRoute('inventory.index', navigate: true);
    }
}; ?>

<div class="w-full max-w-4xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
            {{ __('Create Inventory Item') }}
        </h1>
        <flux:button :href="route('inventory.index')" wire:navigate variant="ghost">
            {{ __('Back') }}
        </flux:button>
    </div>

    <form wire:submit="save" class="space-y-5">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <flux:input wire:model="item_code" :label="__('Item Code')" readonly maxlength="50" />
            <flux:input wire:model="name" :label="__('Name')" required maxlength="200" />
            <flux:input wire:model="package_label" :label="__('Package Label')" maxlength="50" />
            <flux:input wire:model="unit_of_measure" :label="__('Unit of Measure')" maxlength="50" />
        </div>

        <flux:textarea wire:model="description" :label="__('Description')" rows="3" />

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            @if ($categories->count())
                <div>
                    <label class="block text-sm font-medium text-neutral-800 dark:text-neutral-200 mb-1">{{ __('Category') }}</label>
                    <select wire:model="category_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="">{{ __('None') }}</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}">{{ $category->fullName() }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            @if ($suppliers->count())
                <div>
                    <label class="block text-sm font-medium text-neutral-800 dark:text-neutral-200 mb-1">{{ __('Supplier') }}</label>
                    <div
                        class="relative"
                        x-data="{
                            open: false,
                            query: '',
                            selectedId: @js($supplier_id),
                            options: @js($suppliers->map(fn ($sup) => ['id' => (int) $sup->id, 'name' => $sup->name])->values()),
                            init() {
                                const selected = this.options.find((item) => Number(item.id) === Number(this.selectedId));
                                this.query = selected ? selected.name : '';
                            },
                            get filteredOptions() {
                                const term = this.query.trim().toLowerCase();
                                if (term === '') {
                                    return this.options;
                                }

                                return this.options.filter((item) => item.name.toLowerCase().includes(term));
                            },
                            choose(item) {
                                this.selectedId = item.id;
                                this.query = item.name;
                                this.open = false;
                                this.$wire.set('supplier_id', item.id);
                            },
                            clearSelection() {
                                this.selectedId = null;
                                this.query = '';
                                this.open = false;
                                this.$wire.set('supplier_id', null);
                            },
                        }"
                        x-on:keydown.escape.stop="open = false"
                        x-on:click.outside="open = false"
                    >
                        <input
                            type="text"
                            x-model="query"
                            x-on:focus="open = true"
                            x-on:input="open = true"
                            placeholder="{{ __('Search supplier') }}"
                            class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50"
                        />
                        <button
                            x-show="selectedId !== null || query !== ''"
                            x-on:click.prevent="clearSelection()"
                            type="button"
                            class="absolute inset-y-0 right-0 px-3 text-xs text-neutral-500 hover:text-neutral-700 dark:text-neutral-400 dark:hover:text-neutral-200"
                        >{{ __('Clear') }}</button>
                        <div
                            x-show="open"
                            class="absolute z-30 mt-1 max-h-60 w-full overflow-auto rounded-md border border-neutral-200 bg-white shadow-lg dark:border-neutral-700 dark:bg-neutral-900"
                        >
                            <button
                                type="button"
                                x-on:click="clearSelection()"
                                class="w-full px-3 py-2 text-left text-sm text-neutral-500 hover:bg-neutral-50 dark:text-neutral-300 dark:hover:bg-neutral-800/80"
                            >
                                {{ __('None') }}
                            </button>
                            <template x-for="item in filteredOptions" :key="item.id">
                                <button
                                    type="button"
                                    x-on:click="choose(item)"
                                    class="w-full px-3 py-2 text-left text-sm text-neutral-800 hover:bg-neutral-50 dark:text-neutral-100 dark:hover:bg-neutral-800/80"
                                    x-text="item.name"
                                ></button>
                            </template>
                            <div x-show="filteredOptions.length === 0" class="px-3 py-2 text-sm text-neutral-500 dark:text-neutral-400">
                                {{ __('No suppliers found.') }}
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            @if ($branches->count())
                <div>
                    <label class="block text-sm font-medium text-neutral-800 dark:text-neutral-200 mb-1">{{ __('Branch for Initial Stock') }}</label>
                    <select wire:model="branch_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <flux:input wire:model="units_per_package" type="number" min="0.001" step="0.001" :label="__('Units per Package')" />
            <flux:input wire:model="minimum_stock" type="number" min="0" step="0.001" :label="__('Minimum Stock')" />
            <flux:input wire:model="current_stock" type="number" min="0" step="0.001" :label="__('Initial Stock')" />
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <flux:input wire:model="cost_per_unit" type="number" step="0.0001" min="0" :label="__('Package Cost')" />
            <flux:input wire:model="location" :label="__('Location')" maxlength="100" />
            <div>
                <label class="block text-sm font-medium text-neutral-800 dark:text-neutral-200 mb-1">{{ __('Status') }}</label>
                <select wire:model="status" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="active">{{ __('Active') }}</option>
                    <option value="discontinued">{{ __('Discontinued') }}</option>
                </select>
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium text-neutral-800 dark:text-neutral-200 mb-1">{{ __('Image') }}</label>
            <input type="file" wire:model="image" accept=".jpg,.jpeg,.png,.webp" class="text-sm text-neutral-700 dark:text-neutral-200" />
            @if ($image)
                <div class="mt-2 text-sm text-neutral-600 dark:text-neutral-300">{{ __('Preview selected image') }}</div>
            @endif
        </div>

        <div class="flex justify-end gap-3">
            <flux:button :href="route('inventory.index')" wire:navigate variant="ghost">
                {{ __('Cancel') }}
            </flux:button>
            <flux:button type="submit" variant="primary">
                {{ __('Save') }}
            </flux:button>
        </div>
    </form>
</div>
