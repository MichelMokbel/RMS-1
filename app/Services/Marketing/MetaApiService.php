<?php

namespace App\Services\Marketing;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper around the Meta Marketing API (Graph API).
 *
 * All methods return plain arrays of raw API data — transformation
 * and persistence happen in the sync services, not here.
 *
 * Token is loaded from DB on every call (never cached in properties)
 * so rotation takes effect immediately without a restart.
 */
class MetaApiService
{
    private const FIELDS_CAMPAIGN = 'id,name,status,objective,daily_budget,lifetime_budget,start_time,stop_time';

    private const FIELDS_ADSET = 'id,name,status,daily_budget,campaign_id';

    private const FIELDS_AD = 'id,name,status,adset_id,creative{object_type,effective_object_story_id,thumbnail_url,image_url,title,body}';

    private const FIELDS_INSIGHTS = 'campaign_id,adset_id,ad_id,impressions,reach,clicks,spend,actions';

    public function __construct(
        protected MarketingSettingsService $settings,
    ) {}

    /**
     * Fetch all campaigns for the given ad account ID (handles pagination).
     * Returns a flat array of raw campaign objects from the API.
     *
     * @throws RuntimeException on API error or missing token
     */
    public function fetchCampaigns(string $adAccountId): array
    {
        return $this->fetchPaged(
            endpoint: $this->accountUrl($adAccountId, 'campaigns'),
            params: ['fields' => self::FIELDS_CAMPAIGN, 'limit' => 200],
        );
    }

    /**
     * Fetch all ad sets for the given ad account (handles pagination).
     */
    public function fetchAdSets(string $adAccountId): array
    {
        return $this->fetchPaged(
            endpoint: $this->accountUrl($adAccountId, 'adsets'),
            params: ['fields' => self::FIELDS_ADSET, 'limit' => 200],
        );
    }

    /**
     * Fetch all ads for the given ad account (handles pagination).
     */
    public function fetchAds(string $adAccountId): array
    {
        return $this->fetchPaged(
            endpoint: $this->accountUrl($adAccountId, 'ads'),
            params: ['fields' => self::FIELDS_AD, 'limit' => 200],
        );
    }

    /**
     * Fetch daily insights at ad level for a single date.
     * $date format: YYYY-MM-DD.
     *
     * Returns array of rows, each containing:
     *   campaign_id, adset_id, ad_id, impressions, reach, clicks, spend, date_start
     */
    public function fetchDailyInsights(string $adAccountId, string $date): array
    {
        $timeRange = json_encode(['since' => $date, 'until' => $date]);

        return $this->fetchPaged(
            endpoint: $this->accountUrl($adAccountId, 'insights'),
            params: [
                'fields' => self::FIELDS_INSIGHTS,
                'time_range' => $timeRange,
                'level' => 'ad',
                'time_increment' => 1,
                'limit' => 500,
            ],
        );
    }

    /**
     * Paginate through a Meta API endpoint and return all records.
     * Uses cursor-based pagination (paging.cursors.after + paging.next).
     */
    private function fetchPaged(string $endpoint, array $params): array
    {
        $token = $this->settings->getMetaSystemUserToken();
        if (! $token) {
            throw new RuntimeException('Meta system user token is not configured.');
        }

        $results = [];
        $url = $endpoint;
        $currentParams = array_merge($params, ['access_token' => $token]);

        do {
            $response = Http::timeout(config('marketing.meta.timeout', 30))
                ->acceptJson()
                ->get($url, $currentParams);

            if (! $response->successful()) {
                $error = $response->json('error', []);
                $code = $error['code'] ?? 0;
                $message = $error['message'] ?? "HTTP {$response->status()}";

                if ($code === 190) {
                    throw new RuntimeException("Meta API token is invalid or expired (code 190): {$message}");
                }

                if ($response->status() === 429 || $code === 17) {
                    throw new RuntimeException("Meta API rate limit reached (code 17): {$message}");
                }

                throw new RuntimeException("Meta API error (code {$code}): {$message}");
            }

            $body = $response->json();
            $data = $body['data'] ?? [];

            foreach ($data as $item) {
                $results[] = $item;
            }

            // Follow cursor pagination
            $after = $body['paging']['cursors']['after'] ?? null;
            $hasNext = isset($body['paging']['next']);

            if ($hasNext && $after) {
                // Subsequent pages: pass cursor, not the full initial params
                $url = $endpoint;
                $currentParams = [
                    'access_token' => $token,
                    'after' => $after,
                    'limit' => $currentParams['limit'] ?? 200,
                    // fields must be repeated on subsequent cursor pages
                    'fields' => $currentParams['fields'] ?? '',
                ];
                // Preserve insight-specific params on subsequent pages
                foreach (['time_range', 'level', 'time_increment'] as $key) {
                    if (isset($params[$key])) {
                        $currentParams[$key] = $params[$key];
                    }
                }
            } else {
                $hasNext = false;
            }
        } while ($hasNext);

        return $results;
    }

    private function accountUrl(string $adAccountId, string $edge): string
    {
        $base = rtrim(config('marketing.meta.base_url', 'https://graph.facebook.com'), '/');
        $version = config('marketing.meta.api_version', 'v21.0');
        $accountId = $this->normalizeAdAccountId($adAccountId);

        return "{$base}/{$version}/{$accountId}/{$edge}";
    }

    /**
     * Meta requires account IDs prefixed with "act_" in API paths.
     */
    private function normalizeAdAccountId(string $accountId): string
    {
        return str_starts_with($accountId, 'act_') ? $accountId : 'act_'.$accountId;
    }
}
