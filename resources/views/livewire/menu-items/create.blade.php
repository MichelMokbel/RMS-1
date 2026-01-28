<?php

use App\Models\Category;
use App\Models\MenuItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public string $code = '';
    public string $name = '';
    public ?string $arabic_name = null;
    public ?int $category_id = null;
    public ?int $recipe_id = null;
    public float $selling_price_per_unit = 0;
    public float $tax_rate = 0;
    public bool $is_active = true;
    public int $display_order = 0;
    public array $branch_ids = [];

    public function mount(): void
    {
        if (Schema::hasTable('branches')) {
            $defaultBranch = (int) config('inventory.default_branch_id', 1);
            $this->branch_ids = [$defaultBranch > 0 ? $defaultBranch : 1];
        }
    }

    public function with(): array
    {
        return [
            'categories' => Schema::hasTable('categories') ? Category::orderBy('name')->get() : collect(),
            'recipes' => Schema::hasTable('recipes') ? DB::table('recipes')->select('id', 'name')->orderBy('name')->get() : collect(),
            'branches' => Schema::hasTable('branches') ? DB::table('branches')->where('is_active', 1)->orderBy('name')->get() : collect(),
        ];
    }

    public function save(): void
    {
        $data = $this->validate($this->rules());
        $branchIds = $data['branch_ids'] ?? [];
        unset($data['branch_ids']);
        $menuItem = MenuItem::create($data);
        $this->syncBranches($menuItem->id, $branchIds);
        session()->flash('status', __('Menu item created.'));
        $this->redirectRoute('menu-items.index', navigate: true);
    }

    private function rules(): array
    {
        $rules = [
            'code' => ['required', 'string', 'max:50', 'unique:menu_items,code'],
            'name' => ['required', 'string', 'max:255'],
            'arabic_name' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'recipe_id' => ['nullable', 'integer', 'exists:recipes,id'],
            'selling_price_per_unit' => ['required', 'numeric', 'min:0'],
            'tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['required', 'boolean'],
            'display_order' => ['required', 'integer', 'min:0'],
        ];

        if (Schema::hasTable('branches') && Schema::hasTable('menu_item_branches')) {
            $rules['branch_ids'] = ['required', 'array', 'min:1'];
            $rules['branch_ids.*'] = ['integer', 'exists:branches,id'];
        }

        return $rules;
    }

    private function syncBranches(int $menuItemId, array $branchIds): void
    {
        if (! Schema::hasTable('menu_item_branches') || ! Schema::hasTable('branches')) {
            return;
        }

        $branchIds = array_values(array_unique(array_filter(array_map('intval', $branchIds))));
        if (empty($branchIds)) {
            $defaultBranch = (int) config('inventory.default_branch_id', 1);
            $branchIds = [$defaultBranch > 0 ? $defaultBranch : 1];
        }

        $validBranchIds = DB::table('branches')->whereIn('id', $branchIds)->pluck('id')->all();
        DB::table('menu_item_branches')->where('menu_item_id', $menuItemId)->delete();

        $now = now();
        $rows = array_map(fn ($id) => [
            'menu_item_id' => $menuItemId,
            'branch_id' => $id,
            'created_at' => $now,
            'updated_at' => $now,
        ], $validBranchIds);

        if (! empty($rows)) {
            DB::table('menu_item_branches')->insert($rows);
        }
    }
}; ?>

<div class="w-full max-w-4xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
            {{ __('Create Menu Item') }}
        </h1>
        <flux:button :href="route('menu-items.index')" wire:navigate variant="ghost">
            {{ __('Back') }}
        </flux:button>
    </div>

    <form wire:submit="save" class="space-y-4">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <flux:input wire:model="code" :label="__('Code')" required maxlength="50" />
            <flux:input wire:model="name" :label="__('Name')" required maxlength="255" />
            <flux:input wire:model="arabic_name" :label="__('Arabic Name')" maxlength="255" />
        </div>
        <flux:input wire:model="display_order" type="number" min="0" :label="__('Display Order')" />

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            @if ($categories->count())
                <div>
                    <label class="block text-sm font-medium text-neutral-800 dark:text-neutral-200 mb-1">{{ __('Category') }}</label>
                    <select wire:model="category_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="">{{ __('None') }}</option>
                        @foreach ($categories as $cat)
                            <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            <div>
                <label class="block text-sm font-medium text-neutral-800 dark:text-neutral-200 mb-1">{{ __('Recipe') }}</label>
                @if ($recipes->count())
                    <select wire:model="recipe_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="">{{ __('None') }}</option>
                        @foreach ($recipes as $recipe)
                            <option value="{{ $recipe->id }}">{{ $recipe->name }}</option>
                        @endforeach
                    </select>
                @else
                    <flux:input disabled placeholder="{{ __('Recipes module not available yet') }}" />
                @endif
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <flux:input wire:model="selling_price_per_unit" type="number" step="0.001" min="0" :label="__('Selling Price')" />
            <flux:input wire:model="tax_rate" type="number" step="0.01" min="0" max="100" :label="__('Tax Rate (%)')" />
            <div class="flex items-center gap-3">
                <flux:checkbox wire:model="is_active" :label="__('Active')" />
            </div>
        </div>

        @if ($branches->count())
            <div class="space-y-2">
                <label class="block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Available Branches') }}</label>
                <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
                    @foreach ($branches as $branch)
                        <label class="inline-flex items-center gap-2 text-sm text-neutral-700 dark:text-neutral-200">
                            <input type="checkbox" wire:model="branch_ids" value="{{ $branch->id }}" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
                            <span>{{ $branch->name }}</span>
                        </label>
                    @endforeach
                </div>
                @error('branch_ids') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                @error('branch_ids.*') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
        @endif

        <div class="flex justify-end gap-3">
            <flux:button :href="route('menu-items.index')" wire:navigate variant="ghost">
                {{ __('Cancel') }}
            </flux:button>
            <flux:button type="submit" variant="primary">
                {{ __('Save') }}
            </flux:button>
        </div>
    </form>
</div>
