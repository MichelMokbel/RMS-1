<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Services\Marketing\GoogleAdsOAuthService;
use App\Services\Marketing\MarketingActivityLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GoogleAdsOAuthController extends Controller
{
    public function __construct(
        protected GoogleAdsOAuthService $oauthService,
        protected MarketingActivityLogService $activityLog,
    ) {}

    public function redirect(Request $request): RedirectResponse
    {
        $state = $this->oauthService->state();
        $request->session()->put('marketing_google_oauth_state', $state);

        try {
            $url = $this->oauthService->authorizationUrl(
                redirectUri: route('marketing.google.oauth.callback'),
                state: $state,
            );
        } catch (\RuntimeException $e) {
            return redirect()
                ->route('marketing.settings')
                ->with('status', $e->getMessage());
        }

        return redirect()->away($url);
    }

    public function callback(Request $request): RedirectResponse
    {
        $expectedState = (string) $request->session()->pull('marketing_google_oauth_state', '');
        $state = (string) $request->query('state', '');

        if ($expectedState === '' || ! hash_equals($expectedState, $state)) {
            return redirect()
                ->route('marketing.settings')
                ->with('status', __('Google Ads connection failed because the OAuth state did not match. Try connecting again.'));
        }

        if ($request->filled('error')) {
            return redirect()
                ->route('marketing.settings')
                ->with('status', __('Google Ads connection was cancelled or denied: :error', [
                    'error' => (string) $request->query('error'),
                ]));
        }

        $code = (string) $request->query('code', '');
        if ($code === '') {
            return redirect()
                ->route('marketing.settings')
                ->with('status', __('Google Ads connection failed because Google did not return an authorization code.'));
        }

        try {
            $this->oauthService->exchangeCodeForRefreshToken(
                code: $code,
                redirectUri: route('marketing.google.oauth.callback'),
                actorId: $request->user()->id,
            );
        } catch (\RuntimeException $e) {
            return redirect()
                ->route('marketing.settings')
                ->with('status', $e->getMessage());
        }

        $this->activityLog->log('settings.google_ads.connected', $request->user()->id, null, [
            'section' => 'marketing',
        ]);

        return redirect()
            ->route('marketing.settings')
            ->with('status', __('Google Ads connected. The OAuth refresh token has been saved securely.'));
    }
}
