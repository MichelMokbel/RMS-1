<?php

namespace App\Services\Marketing;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class GoogleAdsOAuthService
{
    private const SCOPE = 'https://www.googleapis.com/auth/adwords';

    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public function __construct(
        protected MarketingSettingsService $settingsService,
    ) {}

    public function authorizationUrl(string $redirectUri, string $state): string
    {
        $settings = $this->settingsService->get();

        if (empty($settings->google_client_id)) {
            throw new RuntimeException('Google OAuth client ID must be saved before connecting Google Ads.');
        }

        if (! $this->settingsService->getGoogleClientSecret()) {
            throw new RuntimeException('Google OAuth client secret must be saved before connecting Google Ads.');
        }

        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => $settings->google_client_id,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCodeForRefreshToken(string $code, string $redirectUri, int $actorId): string
    {
        $settings = $this->settingsService->get();
        $clientSecret = $this->settingsService->getGoogleClientSecret();

        if (empty($settings->google_client_id) || ! $clientSecret) {
            throw new RuntimeException('Google OAuth client ID and secret must be saved before connecting Google Ads.');
        }

        try {
            $response = Http::asForm()
                ->timeout((int) config('marketing.google_ads.oauth_timeout', 30))
                ->post(self::TOKEN_URL, [
                    'code' => $code,
                    'client_id' => $settings->google_client_id,
                    'client_secret' => $clientSecret,
                    'redirect_uri' => $redirectUri,
                    'grant_type' => 'authorization_code',
                ])
                ->throw()
                ->json();
        } catch (RequestException $e) {
            $message = $e->response?->json('error_description')
                ?? $e->response?->json('error')
                ?? $e->getMessage();

            throw new RuntimeException("Google OAuth token exchange failed: {$message}", previous: $e);
        }

        $refreshToken = $response['refresh_token'] ?? null;
        if (! is_string($refreshToken) || trim($refreshToken) === '') {
            throw new RuntimeException('Google did not return a refresh token. Remove the app grant from your Google Account permissions, then connect again.');
        }

        $this->settingsService->save([
            'google_refresh_token' => $refreshToken,
        ], $actorId);

        return $refreshToken;
    }

    public function state(): string
    {
        return Str::random(40);
    }
}
