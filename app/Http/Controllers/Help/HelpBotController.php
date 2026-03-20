<?php

namespace App\Http\Controllers\Help;

use App\Http\Controllers\Controller;
use App\Models\HelpArticle;
use App\Models\HelpChatSession;
use App\Services\Help\HelpBotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HelpBotController extends Controller
{
    public function __invoke(Request $request, HelpBotService $bot): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            'session_id' => ['nullable', 'integer'],
            'article_slug' => ['nullable', 'string'],
        ]);

        $session = null;
        if (! empty($validated['session_id'])) {
            $session = HelpChatSession::query()
                ->where('id', (int) $validated['session_id'])
                ->where('user_id', $request->user()?->id)
                ->first();
        }

        $article = null;
        if (! empty($validated['article_slug'])) {
            $article = HelpArticle::query()
                ->where('slug', (string) $validated['article_slug'])
                ->first();
        }

        return response()->json(
            $bot->respond(
                $request->user(),
                (string) $validated['message'],
                $session,
                $article,
            )
        );
    }
}
