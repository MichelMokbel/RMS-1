<?php

namespace App\Services\Help;

use App\Models\HelpArticle;
use App\Models\HelpChatMessage;
use App\Models\HelpChatSession;
use App\Models\User;
use App\Services\Ai\AiProviderInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class HelpBotService
{
    public function __construct(
        private readonly HelpSearchService $search,
        private readonly AiProviderInterface $provider,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function respond(User $user, string $message, ?HelpChatSession $session = null, ?HelpArticle $currentArticle = null): array
    {
        $session ??= HelpChatSession::query()->create([
            'user_id' => $user->id,
            'locale' => config('help.default_locale', 'en'),
        ]);

        $message = trim($message);
        if ($message === '') {
            return $this->fallback($session, 'Ask a question about a workflow to get a cited answer.', collect(), false);
        }

        $session->messages()->create([
            'role' => 'user',
            'content' => $message,
        ]);

        $suggested = $this->search->searchArticles($user, $message, $session->locale)->take(3)->values();
        $context = $this->search->buildContext($user, $message, $currentArticle, $session->locale);

        if (! config('help.bot.enabled', true)) {
            return $this->fallback($session, 'The help bot is currently disabled. Open one of the suggested guides below instead.', $suggested, true);
        }

        if ($context === []) {
            return $this->fallback($session, "I don't have an approved guide for that yet. Open one of the closest guides below.", $suggested, true);
        }

        try {
            $structured = $this->provider->generateStructured(
                $this->messages($message, $context),
                $this->schema(),
            );
        } catch (\Throwable) {
            return $this->fallback($session, 'I could not reach the help model just now. Open one of the suggested guides below.', $suggested, true);
        }

        $answer = trim((string) Arr::get($structured, 'answer_markdown', ''));
        $citations = collect(Arr::get($structured, 'citations', []))
            ->filter(fn ($citation) => is_array($citation) && filled($citation['article_slug'] ?? null))
            ->values()
            ->all();

        $fallback = (bool) Arr::get($structured, 'fallback', false);
        $confidence = Arr::get($structured, 'confidence', 'medium');

        if ($answer === '' || $citations === []) {
            return $this->fallback($session, "I couldn't find a fully grounded answer in the approved help content. Open one of the suggested guides below.", $suggested, true);
        }

        $assistantMessage = $session->messages()->create([
            'role' => 'assistant',
            'content' => $answer,
            'citations' => $citations,
            'confidence' => $confidence,
            'fallback' => $fallback,
        ]);

        $session->forceFill([
            'title' => Str::limit($message, (int) config('help.bot.session_title_limit', 80), ''),
            'last_question' => $message,
            'last_answered_at' => now(),
        ])->save();

        return [
            'session_id' => $session->id,
            'message_id' => $assistantMessage->id,
            'answer_markdown' => $answer,
            'citations' => $citations,
            'suggested_articles' => $this->mapSuggested($suggested),
            'confidence' => $confidence,
            'fallback' => $fallback,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $context
     * @return array<int, array<string, string>>
     */
    private function messages(string $question, array $context): array
    {
        return [
            [
                'role' => 'user',
                'content' => "You are the RMS-1 help bot. Answer only from the approved help content provided below.\n".
                    "If the answer is not fully supported by that content, say so and set fallback=true.\n".
                    "Always return JSON only and include citations for every answer.\n\n".
                    'Approved help content:'."\n".json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ],
            [
                'role' => 'user',
                'content' => 'User question: '.$question,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'answer_markdown' => ['type' => 'STRING'],
                'confidence' => ['type' => 'STRING', 'enum' => ['high', 'medium', 'low']],
                'fallback' => ['type' => 'BOOLEAN'],
                'citations' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'article_slug' => ['type' => 'STRING'],
                            'article_title' => ['type' => 'STRING'],
                            'step_id' => ['type' => 'INTEGER'],
                            'route_name' => ['type' => 'STRING'],
                        ],
                        'required' => ['article_slug', 'article_title'],
                    ],
                ],
            ],
            'required' => ['answer_markdown', 'confidence', 'fallback', 'citations'],
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, HelpArticle>  $suggested
     * @return array<int, array<string, string|null>>
     */
    private function mapSuggested($suggested): array
    {
        return $suggested
            ->map(fn (HelpArticle $article) => [
                'slug' => $article->slug,
                'title' => $article->title,
                'module' => $article->module,
                'url' => route('help.show', $article),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, HelpArticle>  $suggested
     * @return array<string, mixed>
     */
    private function fallback(HelpChatSession $session, string $message, $suggested, bool $fallback): array
    {
        $assistantMessage = $session->messages()->create([
            'role' => 'assistant',
            'content' => $message,
            'citations' => [],
            'confidence' => 'low',
            'fallback' => $fallback,
        ]);

        return [
            'session_id' => $session->id,
            'message_id' => $assistantMessage->id,
            'answer_markdown' => $message,
            'citations' => [],
            'suggested_articles' => $this->mapSuggested($suggested),
            'confidence' => 'low',
            'fallback' => $fallback,
        ];
    }
}
