<?php

use App\Models\MenuItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public int $branchOneId = 1;
    public int $branchTwoId = 2;
    public string $search = '';
    public string $sortDirection = 'asc';
    public int $perPage = 25;

    protected $paginationTheme = 'tailwind';

    public function mount(): void
    {
        if (! Schema::hasTable('menu_item_branches') || ! Schema::hasTable('menu_items')) {
            return;
        }

        $items = DB::table('menu_items')->pluck('id')->all();
        if (empty($items)) {
            return;
        }

        $now = now();
        $rows = array_map(fn ($id) => [
            'menu_item_id' => $id,
            'branch_id' => $this->branchOneId,
            'created_at' => $now,
            'updated_at' => $now,
        ], $items);

        DB::table('menu_item_branches')->insertOrIgnore($rows);
    }

    public function with(): array
    {
        $items = MenuItem::query()
            ->when($this->search !== '', fn ($q) => $q->where('name', 'like', '%'.$this->search.'%'))
            ->orderBy('name', $this->sortDirection === 'desc' ? 'desc' : 'asc')
            ->paginate($this->perPage, ['id', 'name']);

        $availability = [];
        if (Schema::hasTable('menu_item_branches')) {
            $pageIds = $items->getCollection()->pluck('id')->all();
            $rows = DB::table('menu_item_branches')
                ->whereIn('branch_id', [$this->branchOneId, $this->branchTwoId])
                ->when(! empty($pageIds), fn ($q) => $q->whereIn('menu_item_id', $pageIds))
                ->get(['menu_item_id', 'branch_id']);

            foreach ($rows as $row) {
                $availability[$row->menu_item_id][$row->branch_id] = true;
            }
        }

        $branches = Schema::hasTable('branches')
            ? DB::table('branches')->whereIn('id', [$this->branchOneId, $this->branchTwoId])->orderBy('id')->get()
            : collect();

        $branchLabels = [
            $this->branchOneId => $branches->firstWhere('id', $this->branchOneId)?->name ?? __('Branch 1'),
            $this->branchTwoId => $branches->firstWhere('id', $this->branchTwoId)?->name ?? __('Branch 2'),
        ];

        return [
            'items' => $items,
            'availability' => $availability,
            'branchLabels' => $branchLabels,
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

    public function sortByName(): void
    {
        $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        $this->resetPage();
    }

    public function toggleAvailability(int $itemId, int $branchId, bool $checked): void
    {
        if (! Schema::hasTable('menu_item_branches')) {
            return;
        }

        if ($checked) {
            DB::table('menu_item_branches')->updateOrInsert(
                ['menu_item_id' => $itemId, 'branch_id' => $branchId],
                ['created_at' => now(), 'updated_at' => now()]
            );
        } else {
            DB::table('menu_item_branches')
                ->where('menu_item_id', $itemId)
                ->where('branch_id', $branchId)
                ->delete();
        }
    }
}; ?>

<div class="w-full max-w-6xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
            {{ __('Menu Item Branch Availability') }}
        </h1>
        <flux:button :href="route('menu-items.index')" wire:navigate variant="ghost">
            {{ __('Back') }}
        </flux:button>
    </div>

    <!-- <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <p class="text-sm text-neutral-600 dark:text-neutral-300">
            {{ __('All menu items are marked available in Branch 1 by default.') }}
        </p>
    </div> -->

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="flex flex-wrap items-center gap-3">
            <flux:input
                wire:model.live.debounce.300ms="search"
                placeholder="{{ __('Search menu items') }}"
                class="w-full md:w-64"
            />
            <div class="flex items-center gap-2">
                <label for="per_page" class="text-sm text-neutral-800 dark:text-neutral-200">{{ __('Per page') }}</label>
                <select id="per_page" wire:model.live="perPage" class="rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="10">10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
        </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <table class="w-full min-w-full table-fixed divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                        <button type="button" wire:click="sortByName" class="inline-flex items-center gap-1">
                            <span>{{ __('Menu Item') }}</span>
                            <span class="text-[10px]">
                                {{ $sortDirection === 'asc' ? '▲' : '▼' }}
                            </span>
                        </button>
                    </th>
                    <th class="px-3 py-3 text-center text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                        {{ $branchLabels[$branchOneId] ?? __('Branch 1') }}
                    </th>
                    <th class="px-3 py-3 text-center text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                        {{ $branchLabels[$branchTwoId] ?? __('Branch 2') }}
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 bg-white dark:divide-neutral-800 dark:bg-neutral-900">
                @forelse ($items as $item)
                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                        <td class="px-3 py-3 text-sm text-neutral-900 dark:text-neutral-100">
                            {{ $item->name }}
                        </td>
                        <td class="px-3 py-3 text-center">
                            <input
                                type="checkbox"
                                class="h-4 w-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
                                @checked($availability[$item->id][$branchOneId] ?? false)
                                wire:change="toggleAvailability({{ $item->id }}, {{ $branchOneId }}, $event.target.checked)"
                            />
                        </td>
                        <td class="px-3 py-3 text-center">
                            <input
                                type="checkbox"
                                class="h-4 w-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
                                @checked($availability[$item->id][$branchTwoId] ?? false)
                                wire:change="toggleAvailability({{ $item->id }}, {{ $branchTwoId }}, $event.target.checked)"
                            />
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">
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
