<?php

use App\Models\Category;
use App\Models\MenuItem;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public int $perPage = 25;
    public string $message = '';

    /** @var array<int, int|null> item_id => category_id */
    public array $assignments = [];

    /** Track original values to detect changes */
    public array $original = [];

    protected $paginationTheme = 'tailwind';

    public function mount(): void
    {
        $this->loadAssignments();
    }

    public function with(): array
    {
        return [
            'items' => MenuItem::query()
                ->when($this->search, fn ($q) => $q->search($this->search))
                ->ordered()
                ->paginate($this->perPage),
            'categories' => Category::alphabetical()->get(),
        ];
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingPerPage(): void
    {
        $this->resetPage();
    }

    public function toggleCategory(int $itemId, int $categoryId): void
    {
        // If already assigned to this category, unassign it
        if (($this->assignments[$itemId] ?? null) === $categoryId) {
            $this->assignments[$itemId] = null;
        } else {
            $this->assignments[$itemId] = $categoryId;
        }
    }

    public function save(): void
    {
        $changed = 0;

        foreach ($this->assignments as $itemId => $categoryId) {
            $originalValue = $this->original[$itemId] ?? null;

            if ($categoryId !== $originalValue) {
                MenuItem::where('id', $itemId)->update(['category_id' => $categoryId]);
                $changed++;
            }
        }

        // Refresh original values after save
        $this->loadAssignments();

        $this->message = $changed > 0
            ? __(':count menu item(s) updated successfully.', ['count' => $changed])
            : __('No changes to save.');
    }

    private function loadAssignments(): void
    {
        $map = MenuItem::pluck('category_id', 'id')
            ->map(fn ($v) => $v ? (int) $v : null)
            ->all();

        $this->assignments = $map;
        $this->original = $map;
    }
}; ?>

<div class="flex flex-col h-[calc(100vh-4rem)] w-full max-w-full mx-auto px-4">
    {{-- Fixed header area --}}
    <div class="shrink-0 space-y-4 py-4">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                {{ __('Categorize Menu Items') }}
            </h1>
            <div class="flex items-center gap-2">
                <flux:button :href="route('menu-items.index')" wire:navigate variant="ghost">
                    {{ __('Back') }}
                </flux:button>
                <flux:button wire:click="save" variant="primary">
                    {{ __('Save Changes') }}
                </flux:button>
            </div>
        </div>

        @if ($message)
            <div class="rounded-md border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200">
                {{ $message }}
            </div>
        @endif

        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="flex flex-wrap items-center gap-3">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search menu items...') }}"
                    class="w-full md:w-64"
                />
                <div class="flex items-center gap-2">
                    <label for="per_page" class="text-sm text-neutral-800 dark:text-neutral-200">{{ __('Per page') }}</label>
                    <select id="per_page" wire:model.live="perPage" class="rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                        <option value="250">250</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    {{-- Scrollable table area - fills remaining height --}}
    <div class="flex-1 min-h-0 overflow-auto rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <table class="w-full min-w-full divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="sticky top-0 z-20 bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="sticky left-0 z-30 bg-neutral-50 dark:bg-neutral-800/90 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100 min-w-[200px] border-b border-neutral-200 dark:border-neutral-700">
                        {{ __('Menu Item') }}
                    </th>
                    @foreach ($categories as $category)
                        <th class="bg-neutral-50 dark:bg-neutral-800/90 px-2 py-3 text-center text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100 min-w-[100px] border-b border-neutral-200 dark:border-neutral-700">
                            <span class="whitespace-nowrap">{{ $category->name }}</span>
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 bg-white dark:divide-neutral-800 dark:bg-neutral-900">
                @forelse ($items as $item)
                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70" wire:key="item-{{ $item->id }}">
                        <td class="sticky left-0 z-10 bg-white dark:bg-neutral-900 px-4 py-2 text-sm font-medium text-neutral-900 dark:text-neutral-100 border-r border-neutral-100 dark:border-neutral-800">
                            <div>{{ $item->name }}</div>
                            @if ($item->code)
                                <div class="text-xs text-neutral-500 dark:text-neutral-400">{{ $item->code }}</div>
                            @endif
                        </td>
                        @foreach ($categories as $category)
                            <td class="px-2 py-2 text-center" wire:key="item-{{ $item->id }}-cat-{{ $category->id }}">
                                <input
                                    type="checkbox"
                                    class="h-4 w-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-500 cursor-pointer"
                                    @checked(($assignments[$item->id] ?? null) === $category->id)
                                    wire:click="toggleCategory({{ $item->id }}, {{ $category->id }})"
                                />
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $categories->count() + 1 }}" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">
                            {{ __('No menu items found.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Fixed footer area --}}
    <div class="shrink-0 flex items-center justify-between py-4">
        <div>{{ $items->links() }}</div>
        <flux:button wire:click="save" variant="primary">
            {{ __('Save Changes') }}
        </flux:button>
    </div>
</div>
