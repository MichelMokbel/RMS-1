<?php

use App\Models\Category;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public ?int $parentFilter = null;
    public string $message = '';

    protected $paginationTheme = 'tailwind';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingParentFilter(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        return [
            'categories' => $this->query()->paginate(10),
            'parents' => Category::alphabetical()->get(),
        ];
    }

    public function deleteCategory(int $categoryId): void
    {
        $category = Category::findOrFail($categoryId);

        if ($category->isInUse()) {
            $this->addError('delete', __('Category is in use and cannot be deleted.'));
            return;
        }

        $category->delete();
        $this->message = __('Category deleted.');
        $this->resetPage();
    }

    private function query(): Builder
    {
        return Category::query()
            ->with('parent')
            ->when($this->search, fn ($query) => $query->where('name', 'like', '%'.$this->search.'%'))
            ->when(! is_null($this->parentFilter), fn ($query) => $query->where('parent_id', $this->parentFilter))
            ->alphabetical();
    }
}; ?>

<div class="w-full max-w-7xl mx-auto px-4 space-y-6">
    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div class="flex flex-1 flex-col gap-3 md:flex-row md:items-center md:gap-4">
            <flux:input
                wire:model.debounce.400ms="search"
                placeholder="{{ __('Search categories') }}"
                class="w-full md:max-w-sm"
            />

            <div class="flex items-center gap-2">
                <label for="parentFilter" class="text-sm text-neutral-800 dark:text-neutral-200">{{ __('Parent') }}</label>
                <select
                    id="parentFilter"
                    wire:model.live.number="parentFilter"
                    class="rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50"
                >
                    <option class="bg-white text-neutral-800 dark:bg-neutral-800 dark:text-neutral-50" value="">{{ __('All') }}</option>
                    @foreach ($parents as $parent)
                        <option class="bg-white text-neutral-800 dark:bg-neutral-800 dark:text-neutral-50" value="{{ $parent->id }}">{{ $parent->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <flux:button :href="route('categories.create')" wire:navigate variant="primary">
            {{ __('Create Category') }}
        </flux:button>
    </div>

    @if ($message)
        <div class="rounded-md border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200">
            {{ $message }}
        </div>
    @endif

    @error('delete')
        <div class="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
            {{ $message }}
        </div>
    @enderror

    <div class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <table class="w-full min-w-full table-fixed divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="w-5/12 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                        {{ __('Name') }}
                    </th>
                    <th class="w-4/12 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                        {{ __('Parent') }}
                    </th>
                    <th class="w-3/12 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                        {{ __('Actions') }}
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 bg-white dark:divide-neutral-800 dark:bg-neutral-900">
                @forelse ($categories as $category)
                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                        <td class="px-4 py-3 text-sm font-medium text-neutral-900 dark:text-neutral-100">
                            {{ $category->name }}
                        </td>
                        <td class="px-4 py-3 text-sm text-neutral-700 dark:text-neutral-200">
                            {{ $category->parent?->name ?? __('-') }}
                        </td>
                        <td class="px-4 py-3 text-sm text-neutral-900 dark:text-neutral-100">
                            <div class="flex flex-wrap gap-2">
                                <flux:button size="xs" :href="route('categories.edit', $category)" wire:navigate>
                                    {{ __('Edit') }}
                                </flux:button>

                                <flux:button size="xs" variant="danger" wire:click="deleteCategory({{ $category->id }})">
                                    {{ __('Delete') }}
                                </flux:button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="px-4 py-6 text-center text-sm text-neutral-400">
                            {{ __('No categories found.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>
        {{ $categories->links() }}
    </div>
</div>
