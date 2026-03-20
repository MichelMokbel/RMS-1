<?php

namespace App\Services\Ai;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GeminiProvider implements AiProviderInterface
{
    public function generateStructured(array $messages, array $schema): array
    {
        $apiKey = (string) config('services.gemini.api_key', '');
        $model = (string) config('services.gemini.model', 'gemini-2.5-flash');
        $baseUrl = rtrim((string) config('services.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta'), '/');

        if ($apiKey === '') {
            throw new RuntimeException('Gemini API key is not configured.');
        }

        $payload = [
            'contents' => $this->transformMessages($messages),
            'generationConfig' => [
                'temperature' => 0.2,
                'responseMimeType' => 'application/json',
                'responseSchema' => $schema,
            ],
        ];

        $response = Http::timeout(30)
            ->acceptJson()
            ->post("{$baseUrl}/models/{$model}:generateContent?key={$apiKey}", $payload);

        if (! $response->successful()) {
            throw new RuntimeException('Gemini request failed with status '.$response->status().'.');
        }

        $text = $this->extractText($response->json() ?? []);

        if (! is_string($text) || trim($text) === '') {
            throw new RuntimeException('Gemini returned an empty response.');
        }

        $decoded = json_decode($text, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Gemini returned non-JSON content.');
        }

        return $decoded;
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<int, array<string, mixed>>
     */
    private function transformMessages(array $messages): array
    {
        return collect($messages)
            ->map(function (array $message): array {
                $role = (string) ($message['role'] ?? 'user');
                $content = trim((string) ($message['content'] ?? ''));

                return [
                    'role' => $role === 'assistant' ? 'model' : 'user',
                    'parts' => [[
                        'text' => $content,
                    ]],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractText(array $payload): ?string
    {
        $parts = Arr::get($payload, 'candidates.0.content.parts', []);
        if (! is_array($parts)) {
            return null;
        }

        return collect($parts)
            ->pluck('text')
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->implode("\n");
    }
}
