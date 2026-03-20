<?php

namespace App\Services\Help;

use App\Models\HelpArticle;
use App\Models\HelpArticleFaq;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class HelpSearchService
{
    /**
     * @return Collection<int, HelpArticle>
     */
    public function visibleArticles(User $user, ?string $locale = null): Collection
    {
        $locale = $locale ?: (string) config('help.default_locale', 'en');

        return HelpArticle::query()
            ->with(['steps.imageAsset', 'faqs', 'assets'])
            ->where('status', 'published')
            ->where('locale', $locale)
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get()
            ->filter(fn (HelpArticle $article) => $article->isVisibleTo($user))
            ->values();
    }

    /**
     * @return Collection<int, HelpArticle>
     */
    public function searchArticles(User $user, string $query = '', ?string $locale = null, ?string $module = null): Collection
    {
        $articles = $this->visibleArticles($user, $locale);

        if ($module && $module !== 'all') {
            $articles = $articles->where('module', $module)->values();
        }

        $normalized = $this->normalize($query);
        if ($normalized === '') {
            return $articles;
        }

        return $articles
            ->map(function (HelpArticle $article) use ($normalized): array {
                return [
                    'article' => $article,
                    'score' => $this->scoreArticle($article, $normalized),
                ];
            })
            ->filter(fn (array $row) => $row['score'] > 0)
            ->sortByDesc('score')
            ->pluck('article')
            ->values();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildContext(User $user, string $query, ?HelpArticle $currentArticle = null, ?string $locale = null, ?int $limit = null): array
    {
        $limit ??= max(1, (int) config('help.bot.max_context_items', 6));

        $articles = $this->searchArticles($user, $query, $locale)
            ->take($limit)
            ->values();

        if ($currentArticle && $currentArticle->isVisibleTo($user)) {
            $articles = collect([$currentArticle])
                ->merge($articles->reject(fn (HelpArticle $article) => $article->is($currentArticle)))
                ->take($limit)
                ->values();
        }

        return $articles
            ->map(function (HelpArticle $article): array {
                return [
                    'slug' => $article->slug,
                    'title' => $article->title,
                    'module' => $article->module,
                    'summary' => (string) $article->summary,
                    'target_route' => $article->target_route,
                    'target_url' => $article->targetUrl(),
                    'steps' => $article->steps->map(fn ($step) => [
                        'id' => $step->id,
                        'sort_order' => $step->sort_order,
                        'title' => $step->title,
                        'body_markdown' => $step->body_markdown,
                        'cta_label' => $step->cta_label,
                        'cta_route' => $step->cta_route,
                    ])->all(),
                    'faqs' => $article->faqs->map(fn (HelpArticleFaq $faq) => [
                        'id' => $faq->id,
                        'question' => $faq->question,
                        'answer_markdown' => $faq->answer_markdown,
                    ])->all(),
                ];
            })
            ->all();
    }

    /**
     * @return Collection<int, HelpArticle>
     */
    public function relatedArticles(User $user, HelpArticle $article, int $limit = 4): Collection
    {
        return $this->visibleArticles($user, $article->locale)
            ->reject(fn (HelpArticle $candidate) => $candidate->is($article))
            ->sortByDesc(fn (HelpArticle $candidate) => (int) ($candidate->module === $article->module) * 100 + (100 - abs((int) $candidate->sort_order - (int) $article->sort_order)))
            ->take($limit)
            ->values();
    }

    /**
     * @return Collection<int, HelpArticleFaq>
     */
    public function visibleFaqs(User $user, ?string $locale = null): Collection
    {
        return $this->visibleArticles($user, $locale)
            ->flatMap->faqs
            ->sortBy('sort_order')
            ->values();
    }

    private function scoreArticle(HelpArticle $article, string $query): int
    {
        $haystacks = collect([
            $article->title,
            $article->summary,
            $article->body_markdown,
            $article->module,
            implode(' ', $article->keywords ?? []),
            $article->steps->pluck('title')->implode(' '),
            $article->steps->pluck('body_markdown')->implode(' '),
            $article->faqs->pluck('question')->implode(' '),
            $article->faqs->pluck('answer_markdown')->implode(' '),
        ])->map(fn ($value) => $this->normalize((string) $value));

        $score = 0;
        foreach ($haystacks as $haystack) {
            if ($haystack === '') {
                continue;
            }

            if (Str::contains($haystack, $query)) {
                $score += 20;
            }

            foreach (explode(' ', $query) as $term) {
                if ($term !== '' && Str::contains($haystack, $term)) {
                    $score += 3;
                }
            }
        }

        if (Str::contains($this->normalize($article->title), $query)) {
            $score += 50;
        }

        return $score;
    }

    private function normalize(string $value): string
    {
        return Str::of($value)
            ->lower()
            ->replaceMatches('/[^\pL\pN\s]+/u', ' ')
            ->squish()
            ->value();
    }
}
