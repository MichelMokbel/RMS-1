<?php

namespace App\Services\Security;

use Illuminate\Support\Facades\Http;

class RecaptchaService
{
    public function verify(?string $token, ?string $remoteIp = null): array
    {
        $enabled = (bool) config('services.recaptcha.enabled', false);
        if (! $enabled) {
            return ['ok' => true, 'skipped' => true];
        }

        $secret = config('services.recaptcha.secret');
        $minScore = (float) config('services.recaptcha.min_score', 0.0);

        if (! $secret) {
            return ['ok' => false, 'reason' => 'recaptcha_not_configured'];
        }

        if (! $token) {
            return ['ok' => false, 'reason' => 'missing_token'];
        }

        $payload = [
            'secret' => $secret,
            'response' => $token,
        ];
        if ($remoteIp) {
            $payload['remoteip'] = $remoteIp;
        }

        $resp = Http::asForm()->timeout(8)->post('https://www.google.com/recaptcha/api/siteverify', $payload);
        if (! $resp->ok()) {
            return ['ok' => false, 'reason' => 'verify_http_error', 'status' => $resp->status()];
        }

        $json = $resp->json() ?: [];
        $success = (bool) ($json['success'] ?? false);
        if (! $success) {
            return ['ok' => false, 'reason' => 'verify_failed', 'errors' => $json['error-codes'] ?? []];
        }

        // v3 responses include a score; v2 typically does not.
        if (array_key_exists('score', $json)) {
            $score = (float) $json['score'];
            if ($score < $minScore) {
                return ['ok' => false, 'reason' => 'score_too_low', 'score' => $score];
            }
            return ['ok' => true, 'score' => $score];
        }

        return ['ok' => true];
    }
}


