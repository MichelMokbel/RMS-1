<?php

use App\Models\HelpArticle;
use App\Support\Help\MarkdownRenderer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

new #[Layout('components.layouts.app')] class extends Component {
    public ?int $articleId = null;
    public string $title = '';
    public string $slug = '';
    public string $module = 'general';
    public string $summary = '';
    public string $body_markdown = '';
    public string $keywords = '';
    public string $target_route = '';
    public string $locale = 'en';
    public string $status = 'draft';
    public string $visibility_mode = 'all';
    public array $prerequisites = [''];
    public array $allowed_roles = [];
    public array $allowed_permissions = [];
    public array $steps = [];
    public array $faqs = [];

    public function mount(): void
    {
        $this->newArticle();

        $first = HelpArticle::query()->orderBy('sort_order')->orderBy('title')->first();
        if ($first) {
            $this->loadArticle($first->id);
        }
    }

    public function with(MarkdownRenderer $markdown): array
    {
        return [
            'articles' => HelpArticle::query()->orderBy('sort_order')->orderBy('title')->get(),
            'modules' => config('help.modules', []),
            'roles' => Role::query()->where('guard_name', 'web')->orderBy('name')->pluck('name'),
            'permissions' => Permission::query()->where('guard_name', 'web')->orderBy('name')->pluck('name'),
            'previewBody' => $markdown->render($this->body_markdown),
        ];
    }

    public function newArticle(): void
    {
        $this->articleId = null;
        $this->title = '';
        $this->slug = '';
        $this->module = 'general';
        $this->summary = '';
        $this->body_markdown = '';
        $this->keywords = '';
        $this->target_route = '';
        $this->locale = 'en';
        $this->status = 'draft';
        $this->visibility_mode = 'all';
        $this->prerequisites = [''];
        $this->allowed_roles = [];
        $this->allowed_permissions = [];
        $this->steps = [
            ['title' => '', 'body_markdown' => '', 'image_key' => '', 'cta_label' => '', 'cta_route' => ''],
        ];
        $this->faqs = [
            ['question' => '', 'answer_markdown' => ''],
        ];
    }

    public function loadArticle(int $id): void
    {
        $article = HelpArticle::query()->with(['steps', 'faqs'])->findOrFail($id);

        $this->articleId = $article->id;
        $this->title = $article->title;
        $this->slug = $article->slug;
        $this->module = $article->module;
        $this->summary = (string) $article->summary;
        $this->body_markdown = (string) $article->body_markdown;
        $this->keywords = implode(', ', $article->keywords ?? []);
        $this->target_route = (string) $article->target_route;
        $this->locale = (string) $article->locale;
        $this->status = (string) $article->status;
        $this->visibility_mode = (string) $article->visibility_mode;
        $this->prerequisites = $article->prerequisites ?: [''];
        $this->allowed_roles = $article->allowed_roles ?: [];
        $this->allowed_permissions = $article->allowed_permissions ?: [];
        $this->steps = $article->steps->map(fn ($step) => [
            'title' => $step->title,
            'body_markdown' => (string) $step->body_markdown,
            'image_key' => (string) $step->image_key,
            'cta_label' => (string) $step->cta_label,
            'cta_route' => (string) $step->cta_route,
        ])->all();
        $this->faqs = $article->faqs->map(fn ($faq) => [
            'question' => $faq->question,
            'answer_markdown' => $faq->answer_markdown,
        ])->all();
    }

    public function updatedTitle(string $value): void
    {
        if (! $this->articleId || $this->slug === '') {
            $this->slug = Str::slug($value);
        }
    }

    public function addPrerequisite(): void
    {
        $this->prerequisites[] = '';
    }

    public function removePrerequisite(int $index): void
    {
        unset($this->prerequisites[$index]);
        $this->prerequisites = array_values($this->prerequisites);
    }

    public function addStep(): void
    {
        $this->steps[] = ['title' => '', 'body_markdown' => '', 'image_key' => '', 'cta_label' => '', 'cta_route' => ''];
    }

    public function removeStep(int $index): void
    {
        unset($this->steps[$index]);
        $this->steps = array_values($this->steps);
    }

    public function addFaq(): void
    {
        $this->faqs[] = ['question' => '', 'answer_markdown' => ''];
    }

    public function removeFaq(int $index): void
    {
        unset($this->faqs[$index]);
        $this->faqs = array_values($this->faqs);
    }

    public function save(): void
    {
        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', Rule::unique('help_articles', 'slug')->ignore($this->articleId)],
            'module' => ['required', 'string', 'max:50'],
            'summary' => ['nullable', 'string'],
            'body_markdown' => ['nullable', 'string'],
            'target_route' => ['nullable', 'string', 'max:255'],
            'locale' => ['required', 'string', 'max:10'],
            'status' => ['required', 'in:draft,published,archived'],
            'visibility_mode' => ['required', 'in:all,scoped'],
            'prerequisites' => ['array'],
            'prerequisites.*' => ['nullable', 'string', 'max:255'],
            'allowed_roles' => ['array'],
            'allowed_roles.*' => ['string', 'max:255'],
            'allowed_permissions' => ['array'],
            'allowed_permissions.*' => ['string', 'max:255'],
            'steps' => ['required', 'array', 'min:1'],
            'steps.*.title' => ['required', 'string', 'max:255'],
            'steps.*.body_markdown' => ['nullable', 'string'],
            'steps.*.image_key' => ['nullable', 'string', 'max:255'],
            'steps.*.cta_label' => ['nullable', 'string', 'max:255'],
            'steps.*.cta_route' => ['nullable', 'string', 'max:255'],
            'faqs' => ['array'],
            'faqs.*.question' => ['nullable', 'string', 'max:255'],
            'faqs.*.answer_markdown' => ['nullable', 'string'],
        ]);

        $article = HelpArticle::query()->updateOrCreate(
            ['id' => $this->articleId],
            [
                'title' => $validated['title'],
                'slug' => Str::slug($validated['slug']),
                'module' => $validated['module'],
                'summary' => $validated['summary'],
                'body_markdown' => $validated['body_markdown'],
                'prerequisites' => array_values(array_filter($validated['prerequisites'], fn ($value) => filled($value))),
                'keywords' => collect(explode(',', $this->keywords))->map(fn ($value) => trim($value))->filter()->values()->all(),
                'target_route' => $validated['target_route'] ?: null,
                'locale' => $validated['locale'],
                'status' => $validated['status'],
                'visibility_mode' => $validated['visibility_mode'],
                'allowed_roles' => $validated['visibility_mode'] === 'scoped' ? array_values($validated['allowed_roles']) : [],
                'allowed_permissions' => $validated['visibility_mode'] === 'scoped' ? array_values($validated['allowed_permissions']) : [],
                'updated_by' => Auth::id(),
                'created_by' => $this->articleId ? HelpArticle::find($this->articleId)?->created_by : Auth::id(),
            ],
        );

        $article->steps()->delete();
        foreach ($validated['steps'] as $index => $step) {
            $article->steps()->create([
                'sort_order' => $index + 1,
                'title' => $step['title'],
                'body_markdown' => $step['body_markdown'],
                'image_key' => $step['image_key'] ?: null,
                'cta_label' => $step['cta_label'] ?: null,
                'cta_route' => $step['cta_route'] ?: null,
            ]);
        }

        $article->faqs()->delete();
        foreach ($validated['faqs'] as $index => $faq) {
            if (! filled($faq['question']) || ! filled($faq['answer_markdown'])) {
                continue;
            }

            $article->faqs()->create([
                'module' => $article->module,
                'sort_order' => $index + 1,
                'question' => $faq['question'],
                'answer_markdown' => $faq['answer_markdown'],
            ]);
        }

        $this->articleId = $article->id;
        $this->loadArticle($article->id);

        session()->flash('status', __('Help article saved.'));
    }

    public function deleteCurrentArticle(): void
    {
        if (! $this->articleId) {
            return;
        }

        $article = HelpArticle::query()->findOrFail($this->articleId);
        $article->delete();

        $this->newArticle();
        session()->flash('status', __('Help article deleted.'));
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Manage Help') }}</h1>
            <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ __('Edit approved guides, FAQs, and step content used by the Help Center and bot.') }}</p>
        </div>
        <div class="flex gap-2">
            <flux:button :href="route('help.index')" wire:navigate variant="ghost">{{ __('View Help') }}</flux:button>
            <flux:button wire:click="newArticle" variant="primary">{{ __('New Article') }}</flux:button>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid gap-6 xl:grid-cols-[22rem_1fr]">
        <aside class="rounded-3xl border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-semibold uppercase tracking-[0.18em] text-neutral-400">{{ __('Articles') }}</h2>
                <span class="text-xs text-neutral-400">{{ $articles->count() }}</span>
            </div>

            <div class="space-y-2">
                @foreach ($articles as $article)
                    <button
                        type="button"
                        wire:click="loadArticle({{ $article->id }})"
                        class="w-full rounded-2xl border px-4 py-3 text-left transition {{ $articleId === $article->id ? 'border-neutral-900 bg-neutral-950 text-white dark:border-white dark:bg-white dark:text-neutral-950' : 'border-neutral-200 bg-white text-neutral-800 hover:border-neutral-300 dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-100 dark:hover:border-neutral-600' }}"
                    >
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm font-semibold">{{ $article->title }}</span>
                            <span class="text-[10px] uppercase tracking-[0.18em] {{ $articleId === $article->id ? 'text-white/70 dark:text-neutral-500' : 'text-neutral-400' }}">{{ $article->status }}</span>
                        </div>
                        <p class="mt-1 text-xs {{ $articleId === $article->id ? 'text-white/70 dark:text-neutral-500' : 'text-neutral-500 dark:text-neutral-400' }}">{{ config('help.modules.'.$article->module, ucfirst($article->module)) }}</p>
                    </button>
                @endforeach
            </div>
        </aside>

        <section class="space-y-6">
            <div class="rounded-3xl border border-neutral-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="grid gap-4 md:grid-cols-2">
                    <flux:input wire:model="title" :label="__('Title')" />
                    <flux:input wire:model="slug" :label="__('Slug')" />
                    <div>
                        <label class="mb-2 block text-sm font-medium text-neutral-700 dark:text-neutral-300">{{ __('Module') }}</label>
                        <select wire:model="module" class="w-full rounded-xl border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-100">
                            @foreach ($modules as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <flux:input wire:model="target_route" :label="__('Target Route')" placeholder="orders.index" />
                    <div>
                        <label class="mb-2 block text-sm font-medium text-neutral-700 dark:text-neutral-300">{{ __('Status') }}</label>
                        <select wire:model="status" class="w-full rounded-xl border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-100">
                            <option value="draft">{{ __('Draft') }}</option>
                            <option value="published">{{ __('Published') }}</option>
                            <option value="archived">{{ __('Archived') }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-neutral-700 dark:text-neutral-300">{{ __('Visibility') }}</label>
                        <select wire:model.live="visibility_mode" class="w-full rounded-xl border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-100">
                            <option value="all">{{ __('All authenticated users') }}</option>
                            <option value="scoped">{{ __('Scoped by role/permission') }}</option>
                        </select>
                    </div>
                </div>

                <div class="mt-4 grid gap-4">
                    <flux:textarea wire:model="summary" :label="__('Summary')" rows="3" />
                    <flux:textarea wire:model="body_markdown" :label="__('Body Markdown')" rows="8" />
                    <flux:input wire:model="keywords" :label="__('Keywords')" placeholder="orders, invoice, inventory" />
                </div>

                <div class="mt-6 space-y-3">
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-semibold uppercase tracking-[0.18em] text-neutral-400">{{ __('Prerequisites') }}</h3>
                        <button type="button" wire:click="addPrerequisite" class="text-sm font-medium text-neutral-600 hover:text-neutral-900 dark:text-neutral-300 dark:hover:text-white">{{ __('Add') }}</button>
                    </div>
                    @foreach ($prerequisites as $index => $value)
                        <div class="flex gap-2">
                            <flux:input wire:model="prerequisites.{{ $index }}" class="flex-1" />
                            <flux:button wire:click="removePrerequisite({{ $index }})" variant="ghost" size="sm">{{ __('Remove') }}</flux:button>
                        </div>
                    @endforeach
                </div>

                @if ($visibility_mode === 'scoped')
                    <div class="mt-6 grid gap-6 lg:grid-cols-2">
                        <div>
                            <h3 class="mb-3 text-sm font-semibold uppercase tracking-[0.18em] text-neutral-400">{{ __('Allowed Roles') }}</h3>
                            <div class="grid gap-2">
                                @foreach ($roles as $role)
                                    <label class="flex items-center gap-2 text-sm text-neutral-700 dark:text-neutral-300">
                                        <input type="checkbox" value="{{ $role }}" wire:model="allowed_roles" class="rounded border-neutral-300 text-neutral-900 focus:ring-neutral-500" />
                                        <span>{{ $role }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                        <div>
                            <h3 class="mb-3 text-sm font-semibold uppercase tracking-[0.18em] text-neutral-400">{{ __('Allowed Permissions') }}</h3>
                            <div class="grid max-h-56 gap-2 overflow-y-auto pr-1">
                                @foreach ($permissions as $permission)
                                    <label class="flex items-center gap-2 text-sm text-neutral-700 dark:text-neutral-300">
                                        <input type="checkbox" value="{{ $permission }}" wire:model="allowed_permissions" class="rounded border-neutral-300 text-neutral-900 focus:ring-neutral-500" />
                                        <span>{{ $permission }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endif
            </div>

            <div class="rounded-3xl border border-neutral-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-sm font-semibold uppercase tracking-[0.18em] text-neutral-400">{{ __('Steps') }}</h2>
                    <button type="button" wire:click="addStep" class="text-sm font-medium text-neutral-600 hover:text-neutral-900 dark:text-neutral-300 dark:hover:text-white">{{ __('Add step') }}</button>
                </div>

                <div class="space-y-5">
                    @foreach ($steps as $index => $step)
                        <div class="rounded-2xl border border-neutral-200 p-4 dark:border-neutral-700">
                            <div class="mb-3 flex items-center justify-between">
                                <h3 class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Step') }} {{ $index + 1 }}</h3>
                                @if (count($steps) > 1)
                                    <flux:button wire:click="removeStep({{ $index }})" variant="ghost" size="sm">{{ __('Remove') }}</flux:button>
                                @endif
                            </div>
                            <div class="grid gap-4 md:grid-cols-2">
                                <flux:input wire:model="steps.{{ $index }}.title" :label="__('Title')" />
                                <flux:input wire:model="steps.{{ $index }}.image_key" :label="__('Image Key')" placeholder="purchase-orders.create" />
                                <flux:input wire:model="steps.{{ $index }}.cta_label" :label="__('CTA Label')" />
                                <flux:input wire:model="steps.{{ $index }}.cta_route" :label="__('CTA Route')" placeholder="purchase-orders.index" />
                            </div>
                            <div class="mt-4">
                                <flux:textarea wire:model="steps.{{ $index }}.body_markdown" :label="__('Step Markdown')" rows="5" />
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="rounded-3xl border border-neutral-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-sm font-semibold uppercase tracking-[0.18em] text-neutral-400">{{ __('FAQs') }}</h2>
                    <button type="button" wire:click="addFaq" class="text-sm font-medium text-neutral-600 hover:text-neutral-900 dark:text-neutral-300 dark:hover:text-white">{{ __('Add FAQ') }}</button>
                </div>

                <div class="space-y-5">
                    @foreach ($faqs as $index => $faq)
                        <div class="rounded-2xl border border-neutral-200 p-4 dark:border-neutral-700">
                            <div class="mb-3 flex items-center justify-between">
                                <h3 class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('FAQ') }} {{ $index + 1 }}</h3>
                                @if (count($faqs) > 1)
                                    <flux:button wire:click="removeFaq({{ $index }})" variant="ghost" size="sm">{{ __('Remove') }}</flux:button>
                                @endif
                            </div>
                            <div class="grid gap-4">
                                <flux:input wire:model="faqs.{{ $index }}.question" :label="__('Question')" />
                                <flux:textarea wire:model="faqs.{{ $index }}.answer_markdown" :label="__('Answer Markdown')" rows="4" />
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="rounded-3xl border border-neutral-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-sm font-semibold uppercase tracking-[0.18em] text-neutral-400">{{ __('Markdown Preview') }}</h2>
                        <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ __('Live preview of the article body.') }}</p>
                    </div>
                    <div class="flex gap-2">
                        @if ($articleId)
                            <flux:button wire:click="deleteCurrentArticle" variant="danger">{{ __('Delete') }}</flux:button>
                        @endif
                        <flux:button wire:click="save">{{ __('Save Article') }}</flux:button>
                    </div>
                </div>

                <div class="help-markdown mt-4 rounded-2xl border border-dashed border-neutral-300 bg-neutral-50 p-5 dark:border-neutral-700 dark:bg-neutral-950">
                    {!! $previewBody ?: '<p class="text-neutral-400">Nothing to preview yet.</p>' !!}
                </div>
            </div>
        </section>
    </div>
</div>
