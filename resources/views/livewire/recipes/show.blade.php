<?php

use App\Models\Recipe;
use App\Services\Recipes\RecipeShowQueryService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public Recipe $recipe;

    public function with(RecipeShowQueryService $queryService): array
    {
        return $queryService->showData($this->recipe);
    }

}; ?>

<div class="w-full max-w-5xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Recipes') }}</p>
            <h1 class="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ $recipe->name }}</h1>
        </div>
        <div class="flex gap-2">
            <flux:button :href="route('recipes.edit', $recipe)" wire:navigate>{{ __('Edit') }}</flux:button>
            <flux:button :href="route('recipes.index')" wire:navigate variant="ghost">{{ __('Back') }}</flux:button>
        </div>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-2">
        <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
            <div>
                <p class="text-xs uppercase tracking-wide text-neutral-500 dark:text-neutral-400">{{ __('Category') }}</p>
                <p class="text-sm text-neutral-900 dark:text-neutral-100">{{ $recipe->category?->name ?? '—' }}</p>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wide text-neutral-500 dark:text-neutral-400">{{ __('Yield') }}</p>
                <p class="text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $recipe->yield_quantity, 3) }} {{ $recipe->yield_unit }}</p>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wide text-neutral-500 dark:text-neutral-400">{{ __('Overhead %') }}</p>
                <p class="text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $recipe->overhead_pct, 4) }}</p>
            </div>
        </div>
        <div>
            <p class="text-xs uppercase tracking-wide text-neutral-500 dark:text-neutral-400">{{ __('Description') }}</p>
            <p class="text-sm text-neutral-800 dark:text-neutral-200">{{ $recipe->description ?: '—' }}</p>
        </div>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
        <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Ingredients') }}</h2>
        <div class="overflow-x-auto">
            <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                    <tr>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Item') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Quantity') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Unit') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Qty Type') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Cost Type') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    @forelse ($items as $item)
                        <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                            <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">
                                {{ $item->inventoryItem?->name ?? '—' }}
                            </td>
                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                                {{ number_format((float) $item->quantity, 3) }}
                            </td>
                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                                {{ $item->unit }}
                            </td>
                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                                {{ ucfirst($item->quantity_type) }}
                            </td>
                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                                {{ ucfirst($item->cost_type) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">
                                {{ __('No ingredients added yet.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
        <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Costing') }}</h2>
        <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
            <div>
                <p class="text-xs uppercase tracking-wide text-neutral-500 dark:text-neutral-400">{{ __('Base Cost') }}</p>
                <p class="text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $costing['base_cost_total'], 2) }}</p>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wide text-neutral-500 dark:text-neutral-400">{{ __('Overhead') }}</p>
                <p class="text-sm text-neutral-900 dark:text-neutral-100">
                    {{ number_format((float) $costing['overhead_amount'], 2) }}
                    ({{ number_format((float) $costing['overhead_rate'] * 100, 2) }}%)
                </p>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wide text-neutral-500 dark:text-neutral-400">{{ __('Total Cost') }}</p>
                <p class="text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $costing['total_cost_with_overhead'], 2) }}</p>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wide text-neutral-500 dark:text-neutral-400">{{ __('Cost / Yield Unit') }}</p>
                <p class="text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $costing['cost_per_yield_unit'], 4) }}</p>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wide text-neutral-500 dark:text-neutral-400">{{ __('Selling / Unit') }}</p>
                <p class="text-sm text-neutral-900 dark:text-neutral-100">
                    {{ $costing['selling_price_per_unit'] !== null ? number_format((float) $costing['selling_price_per_unit'], 2) : '—' }}
                </p>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wide text-neutral-500 dark:text-neutral-400">{{ __('Margin / Unit') }}</p>
                <p class="text-sm text-neutral-900 dark:text-neutral-100">
                    @if ($costing['margin_amount_per_unit'] !== null)
                        {{ number_format((float) $costing['margin_amount_per_unit'], 2) }}
                        ({{ number_format((float) $costing['margin_pct'] * 100, 2) }}%)
                    @else
                        —
                    @endif
                </p>
            </div>
        </div>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
        <div class="flex items-center justify-between">
            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Productions') }}</h2>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                    <tr>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Date') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Quantity') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Reference') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Notes') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Created By') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    @forelse ($productions as $prod)
                        <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                            <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">
                                {{ $prod->production_date?->format('Y-m-d H:i') }}
                            </td>
                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                                {{ number_format((float) $prod->produced_quantity, 3) }}
                            </td>
                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                                {{ $prod->reference ?? '—' }}
                            </td>
                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                                {{ $prod->notes ?? '—' }}
                            </td>
                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                                {{ $prod->creator?->username ?? $prod->creator?->email ?? '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">
                                {{ __('No productions recorded yet.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

