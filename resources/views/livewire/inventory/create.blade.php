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
