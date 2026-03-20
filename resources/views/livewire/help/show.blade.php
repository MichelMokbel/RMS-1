<?php

use App\Models\HelpArticle;
use App\Services\Help\HelpSearchService;
use App\Support\Help\MarkdownRenderer;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public HelpArticle $article;

    public function mount(HelpArticle $article): void
    {
        abort_unless(Auth::check() && $article->isVisibleTo(Auth::user()), 404);

        $this->article = $article->loadMissing(['steps.imageAsset', 'faqs', 'assets']);
    }

    public function with(HelpSearchService $search, MarkdownRenderer $markdown): array
    {
        $steps = $this->article->steps->values();
        $fallbackAssets = [];
        $lastAsset = null;

        foreach ($steps as $index => $step) {
            $asset = $step->imageAsset;
            if ($asset) {
                $lastAsset = $asset;
            }

            $fallbackAssets[$index] = $lastAsset;
        }

        $nextAsset = null;
        for ($index = $steps->count() - 1; $index >= 0; $index--) {
            $asset = $steps[$index]->imageAsset;
            if ($asset) {
                $nextAsset = $asset;
            }

            if (! $fallbackAssets[$index] && $nextAsset) {
                $fallbackAssets[$index] = $nextAsset;
            }
        }

        $heroAsset = $this->article->heroAsset;

        return [
            'renderedBody' => $markdown->render($this->article->body_markdown),
            'renderedSteps' => $steps->map(function ($step, int $index) use ($markdown, $fallbackAssets, $heroAsset) {
                $asset = $step->imageAsset ?: ($fallbackAssets[$index] ?? null) ?: $heroAsset;

                return [
                'id' => $step->id,
                'sort_order' => $step->sort_order,
                'title' => $step->title,
                'body_html' => $markdown->render($step->body_markdown),
                'cta_label' => $step->cta_label,
                'cta_url' => $step->ctaUrl(),
                'asset_url' => $asset?->publicUrl(),
                'asset_alt' => $asset?->alt_text ?: $step->title.' screenshot',
            ];
            })->all(),
            'renderedFaqs' => $this->article->faqs->map(fn ($faq) => [
                'question' => $faq->question,
                'answer_html' => $markdown->render($faq->answer_markdown),
            ])->all(),
            'relatedArticles' => $search->relatedArticles(Auth::user(), $this->article),
        ];
    }
}; ?>

<div
    class="app-page space-y-8"
    x-data="{
        lightboxOpen: false,
        lightboxUrl: null,
        lightboxAlt: '',
        openLightbox(url, alt) {
            this.lightboxUrl = url;
            this.lightboxAlt = alt ?? '';
            this.lightboxOpen = true;
            document.body.classList.add('overflow-hidden');
        },
        closeLightbox() {
            this.lightboxOpen = false;
            document.body.classList.remove('overflow-hidden');
        },
    }"
    x-on:keydown.escape.window="closeLightbox()"
