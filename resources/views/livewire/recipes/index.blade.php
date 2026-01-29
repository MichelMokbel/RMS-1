<?php

use App\Services\Recipes\RecipeProductionService;
use App\Services\Recipes\RecipeFormQueryService;
use App\Services\Recipes\RecipeIndexQueryService;
use App\Services\Recipes\RecipeProductionQueryService;
use App\Support\Recipes\RecipeProduceRules;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Auth;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public ?int $category_id = null;
    public bool $showProduceModal = false;
    public ?int $produce_recipe_id = null;
    public ?float $produce_quantity = null;
    public ?string $produce_date = null;
    public ?string $produce_reference = null;
    public ?string $produce_notes = null;

    protected $paginationTheme = 'tailwind';

    public function updating($field): void
    {
        if (in_array($field, ['search', 'category_id'], true)) {
            $this->resetPage();
        }
    }

    public function openProduce(int $recipeId): void
    {
        $this->produce_recipe_id = $recipeId;
        $this->produce_quantity = null;
        $this->produce_date = now()->format('Y-m-d\TH:i');
        $this->produce_reference = null;
        $this->produce_notes = null;
        $this->showProduceModal = true;
    }

    public function produce(
        RecipeProductionService $service,
        RecipeProductionQueryService $recipeQuery,
        RecipeProduceRules $rules
    ): void
    {
        $data = $this->validate($rules->rules());
        $recipe = $recipeQuery->findForProduction((int) $data['produce_recipe_id']);

        try {
            $service->produce($recipe, [
                'produced_quantity' => $data['produce_quantity'],
                'production_date' => $data['produce_date'] ?? now(),
                'reference' => $data['produce_reference'] ?? null,
                'notes' => $data['produce_notes'] ?? null,
                'strict_stock_check' => true,
            ], Auth::id());

            $this->showProduceModal = false;
            $this->reset(['produce_recipe_id', 'produce_quantity', 'produce_date', 'produce_reference', 'produce_notes']);
            session()->flash('status', __('Production recorded and stock updated.'));
        } catch (ValidationException $e) {
            $errors = collect($e->errors())->flatten();
            $message = $errors->first() ?? __('Unable to produce recipe.');
            session()->flash('status', $message);
        }
    }

    public function with(RecipeIndexQueryService $queryService, RecipeFormQueryService $formQuery): array
    {
        return [
            'recipes' => $queryService->paginate($this->search, $this->category_id, 15),
            'categories' => $formQuery->categories(),
        ];
    }
}; ?>

<div class="w-full max-w-7xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
            {{ __('Recipes') }}
        </h1>
        <div class="flex gap-2">
            <flux:button :href="route('recipes.create')" wire:navigate variant="primary">
                {{ __('New Recipe') }}
            </flux:button>
        </div>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
        <div class="flex flex-wrap items-end gap-3">
            <div class="flex-1 min-w-[220px]">
                <flux:input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search recipes') }}" />
            </div>
            <div class="flex-1 min-w-[200px]">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Category') }}</label>
                <select wire:model.live="category_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="">{{ __('All') }}</option>
                    @foreach($categories as $cat)
                        <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                        {{ __('Name') }}
                    </th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                        {{ __('Category') }}
                    </th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                        {{ __('Yield') }}
                    </th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                        {{ __('Overhead %') }}
                    </th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                        {{ __('Selling / Unit') }}
                    </th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                        {{ __('Actions') }}
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                @forelse ($recipes as $recipe)
                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">
                            {{ $recipe->name }}
                        </td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                            {{ $recipe->category?->name ?? '—' }}
                        </td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                            {{ number_format((float) $recipe->yield_quantity, 3) }} {{ $recipe->yield_unit }}
                        </td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">
                            {{ number_format((float) $recipe->overhead_pct, 4) }}
                        </td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">
                            {{ $recipe->selling_price_per_unit !== null ? number_format((float) $recipe->selling_price_per_unit, 2) : '—' }}
                        </td>
                        <td class="px-3 py-2 text-sm text-right">
                            <div class="flex flex-wrap justify-end gap-2">
                                <flux:button size="xs" :href="route('recipes.show', $recipe)" wire:navigate>{{ __('View') }}</flux:button>

                                <flux:button size="xs" type="button" wire:click="openProduce({{ $recipe->id }})">
                                    {{ __('Produce') }}
                                </flux:button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">
                            {{ __('No recipes found.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>
        {{ $recipes->links() }}
    </div>

    {{-- Produce modal --}}
    @if ($showProduceModal)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
            <div class="w-full max-w-2xl rounded-lg border border-neutral-200 bg-white p-4 shadow-lg dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Produce Recipe') }}</h3>
                    <flux:button type="button" wire:click="$set('showProduceModal', false)" variant="ghost">{{ __('Close') }}</flux:button>
                </div>

                @if($produce_recipe_id)
                    @php $modalRecipe = $recipes->firstWhere('id', $produce_recipe_id); @endphp
                    <p class="text-sm text-neutral-700 dark:text-neutral-200 mb-2">{{ $modalRecipe?->name }}</p>
                @endif

                <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                    <flux:input wire:model="produce_quantity" type="number" step="0.001" min="0.001" :label="__('Produced Quantity')" />
                    <flux:input wire:model="produce_date" type="datetime-local" :label="__('Production Date')" />
                    <flux:input wire:model="produce_reference" :label="__('Reference')" />
                    <flux:input wire:model="produce_notes" :label="__('Notes')" />
                </div>

                <div class="mt-4 flex justify-end gap-2">
                    <flux:button type="button" wire:click="$set('showProduceModal', false)" variant="ghost">{{ __('Cancel') }}</flux:button>
                    <flux:button type="button" wire:click="produce" variant="primary">{{ __('Produce') }}</flux:button>
                </div>
            </div>
        </div>
    @endif
</div>

