<?php

use App\Models\Recipe;
use App\Services\Recipes\RecipeCostingService;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public ?int $category_id = null;
    public string $search = '';

    protected $paginationTheme = 'tailwind';

    public function updating($name): void
    {
        if (in_array($name, ['category_id', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function with(RecipeCostingService $costingService): array
    {
        $recipes = $this->query()->paginate(15);
        $costingByRecipe = [];
        foreach ($recipes->getCollection() as $recipe) {
            try {
                $costingByRecipe[$recipe->id] = $costingService->compute($recipe);
            } catch (\Throwable $e) {
                $costingByRecipe[$recipe->id] = null;
            }
        }

        $categories = Schema::hasTable('categories')
            ? \Illuminate\Support\Facades\DB::table('categories')->orderBy('name')->get()
            : collect();

        return [
            'recipes' => $recipes,
            'costingByRecipe' => $costingByRecipe,
            'categories' => $categories,
            'exportParams' => $this->exportParams(),
        ];
    }

    private function query()
    {
        return Recipe::query()
            ->when($this->category_id, fn ($q) => $q->where('category_id', $this->category_id))
            ->when($this->search, fn ($q) => $q->where('name', 'like', '%'.$this->search.'%'))
            ->orderBy('name');
    }

    public function exportParams(): array
    {
        return array_filter([
            'category_id' => $this->category_id,
            'search' => $this->search ?: null,
        ], fn ($v) => $v !== null && $v !== '');
    }
}; ?>

<div class="w-full max-w-7xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Costing Report') }}</h1>
        <div class="flex gap-2">
            <flux:button :href="route('reports.index')" wire:navigate variant="ghost">{{ __('Back to Reports') }}</flux:button>
            <flux:button href="{{ route('reports.costing.print') . '?' . http_build_query($exportParams) }}" target="_blank" variant="ghost">{{ __('Print') }}</flux:button>
            <flux:button href="{{ route('reports.costing.csv') . '?' . http_build_query($exportParams) }}" variant="ghost">{{ __('Export CSV') }}</flux:button>
            <flux:button href="{{ route('reports.costing.pdf') . '?' . http_build_query($exportParams) }}" variant="ghost">{{ __('Export PDF') }}</flux:button>
        </div>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="flex flex-wrap items-end gap-3">
            <div class="min-w-[200px]">
                <flux:input wire:model.live.debounce.300ms="search" :label="__('Search recipe')" placeholder="{{ __('Recipe name') }}" />
            </div>
            @if ($categories->count())
                <div class="min-w-[200px]">
                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Category') }}</label>
                    <select wire:model.live="category_id" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="">{{ __('All') }}</option>
                        @foreach ($categories as $c)
                            <option value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
        </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Recipe') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Base Cost') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Total Cost') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Cost/Unit') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Selling Price') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Margin %') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                @forelse ($recipes as $recipe)
                    @php $c = $costingByRecipe[$recipe->id] ?? null; @endphp
                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $recipe->name }}</td>
                        @if ($c)
                            <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ number_format($c['base_cost_total'], 3) }}</td>
                            <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ number_format($c['total_cost_with_overhead'], 3) }}</td>
                            <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ number_format($c['cost_per_yield_unit_display'], 3) }}</td>
                            <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ $c['selling_price_per_unit'] !== null ? number_format($c['selling_price_per_unit'], 3) : '—' }}</td>
                            <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ $c['margin_pct'] !== null ? number_format($c['margin_pct'] * 100, 1).'%' : '—' }}</td>
                        @else
                            <td colspan="5" class="px-3 py-2 text-sm text-neutral-500 dark:text-neutral-400">{{ __('—') }}</td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No recipes found.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div>{{ $recipes->links() }}</div>
</div>
