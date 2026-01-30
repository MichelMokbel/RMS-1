<?php

use App\Support\Reports\ReportRegistry;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public function with(): array
    {
        return [
            'reports' => ReportRegistry::all(),
        ];
    }
}; ?>

<div class="w-full max-w-4xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Reports') }}</h1>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="divide-y divide-neutral-200 dark:divide-neutral-800">
            @foreach ($reports as $report)
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
                @endif
            @endforeach
        </div>
    </div>
</div>
