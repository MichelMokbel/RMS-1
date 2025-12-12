<?php

use App\Models\Category;
use App\Models\MenuItem;
use App\Services\Menu\MenuItemUsageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $status = 'active';
    public ?int $category_id = null;

    protected $paginationTheme = 'tailwind';

    public function updatingSearch(): void { $this->resetPage(); }
    public function updatingStatus(): void { $this->resetPage(); }
    public function updatingCategoryId(): void { $this->resetPage(); }

    public function with(): array
    {
        return [
            'items' => $this->query()->paginate(15),
            'categories' => Schema::hasTable('categories') ? Category::orderBy('name')->get() : collect(),
        ];
    }

    private function query()
    {
        return MenuItem::query()
            ->search($this->search)
            ->when($this->status !== 'all', fn ($q) => $q->where('is_active', $this->status === 'active'))
            ->when($this->category_id, fn ($q) => $q->where('category_id', $this->category_id))
            ->ordered();
    }

    public function toggleStatus(int $id): void
    {
        $usage = app(MenuItemUsageService::class);
        $item = MenuItem::findOrFail($id);
        if (! $item->is_active && $this->isUsed($id, $usage)) {
            // activating is fine, only block deactivation
        }
        if ($item->is_active && $this->isUsed($id, $usage)) {
            $this->addError('status', __('Menu item is used in orders and cannot be deactivated.'));
            return;
        }
        $item->update(['is_active' => ! $item->is_active]);
        session()->flash('status', __('Status updated.'));
    }

    private function isUsed(int $id, MenuItemUsageService $usage): bool
    {
        return $usage->isMenuItemUsed($id);
    }

}; ?>

<div class="w-full max-w-7xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
            {{ __('Menu Items') }}
        </h1>
        @if(auth()->user()->hasAnyRole(['admin','manager']))
            <flux:button :href="route('menu-items.create')" wire:navigate variant="primary">
                {{ __('Create Menu Item') }}
            </flux:button>
        @endif
    </div>

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif
    @error('status')
        <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100">
            {{ $message }}
        </div>
    @enderror

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="flex flex-wrap items-center gap-3">
            <flux:input
                wire:model.live.debounce.300ms="search"
                placeholder="{{ __('Search code, name, arabic name') }}"
                class="w-full md:w-64"
            />
            @if ($categories->count())
                <div class="flex items-center gap-2">
                    <label for="category_id" class="text-sm text-neutral-800 dark:text-neutral-200">{{ __('Category') }}</label>
                    <select id="category_id" wire:model.live="category_id" class="rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="">{{ __('All') }}</option>
                        @foreach ($categories as $cat)
                            <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div class="flex items-center gap-2">
                <label for="status" class="text-sm text-neutral-800 dark:text-neutral-200">{{ __('Status') }}</label>
                <select id="status" wire:model.live="status" class="rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="active">{{ __('Active') }}</option>
                    <option value="inactive">{{ __('Inactive') }}</option>
                    <option value="all">{{ __('All') }}</option>
                </select>
            </div>
        </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Code') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Name') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Arabic Name') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Category') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Price') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Active') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 bg-white dark:divide-neutral-800 dark:bg-neutral-900">
                @forelse ($items as $item)
                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                        <td class="px-3 py-3 text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ $item->code }}</td>
                        <td class="px-3 py-3 text-sm text-neutral-900 dark:text-neutral-100">{{ $item->name }}</td>
                        <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ $item->arabic_name }}</td>
                        <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ $item->category?->name }}</td>
                        <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ number_format((float) $item->selling_price_per_unit, 3) }}</td>
                        <td class="px-3 py-3 text-sm">
                            <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold {{ $item->is_active ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-100' : 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-100' }}">
                                {{ $item->is_active ? __('Active') : __('Inactive') }}
                            </span>
                        </td>
                        <td class="px-3 py-3 text-sm">
                            <div class="flex flex-wrap gap-2">
                                @if(auth()->user()->hasAnyRole(['admin','manager']))
                                    <flux:button size="xs" :href="route('menu-items.edit', $item)" wire:navigate>{{ __('Edit') }}</flux:button>
                                @else
                                    <span class="text-xs text-neutral-500">{{ __('View only') }}</span>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">
                            {{ __('No menu items found.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>
        {{ $items->links() }}
    </div>
</div>
