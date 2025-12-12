<?php

use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\Supplier;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Illuminate\Validation\Rule;

new #[Layout('components.layouts.app')] class extends Component {
    use WithFileUploads;

    public InventoryItem $item;
    public string $item_code = '';
    public string $name = '';
    public ?string $description = null;
    public ?int $category_id = null;
    public ?int $supplier_id = null;
    public int $units_per_package = 1;
    public ?string $package_label = null;
    public ?string $unit_of_measure = null;
    public int $minimum_stock = 0;
    public ?float $cost_per_unit = null;
    public ?string $location = null;
    public ?string $status = 'active';
    public ?string $image = null;

    public function mount(InventoryItem $item): void
    {
        $this->item = $item;
        $this->fill($item->only([
            'item_code',
            'name',
            'description',
            'category_id',
            'supplier_id',
            'units_per_package',
            'package_label',
            'unit_of_measure',
            'minimum_stock',
            'cost_per_unit',
            'location',
            'status',
        ]));
    }

    public function with(): array
    {
        return [
            'categories' => Schema::hasTable('categories') ? Category::orderBy('name')->get() : collect(),
            'suppliers' => Schema::hasTable('suppliers') ? Supplier::orderBy('name')->get() : collect(),
        ];
    }

    public function save(): void
    {
        $data = $this->validate($this->rules());

        if ($this->image) {
            if ($this->item->image_path) {
                \Storage::disk('public')->delete($this->item->image_path);
            }
            $data['image_path'] = $this->storeImage($this->image, $this->item_code);
        }

        $costChanged = array_key_exists('cost_per_unit', $data) && $data['cost_per_unit'] !== null && (float) $data['cost_per_unit'] !== (float) $this->item->cost_per_unit;
        $unitsChanged = array_key_exists('units_per_package', $data) && (int) $data['units_per_package'] !== (int) $this->item->units_per_package;

        if ($costChanged || $unitsChanged) {
            $data['last_cost_update'] = now();
        }

        unset($data['current_stock']);

        $this->item->update($data);

        session()->flash('status', __('Item updated.'));
        $this->redirectRoute('inventory.index', navigate: true);
    }

    private function rules(): array
    {
        return [
            'item_code' => ['required', 'string', 'max:50', Rule::unique('inventory_items', 'item_code')->ignore($this->item->id)],
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'units_per_package' => ['required', 'integer', 'min:1'],
            'package_label' => ['nullable', 'string', 'max:50'],
            'unit_of_measure' => ['nullable', 'string', 'max:50'],
            'minimum_stock' => ['nullable', 'integer', 'min:0'],
            'cost_per_unit' => ['nullable', 'numeric', 'min:0'],
            'location' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,discontinued'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('inventory.max_image_kb', 2048)],
        ];
    }

    private function storeImage($file, string $itemCode): string
    {
        return $file->storeAs(
            'inventory/items/'.$itemCode,
            $file->hashName(),
            'public'
        );
    }
}; ?>

<div class="w-full max-w-4xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
            {{ __('Edit Inventory Item') }}
        </h1>
        <flux:button :href="route('inventory.index')" wire:navigate variant="ghost">
            {{ __('Back') }}
        </flux:button>
    </div>

    <form wire:submit="save" class="space-y-5">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <flux:input wire:model="item_code" :label="__('Item Code')" required maxlength="50" />
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
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            @if ($suppliers->count())
                <div>
                    <label class="block text-sm font-medium text-neutral-800 dark:text-neutral-200 mb-1">{{ __('Supplier') }}</label>
                    <select wire:model="supplier_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="">{{ __('None') }}</option>
                        @foreach ($suppliers as $sup)
                            <option value="{{ $sup->id }}">{{ $sup->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <flux:input wire:model="units_per_package" type="number" min="1" :label="__('Units per Package')" />
            <flux:input wire:model="minimum_stock" type="number" min="0" :label="__('Minimum Stock')" />
            <flux:input :value="$item->current_stock" :label="__('Current Stock (read-only)')" disabled />
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
            @if ($item->image_path)
                <p class="text-xs text-neutral-500 mt-1">{{ __('Current:') }} {{ $item->image_path }}</p>
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
