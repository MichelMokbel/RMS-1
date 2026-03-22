<?php

use App\Services\Help\HelpSearchService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'module')]
    public string $module = 'all';

    public function with(HelpSearchService $search): array
    {
        $user = auth()->user();

        return [
            'articles' => $search->searchArticles(
                $user,
                $this->search,
                config('help.default_locale'),
                $this->module === 'all' ? null : $this->module,
            ),
            'faqs' => $search->visibleFaqs($user, config('help.default_locale'))->take(12),
            'modules' => config('help.modules', []),
        ];
    }
}; ?>

<div class="app-page space-y-8">
    <section class="rounded-[2rem] border border-neutral-200 bg-gradient-to-br from-amber-50 via-white to-lime-50 p-6 shadow-sm dark:border-neutral-700 dark:from-neutral-900 dark:via-neutral-950 dark:to-neutral-900">
        <div class="max-w-3xl space-y-4">
            <div class="inline-flex items-center rounded-full bg-neutral-950 px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-white dark:bg-white dark:text-neutral-950">
                Help Center
            </div>
            <div>
                <h1 class="text-3xl font-semibold tracking-tight text-neutral-950 dark:text-white">Guided help for daily Layla Kitchen tasks</h1>
                <p class="mt-3 max-w-2xl text-sm leading-6 text-neutral-600 dark:text-neutral-300">
                    Search task-focused guides, FAQs, and screenshots. The Help Bot only answers from the approved guides you can already access.
                </p>
            </div>
        </div>
    </section>

    <section class="rounded-3xl border border-neutral-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="app-filter-grid">
            <div>
                <flux:input wire:model.live.debounce.300ms="search" :label="__('Search help')" placeholder="{{ __('Search by task, module, or keyword') }}" />
            </div>
            <div>
                <label for="help-module" class="mb-2 block text-sm font-medium text-neutral-700 dark:text-neutral-300">{{ __('Module') }}</label>
                <select
                    id="help-module"
                    wire:model.live="module"
                    class="w-full rounded-xl border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-neutral-400 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-100"
                >
                    <option value="all">{{ __('All modules') }}</option>
                    @foreach ($modules as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </section>

    <section class="space-y-4">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Guides') }}</h2>
                <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ $articles->count() }} {{ __('matching guides') }}</p>
            </div>
            @if (auth()->user()?->hasAnyRole(['admin', 'manager']) || auth()->user()?->can('help.manage'))
                <flux:button :href="route('help.manage')" wire:navigate variant="ghost">{{ __('Manage Help') }}</flux:button>
            @endif
        </div>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @forelse ($articles as $article)
                <a href="{{ route('help.show', $article) }}" wire:navigate class="group rounded-3xl border border-neutral-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:border-neutral-300 hover:shadow-md dark:border-neutral-700 dark:bg-neutral-900 dark:hover:border-neutral-600">
                    <div class="flex items-start justify-between gap-3">
                        <span class="inline-flex rounded-full bg-neutral-100 px-3 py-1 text-xs font-semibold uppercase tracking-[0.18em] text-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                            {{ config('help.modules.'.$article->module, ucfirst($article->module)) }}
                        </span>
                        <span class="text-xs text-neutral-400">{{ $article->steps->count() }} {{ __('steps') }}</span>
                    </div>
                    <h3 class="mt-4 text-lg font-semibold text-neutral-900 transition group-hover:text-neutral-700 dark:text-neutral-100 dark:group-hover:text-white">
                        {{ $article->title }}
                    </h3>
                    <p class="mt-3 text-sm leading-6 text-neutral-600 dark:text-neutral-300">
                        {{ $article->summary }}
                    </p>
                    <div class="mt-5 flex items-center justify-between text-sm font-medium text-neutral-700 dark:text-neutral-200">
                        <span>{{ __('Open guide') }}</span>
                        <span aria-hidden="true">→</span>
                    </div>
                </a>
            @empty
                <div class="col-span-full rounded-3xl border border-dashed border-neutral-300 bg-neutral-50 p-8 text-center text-sm text-neutral-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-400">
                    {{ __('No guides match the current filters.') }}
                </div>
            @endforelse
        </div>
    </section>

    <section class="space-y-4">
        <div>
            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Frequently Asked Questions') }}</h2>
            <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ __('Quick answers pulled from the approved guides.') }}</p>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            @foreach ($faqs as $faq)
                <article class="rounded-3xl border border-neutral-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="flex items-start justify-between gap-3">
                        <h3 class="text-base font-semibold text-neutral-900 dark:text-neutral-100">{{ $faq->question }}</h3>
                        @if ($faq->article)
                            <a href="{{ route('help.show', $faq->article) }}" wire:navigate class="text-xs font-medium text-neutral-500 hover:text-neutral-800 dark:text-neutral-400 dark:hover:text-neutral-100">
                                {{ __('Guide') }}
                            </a>
                        @endif
                    </div>
                    <div class="help-markdown mt-3">{!! app(\App\Support\Help\MarkdownRenderer::class)->render($faq->answer_markdown) !!}</div>
                </article>
            @endforeach
        </div>
    </section>
</div>
