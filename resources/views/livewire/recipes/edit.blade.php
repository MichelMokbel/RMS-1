<?php

use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\Recipe;
use App\Models\RecipeItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public Recipe $recipe;

    public string $name = '';
    public ?string $description = null;
    public ?int $category_id = null;
    public float $yield_quantity = 0.0;
    public string $yield_unit = '';
    public float $overhead_pct = 0.0;
    public ?float $selling_price_per_unit = null;

    public array $items = [];

    public function mount(): void
    {
        $this->name = $this->recipe->name;
        $this->description = $this->recipe->description;
        $this->category_id = $this->recipe->category_id;
        $this->yield_quantity = (float) $this->recipe->yield_quantity;
        $this->yield_unit = $this->recipe->yield_unit;
        $this->overhead_pct = (float) $this->recipe->overhead_pct;
        $this->selling_price_per_unit = $this->recipe->selling_price_per_unit !== null ? (float) $this->recipe->selling_price_per_unit : null;

        $this->items = $this->recipe->items->map(function ($item) {
            return [
                'inventory_item_id' => $item->inventory_item_id,
                'quantity' => (float) $item->quantity,
                'unit' => $item->unit,
                'quantity_type' => $item->quantity_type,
                'cost_type' => $item->cost_type,
            ];
        })->values()->toArray();
    }

    public function addItem(): void
    {
        $this->items[] = [
            'inventory_item_id' => null,
            'quantity' => 0,
            'unit' => '',
            'quantity_type' => 'unit',
            'cost_type' => 'ingredient',
        ];
    }

    public function removeItem(int $idx): void
    {
        unset($this->items[$idx]);
        $this->items = array_values($this->items);
    }

    public function with(): array
    {
        return [
            'categories' => Schema::hasTable('categories') ? Category::orderBy('name')->get() : collect(),
            'inventoryItems' => Schema::hasTable('inventory_items') ? InventoryItem::orderBy('name')->get() : collect(),
        ];
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:200'],
            'yield_quantity' => ['required', 'numeric', 'min:0.001'],
            'yield_unit' => ['required', 'string', 'max:50'],
            'overhead_pct' => ['required', 'numeric', 'min:0'],
            'selling_price_per_unit' => ['nullable', 'numeric', 'min:0'],
            'items' => ['array', 'min:1'],
            'items.*.inventory_item_id' => ['required', 'integer', 'exists:inventory_items,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit' => ['required', 'string', 'max:50'],
            'items.*.quantity_type' => ['required', 'in:unit,package'],
            'items.*.cost_type' => ['required', 'in:ingredient,packaging,labour,transport,other'],
        ]);

        $recipe = DB::transaction(function () use ($data) {
            $this->recipe->update([
                'name' => $data['name'],
                'description' => $this->description,
                'category_id' => $this->category_id,
                'yield_quantity' => $data['yield_quantity'],
                'yield_unit' => $data['yield_unit'],
                'overhead_pct' => $data['overhead_pct'],
                'selling_price_per_unit' => $this->selling_price_per_unit,
            ]);

            // Simplest sync: delete and recreate items
            $this->recipe->items()->delete();

            foreach ($data['items'] as $item) {
                RecipeItem::create([
                    'recipe_id' => $this->recipe->id,
                    'inventory_item_id' => $item['inventory_item_id'],
                    'quantity' => $item['quantity'],
                    'unit' => $item['unit'],
                    'quantity_type' => $item['quantity_type'],
                    'cost_type' => $item['cost_type'],
                ]);
            }

            return $this->recipe->fresh();
        });

        session()->flash('status', __('Recipe updated.'));
        $this->redirectRoute('recipes.show', $recipe, navigate: true);
    }
}; ?>

<div class="w-full max-w-5xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Edit Recipe') }}</h1>
        <flux:button :href="route('recipes.show', $recipe)" wire:navigate variant="ghost">{{ __('Back') }}</flux:button>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100">
            {{ session('status') }}
        </div>
    @endif

    <div class="space-y-4 rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <flux:input wire:model="name" :label="__('Name')" />
            <div>
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Category') }}</label>
                <select wire:model="category_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="">{{ __('None') }}</option>
                    @foreach($categories as $cat)
                        <option value="{{ $cat->id }}">{{ $cat->name }}</option>
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
                                <select wire:model="items.{{ $index }}.inventory_item_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                    <option value="">{{ __('Select item') }}</option>
                                    @foreach($inventoryItems as $inv)
                                        <option value="{{ $inv->id }}">{{ $inv->item_code ?? '' }} {{ $inv->name }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="px-3 py-2 text-sm">
                                <flux:input wire:model="items.{{ $index }}.quantity" type="number" step="0.001" min="0" />
                            </td>
                            <td class="px-3 py-2 text-sm">
                                <flux:input wire:model="items.{{ $index }}.unit" />
                            </td>
                            <td class="px-3 py-2 text-sm">
                                <select wire:model="items.{{ $index }}.quantity_type" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
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
        <flux:button :href="route('recipes.show', $recipe)" wire:navigate variant="ghost">{{ __('Cancel') }}</flux:button>
        <flux:button type="button" wire:click="save" variant="primary">{{ __('Save Changes') }}</flux:button>
    </div>
</div>

