@php
    $articleSlug = request()->routeIs('help.show') ? request()->route('article')?->slug : null;
@endphp

<div
    x-data="helpBotWidget({
        endpoint: '{{ route('help.bot.respond') }}',
        csrf: '{{ csrf_token() }}',
        articleSlug: @js($articleSlug),
    })"
    class="fixed bottom-5 right-5 z-50"
    x-cloak
>
    <button
        type="button"
        class="help-bot-shadow inline-flex items-center gap-2 rounded-full bg-neutral-950 px-4 py-3 text-sm font-semibold text-white transition hover:bg-neutral-800 dark:bg-white dark:text-neutral-950 dark:hover:bg-neutral-200"
        @click="open = true"
    >
        <span>Help Bot</span>
    </button>

    <div
        x-show="open"
        class="help-bot-shadow fixed inset-y-4 right-4 z-50 flex w-[min(28rem,calc(100vw-2rem))] flex-col overflow-hidden rounded-3xl border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-950"
        x-transition.opacity
    >
        <div class="flex items-center justify-between border-b border-neutral-200 px-4 py-3 dark:border-neutral-800">
            <div>
                <p class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">Help Bot</p>
                <p class="text-xs text-neutral-500 dark:text-neutral-400">Grounded only in approved Help Center guides</p>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" class="text-xs text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-100" @click="reset()">New chat</button>
                <button type="button" class="rounded-full bg-neutral-100 px-2 py-1 text-xs text-neutral-600 hover:bg-neutral-200 dark:bg-neutral-800 dark:text-neutral-300" @click="open = false">Close</button>
            </div>
        </div>

        <div x-ref="viewport" class="flex-1 space-y-3 overflow-y-auto bg-neutral-50/70 px-4 py-4 dark:bg-neutral-900">
            <template x-for="(entry, index) in messages" :key="index">
                <div class="space-y-2">
                    <div
                        class="max-w-[90%] rounded-2xl px-4 py-3 text-sm leading-6"
                        :class="entry.role === 'user'
                            ? 'ml-auto bg-neutral-950 text-white dark:bg-white dark:text-neutral-950'
                            : 'bg-white text-neutral-800 ring-1 ring-neutral-200 dark:bg-neutral-950 dark:text-neutral-100 dark:ring-neutral-800'"
                    >
                        <div class="whitespace-pre-wrap" x-text="entry.content"></div>
                    </div>

                    <template x-if="entry.citations && entry.citations.length">
                        <div class="flex flex-wrap gap-2">
                            <template x-for="citation in entry.citations" :key="citation.article_slug + '-' + (citation.step_id || 'none')">
                                <a
                                    class="inline-flex items-center rounded-full bg-white px-3 py-1 text-xs font-medium text-neutral-700 ring-1 ring-neutral-200 hover:bg-neutral-100 dark:bg-neutral-900 dark:text-neutral-200 dark:ring-neutral-700"
                                    :href="`/help/${citation.article_slug}`"
                                    x-text="citation.article_title"
                                ></a>
                            </template>
                        </div>
                    </template>

                    <template x-if="entry.suggestions && entry.suggestions.length">
                        <div class="space-y-1">
                            <p class="text-xs font-medium uppercase tracking-[0.18em] text-neutral-400">Suggested guides</p>
                            <div class="flex flex-wrap gap-2">
                                <template x-for="suggestion in entry.suggestions" :key="suggestion.slug">
                                    <a
                                        class="inline-flex items-center rounded-full bg-amber-100 px-3 py-1 text-xs font-medium text-amber-900 hover:bg-amber-200 dark:bg-amber-900/30 dark:text-amber-100"
                                        :href="suggestion.url"
                                        x-text="suggestion.title"
                                    ></a>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
            </template>

            <template x-if="loading">
                <div class="rounded-2xl bg-white px-4 py-3 text-sm text-neutral-500 ring-1 ring-neutral-200 dark:bg-neutral-950 dark:text-neutral-400 dark:ring-neutral-800">
                    Searching approved guides...
                </div>
            </template>
        </div>

        <form class="border-t border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-950" @submit.prevent="send()">
            <label class="mb-2 block text-xs font-medium uppercase tracking-[0.18em] text-neutral-400">Ask a workflow question</label>
            <textarea
                x-model="draft"
                rows="3"
                class="w-full rounded-2xl border border-neutral-200 bg-white px-4 py-3 text-sm text-neutral-800 outline-none transition focus:border-neutral-400 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
                placeholder="Example: How do I create a purchase order and receive it?"
            ></textarea>
            <div class="mt-3 flex items-center justify-between">
                <p class="text-xs text-neutral-400">No actions are performed from this chat.</p>
                <button
                    type="submit"
                    class="rounded-full bg-neutral-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-neutral-800 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-white dark:text-neutral-950 dark:hover:bg-neutral-200"
                    :disabled="loading || !draft.trim()"
                >
                    Ask
                </button>
            </div>
        </form>
    </div>
</div>
