<?php

use App\Support\Reports\ReportRegistry;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    #[Url(as: 'category')]
    public ?string $selectedCategory = null;

    public function selectCategory(string $category): void
    {
        $this->selectedCategory = $category;
    }

    public function backToCategories(): void
    {
        $this->selectedCategory = null;
    }

    public function with(): array
    {
        $categories = ReportRegistry::categories();
        $reports = $this->selectedCategory
            ? ReportRegistry::allInCategory($this->selectedCategory)
            : collect();
        $categoryLabel = $this->selectedCategory
            ? $categories->firstWhere('key', $this->selectedCategory)['label'] ?? $this->selectedCategory
            : null;

        return [
            'categories' => $categories,
            'reports' => $reports,
            'categoryLabel' => $categoryLabel,
        ];
    }
}; ?>

<div class="w-full max-w-4xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Reports') }}</h1>
        @if ($selectedCategory)
            <flux:button wire:click="backToCategories" variant="ghost" class="inline-flex items-center gap-2">
                <flux:icon name="arrow-left" class="size-4" />
                {{ __('Back to categories') }}
            </flux:button>
        @endif
    </div>

    @if (! $selectedCategory)
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($categories as $category)
                <button
                    type="button"
                    wire:click="selectCategory('{{ $category['key'] }}')"
                    class="flex flex-col items-center justify-center rounded-xl border-2 border-neutral-200 bg-white p-6 text-left shadow-sm transition-all hover:border-primary-500 hover:bg-primary-50/50 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-900 dark:hover:border-primary-500 dark:hover:bg-primary-900/20"
                >
                    <flux:icon name="document-text" class="size-10 text-neutral-400 dark:text-neutral-500" />
                    <span class="mt-3 text-base font-semibold text-neutral-900 dark:text-neutral-100">{{ __($category['label']) }}</span>
                    <span class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">{{ __('View reports') }}</span>
                </button>
            @endforeach
        </div>
    @else
        <div class="rounded-lg border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="border-b border-neutral-200 px-4 py-3 dark:border-neutral-800">
                <h2 class="text-sm font-medium text-neutral-700 dark:text-neutral-300">{{ __($categoryLabel) }} – {{ __('Reports') }}</h2>
            </div>
            <div class="divide-y divide-neutral-200 dark:divide-neutral-800">
                @forelse ($reports as $report)
                    @php
                        $routeName = $report['route'] ?? null;
                    @endphp
                    @if ($routeName && Route::has($routeName))
                        <a href="{{ route($routeName) }}" wire:navigate class="flex items-center justify-between px-4 py-4 text-left hover:bg-neutral-50 dark:hover:bg-neutral-800/70 transition-colors">
                            <div>
                                <span class="font-medium text-neutral-900 dark:text-neutral-100">{{ __($report['label']) }}</span>
                                <p class="mt-0.5 text-sm text-neutral-500 dark:text-neutral-400">
                                    {{ __('Filters') }}: {{ implode(', ', $report['filters'] ?? []) }} · {{ __('Outputs') }}: {{ implode(', ', $report['outputs'] ?? []) }}
                                </p>
                            </div>
                            <flux:icon name="chevron-right" class="size-5 text-neutral-400" />
                        </a>
                    @else
                        <div class="px-4 py-4 text-sm text-neutral-500 dark:text-neutral-400">
                            {{ __($report['label']) }} ({{ __('route not registered') }})
                        </div>
                    @endif
                @empty
                    <div class="px-4 py-8 text-center text-sm text-neutral-500 dark:text-neutral-400">
                        {{ __('No reports in this category.') }}
                    </div>
                @endforelse
            </div>
        </div>
    @endif
</div>