>
    <section class="rounded-[2rem] border border-neutral-200 bg-white p-6 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <a href="{{ route('help.index') }}" wire:navigate class="inline-flex items-center gap-2 text-sm font-medium text-neutral-500 hover:text-neutral-800 dark:text-neutral-400 dark:hover:text-neutral-100">
            <span aria-hidden="true">←</span>
            <span>{{ __('Back to Help Center') }}</span>
        </a>

        <div class="mt-4 flex flex-wrap items-start justify-between gap-4">
            <div class="max-w-3xl">
                <span class="inline-flex rounded-full bg-neutral-100 px-3 py-1 text-xs font-semibold uppercase tracking-[0.18em] text-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                    {{ config('help.modules.'.$article->module, ucfirst($article->module)) }}
                </span>
                <h1 class="mt-4 text-3xl font-semibold tracking-tight text-neutral-950 dark:text-white">{{ $article->title }}</h1>
                <p class="mt-4 text-sm leading-6 text-neutral-600 dark:text-neutral-300">{{ $article->summary }}</p>

                @if (($article->prerequisites ?? []) !== [])
                    <div class="mt-5">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-neutral-400">{{ __('Before you start') }}</p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach ($article->prerequisites as $prerequisite)
                                <span class="inline-flex rounded-full bg-amber-100 px-3 py-1 text-xs font-medium text-amber-900 dark:bg-amber-900/30 dark:text-amber-100">
                                    {{ $prerequisite }}
                                </span>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            @if ($article->targetUrl())
                <flux:button :href="$article->targetUrl()" wire:navigate>{{ __('Open Related Screen') }}</flux:button>
            @endif
        </div>

        @if ($renderedBody)
            <div class="help-markdown mt-6">{!! $renderedBody !!}</div>
        @endif
    </section>

    <section class="space-y-4">
        <div class="flex items-center justify-between">
            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Step-by-step guide') }}</h2>
            <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ count($renderedSteps) }} {{ __('steps') }}</p>
        </div>

        <div class="space-y-5">
            @foreach ($renderedSteps as $step)
                <article class="overflow-hidden rounded-[2rem] border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="grid gap-0 lg:grid-cols-[1.1fr_0.9fr]">
                        <div class="p-6">
                            <div class="flex items-center gap-3">
                                <span class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-neutral-950 text-sm font-semibold text-white dark:bg-white dark:text-neutral-950">
                                    {{ $step['sort_order'] }}
                                </span>
                                <h3 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ $step['title'] }}</h3>
                            </div>
                            <div class="help-markdown mt-4">{!! $step['body_html'] !!}</div>

                            @if ($step['cta_url'])
                                <div class="mt-5">
                                    <flux:button :href="$step['cta_url']" wire:navigate variant="ghost">{{ $step['cta_label'] ?: __('Open screen') }}</flux:button>
                                </div>
                            @endif
                        </div>

                        <div class="border-t border-neutral-200 bg-neutral-50 p-4 dark:border-neutral-800 dark:bg-neutral-950 lg:border-t-0 lg:border-l">
                            @if ($step['asset_url'])
                                <button
                                    type="button"
                                    class="group relative block h-full w-full text-left"
                                    x-on:click="openLightbox(@js($step['asset_url']), @js($step['asset_alt']))"
                                >
                                    <img src="{{ $step['asset_url'] }}" alt="{{ $step['asset_alt'] }}" class="h-full w-full rounded-2xl border border-neutral-200 object-cover shadow-sm transition duration-200 group-hover:scale-[1.01] group-hover:shadow-md dark:border-neutral-800" />
                                    <span class="pointer-events-none absolute inset-x-3 bottom-3 inline-flex items-center justify-center rounded-full bg-neutral-950/85 px-3 py-1 text-xs font-semibold text-white opacity-0 transition group-hover:opacity-100 dark:bg-white/90 dark:text-neutral-950">
                                        {{ __('Click to enlarge') }}
                                    </span>
                                </button>
                            @else
                                <div class="flex h-full min-h-64 items-center justify-center rounded-2xl border border-dashed border-neutral-300 bg-white p-6 text-center text-sm text-neutral-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-400">
                                    {{ __('Screenshot will appear here after running the automated capture command.') }}
                                </div>
                            @endif
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    </section>

    <section class="grid gap-6 lg:grid-cols-[1.15fr_0.85fr]">
        <div class="space-y-4">
            <div>
                <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Frequently Asked Questions') }}</h2>
                <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ __('Quick answers tied to this guide.') }}</p>
            </div>

            <div class="space-y-4">
                @foreach ($renderedFaqs as $faq)
                    <article class="rounded-3xl border border-neutral-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <h3 class="text-base font-semibold text-neutral-900 dark:text-neutral-100">{{ $faq['question'] }}</h3>
                        <div class="help-markdown mt-3">{!! $faq['answer_html'] !!}</div>
                    </article>
                @endforeach
            </div>
        </div>

        <aside class="space-y-4">
            <div>
                <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Related Guides') }}</h2>
                <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ __('Continue with nearby tasks in the same system area.') }}</p>
            </div>

            @foreach ($relatedArticles as $related)
                <a href="{{ route('help.show', $related) }}" wire:navigate class="block rounded-3xl border border-neutral-200 bg-white p-5 shadow-sm transition hover:border-neutral-300 dark:border-neutral-700 dark:bg-neutral-900 dark:hover:border-neutral-600">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-neutral-400">{{ config('help.modules.'.$related->module, ucfirst($related->module)) }}</p>
                    <h3 class="mt-2 text-base font-semibold text-neutral-900 dark:text-neutral-100">{{ $related->title }}</h3>
                    <p class="mt-2 text-sm leading-6 text-neutral-600 dark:text-neutral-300">{{ $related->summary }}</p>
                </a>
            @endforeach
        </aside>
    </section>

    <div
        x-cloak
        x-show="lightboxOpen"
        x-transition.opacity
        class="fixed inset-0 z-50 flex items-center justify-center p-4"
        style="background: rgba(10, 10, 10, 0.88); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px);"
        x-on:click.self="closeLightbox()"
    >
        <div class="relative flex h-full max-h-[calc(100dvh-2rem)] w-full max-w-6xl flex-col gap-3">
            <div
                class="pointer-events-none absolute inset-0 -z-10 rounded-[2rem]"
                style="background: radial-gradient(circle at center, rgba(255,255,255,0.08), transparent 42%);"
            ></div>
            <div class="flex items-center justify-between gap-4 rounded-2xl bg-white/95 px-4 py-3 shadow-lg dark:bg-neutral-900/95">
                <p class="truncate text-sm font-medium text-neutral-700 dark:text-neutral-200" x-text="lightboxAlt"></p>
                <button
                    type="button"
                    class="inline-flex h-10 w-10 items-center justify-center rounded-full border border-neutral-200 text-neutral-600 transition hover:border-neutral-300 hover:text-neutral-900 dark:border-neutral-700 dark:text-neutral-300 dark:hover:border-neutral-500 dark:hover:text-white"
                    x-on:click="closeLightbox()"
                    aria-label="{{ __('Close enlarged screenshot') }}"
                >
                    <span aria-hidden="true">×</span>
                </button>
            </div>

            <div class="flex min-h-0 flex-1 items-center justify-center overflow-hidden rounded-[2rem] bg-white/95 p-4 shadow-2xl dark:bg-neutral-900/95">
                <img
                    x-bind:src="lightboxUrl"
                    x-bind:alt="lightboxAlt"
                    class="block h-auto w-auto max-w-full rounded-2xl object-contain shadow-sm"
                    style="max-width: min(100%, calc(100vw - 6rem)); max-height: calc(100dvh - 9rem);"
                />
            </div>
        </div>
    </div>
</div>
