<?php

use App\Models\Category;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public Category $category;
    public string $name = '';
    public ?string $description = null;
    public ?int $parent_id = null;
    public string $errorMessage = '';

    public function mount(Category $category): void
    {
        $this->category = $category;
        $this->name = $category->name;
        $this->description = $category->description;
        $this->parent_id = $category->parent_id;
    }

    public function with(): array
    {
        return [
            'parents' => Category::where('id', '!=', $this->category->id)->alphabetical()->get(),
        ];
    }

    public function save(): void
    {
        $validated = $this->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('categories', 'name')
                    ->ignore($this->category->id)
                    ->where(fn ($query) => $query->where('parent_id', $this->parent_id)->whereNull('deleted_at')),
            ],
            'description' => ['nullable', 'string'],
            'parent_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
        ]);

        if ($this->parent_id === $this->category->id) {
            $this->addError('parent_id', __('A category cannot be its own parent.'));
            return;
        }

        if ($this->category->wouldCreateCycle($this->parent_id)) {
            $this->addError('parent_id', __('Selecting this parent would create a cycle.'));
            return;
        }

        $this->category->update($validated);

        $this->redirectRoute('categories.index');
    }
}; ?>

<div class="space-y-6 max-w-2xl">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Edit Category') }}</h1>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Update category details.') }}</p>
        </div>

        <flux:button :href="route('categories.index')" wire:navigate variant="ghost">
            {{ __('Back') }}
        </flux:button>
    </div>

    <form wire:submit="save" class="grid gap-4">
        <flux:input wire:model.defer="name" :label="__('Name')" required />

        <flux:textarea wire:model.defer="description" :label="__('Description')" rows="3" />

        <div>
            <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Parent Category') }}</label>
            <select
                wire:model="parent_id"
                class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900"
            >
                <option value="">{{ __('None') }}</option>
                @foreach ($parents as $parent)
                    <option value="{{ $parent->id }}">{{ $parent->name }}</option>
                @endforeach
            </select>
            @error('parent_id') <p class="text-sm text-red-500 mt-1">{{ $message }}</p> @enderror
        </div>

        <div class="flex items-center gap-3">
            <flux:button type="submit" variant="primary">
                {{ __('Save') }}
            </flux:button>
            <flux:button :href="route('categories.index')" wire:navigate variant="ghost">
                {{ __('Cancel') }}
            </flux:button>
        </div>
    </form>
</div>
