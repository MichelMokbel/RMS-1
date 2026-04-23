<?php

namespace App\Services\Marketing;

use Google\Ads\GoogleAds\Lib\OAuth2TokenBuilder;
use Google\Ads\GoogleAds\Lib\V23\GoogleAdsClient;
use Google\Ads\GoogleAds\Lib\V23\GoogleAdsClientBuilder;
use Google\Ads\GoogleAds\Lib\V23\GoogleAdsException;
use Google\Ads\GoogleAds\V23\Enums\AdGroupStatusEnum\AdGroupStatus;
use Google\Ads\GoogleAds\V23\Enums\AdGroupTypeEnum\AdGroupType;
use Google\Ads\GoogleAds\V23\Enums\AdvertisingChannelSubTypeEnum\AdvertisingChannelSubType;
use Google\Ads\GoogleAds\V23\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;
use Google\Ads\GoogleAds\V23\Enums\CampaignStatusEnum\CampaignStatus;
use Google\Ads\GoogleAds\V23\Services\Client\GoogleAdsServiceClient;
use Google\Ads\GoogleAds\V23\Services\SearchGoogleAdsStreamRequest;
use Google\ApiCore\ApiException;
use Illuminate\Support\Carbon;
use RuntimeException;

class GoogleAdsApiService
{
    private const SCOPE = 'https://www.googleapis.com/auth/adwords';

    private const CAMPAIGN_QUERY = <<<'GAQL'
SELECT
  campaign.id,
  campaign.name,
  campaign.status,
  campaign.advertising_channel_type,
  campaign.advertising_channel_sub_type,
  campaign.start_date_time,
  campaign.end_date_time,
  campaign_budget.amount_micros,
  campaign_budget.total_amount_micros
FROM campaign
WHERE campaign.status != 'REMOVED'
ORDER BY campaign.id
GAQL;

    private const AD_GROUP_QUERY = <<<'GAQL'
SELECT
  ad_group.id,
  ad_group.name,
  ad_group.status,
  ad_group.type,
  campaign.id,
  campaign.name
FROM ad_group
WHERE ad_group.status != 'REMOVED'
ORDER BY ad_group.id
GAQL;

    private const DAILY_SPEND_QUERY = <<<'GAQL'
SELECT
  campaign.id,
  campaign.name,
  campaign.status,
  segments.date,
  metrics.impressions,
  metrics.clicks,
  metrics.cost_micros,
  metrics.conversions,
  metrics.all_conversions
FROM campaign
WHERE segments.date = '{date}'
ORDER BY campaign.id
GAQL;

    public function __construct(
        protected MarketingSettingsService $settings,
    ) {}

    public function fetchCampaigns(string $customerId): array
    {
        return $this->executeQuery(
            customerId: $customerId,
            query: self::CAMPAIGN_QUERY,
            context: 'fetch campaigns',
            mapper: fn ($row) => [
                'id' => $this->stringify($row->getCampaign()?->getId()),
                'name' => $row->getCampaign()?->getName() ?: 'Untitled',
                'status' => $this->enumName(CampaignStatus::class, $row->getCampaign()?->getStatus()),
                'advertising_channel_type' => $this->enumName(
                    AdvertisingChannelType::class,
                    $row->getCampaign()?->getAdvertisingChannelType(),
                ),
                'advertising_channel_sub_type' => $this->enumName(
                    AdvertisingChannelSubType::class,
                    $row->getCampaign()?->getAdvertisingChannelSubType(),
                ),
                'daily_budget_micro' => $this->campaignBudgetAmount($row, 'daily'),
                'lifetime_budget_micro' => $this->campaignBudgetAmount($row, 'lifetime'),
                'start_date' => $this->dateOnly($row->getCampaign()?->getStartDateTime()),
                'end_date' => $this->dateOnly($row->getCampaign()?->getEndDateTime()),
            ],
        );
    }

    public function fetchAdGroups(string $customerId): array
    {
        return $this->executeQuery(
            customerId: $customerId,
            query: self::AD_GROUP_QUERY,
            context: 'fetch ad groups',
            mapper: fn ($row) => [
                'id' => $this->stringify($row->getAdGroup()?->getId()),
                'name' => $row->getAdGroup()?->getName() ?: 'Untitled',
                'status' => $this->enumName(AdGroupStatus::class, $row->getAdGroup()?->getStatus()),
                'type' => $this->enumName(AdGroupType::class, $row->getAdGroup()?->getType()),
                'campaign_id' => $this->stringify($row->getCampaign()?->getId()),
                'campaign_name' => $row->getCampaign()?->getName() ?: 'Untitled',
            ],
        );
    }

    public function fetchDailySpend(string $customerId, string $date): array
    {
        $normalizedDate = $this->normalizeDate($date);
        $query = str_replace('{date}', $normalizedDate, self::DAILY_SPEND_QUERY);

        return $this->executeQuery(
            customerId: $customerId,
            query: $query,
            context: "fetch daily spend for {$normalizedDate}",
            mapper: fn ($row) => [
                'campaign_id' => $this->stringify($row->getCampaign()?->getId()),
                'campaign_name' => $row->getCampaign()?->getName() ?: 'Untitled',
                'campaign_status' => $this->enumName(
                    CampaignStatus::class,
                    $row->getCampaign()?->getStatus(),
                ),
                'date' => $row->getSegments()?->getDate() ?: $normalizedDate,
                'impressions' => (int) ($row->getMetrics()?->getImpressions() ?? 0),
                'clicks' => (int) ($row->getMetrics()?->getClicks() ?? 0),
                'cost_micros' => (int) ($row->getMetrics()?->getCostMicros() ?? 0),
                'conversions' => (int) round((float) ($row->getMetrics()?->getConversions() ?? 0)),
                'all_conversions' => (int) round((float) ($row->getMetrics()?->getAllConversions() ?? 0)),
            ],
        );
    }

    private function executeQuery(string $customerId, string $query, string $context, callable $mapper): array
    {
        $normalizedCustomerId = $this->normalizeCustomerId($customerId);
        $client = $this->buildServiceClient();

        try {
            $request = SearchGoogleAdsStreamRequest::build($normalizedCustomerId, $query);
            $stream = $client->searchStream($request);

            $rows = [];
            foreach ($stream->readAll() as $response) {
                foreach ($response->getResults() as $row) {
                    $rows[] = $mapper($row);
                }
            }

            return $rows;
        } catch (GoogleAdsException $e) {
            throw new RuntimeException(
                $this->formatGoogleAdsException($e, $context, $normalizedCustomerId),
                previous: $e,
            );
        } catch (ApiException $e) {
            throw new RuntimeException(
                $this->formatApiException($e, $context, $normalizedCustomerId),
                previous: $e,
            );
        }
    }

    private function buildServiceClient(): GoogleAdsServiceClient
    {
        return $this->buildGoogleAdsClient()->getGoogleAdsServiceClient();
    }

    private function buildGoogleAdsClient(): GoogleAdsClient
    {
        $settings = $this->settings->get();

        $developerToken = $this->settings->getGoogleDeveloperToken();
        $clientId = $settings->google_client_id ?? null;
        $refreshToken = $this->settings->getGoogleRefreshToken();
        $clientSecret = $this->settings->getGoogleClientSecret();

        if (! $developerToken) {
            throw new RuntimeException('Google Ads developer token is not configured.');
        }

        if (! $clientId) {
            throw new RuntimeException('Google Ads client ID is not configured.');
        }

        if (! $clientSecret) {
            throw new RuntimeException('Google Ads client secret is not configured.');
        }

        if (! $refreshToken) {
            throw new RuntimeException('Google Ads refresh token is not configured.');
        }

        $oauth2Credential = (new OAuth2TokenBuilder)
            ->withClientId($clientId)
            ->withClientSecret($clientSecret)
            ->withRefreshToken($refreshToken)
            ->withScopes(self::SCOPE)
            ->build();

        $builder = (new GoogleAdsClientBuilder)
            ->withDeveloperToken($developerToken)
            ->withOAuth2Credential($oauth2Credential);

        $transport = config('marketing.google_ads.transport', 'rest');
        if (is_string($transport) && $transport !== '') {
            $builder->withTransport($transport);
        }

        if (! empty($settings->google_login_customer_id)) {
            $builder->withLoginCustomerId($this->normalizeLoginCustomerId(
                (string) $settings->google_login_customer_id,
            ));
        }

        return $builder->build();
    }

    private function normalizeCustomerId(string $customerId): string
    {
        $normalized = str_replace('-', '', trim($customerId));

        if ($normalized === '' || ! ctype_digit($normalized)) {
            throw new RuntimeException("Invalid Google Ads customer ID: {$customerId}");
        }

        return $normalized;
    }

    private function normalizeLoginCustomerId(string $customerId): int
    {
        $normalized = $this->normalizeCustomerId($customerId);

        return (int) $normalized;
    }

    private function normalizeDate(string $date): string
    {
        try {
            $normalized = trim($date);
            $parsed = Carbon::createFromFormat('Y-m-d', $normalized);

            if ($parsed->format('Y-m-d') !== $normalized) {
                throw new RuntimeException;
            }

            return $parsed->format('Y-m-d');
        } catch (\Throwable) {
            throw new RuntimeException("Invalid Google Ads date: {$date}. Expected YYYY-MM-DD.");
        }
    }

    private function dateOnly(?string $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function campaignBudgetAmount($row, string $type): ?int
    {
        $budget = $row->getCampaignBudget();

        if (! $budget) {
            return null;
        }

        if ($type === 'daily' && $budget->hasAmountMicros()) {
            return (int) $budget->getAmountMicros();
        }

        if ($type === 'lifetime' && $budget->hasTotalAmountMicros()) {
            return (int) $budget->getTotalAmountMicros();
        }

        return null;
    }

    private function stringify(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private function enumName(string $enumClass, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        try {
            return $enumClass::name($value);
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    private function formatGoogleAdsException(
        GoogleAdsException $exception,
        string $context,
        string $customerId,
    ): string {
        $message = $exception->getBasicMessage() ?: $exception->getMessage();
        $parts = [];

        $failure = $exception->getGoogleAdsFailure();
        if ($failure) {
            foreach ($failure->getErrors() as $error) {
                $errorMessage = $error->getMessage();
                if ($errorMessage !== '') {
                    $parts[] = $errorMessage;
                }
            }
        }

        $suffix = $parts ? ' Details: '.implode(' | ', $parts) : '';
        $requestId = $exception->getRequestId();
        $requestPart = $requestId ? " requestId={$requestId}" : '';

        return "Google Ads {$context} failed for customer {$customerId}{$requestPart}: {$message}{$suffix}";
    }

    private function formatApiException(ApiException $exception, string $context, string $customerId): string
    {
        $status = method_exists($exception, 'getStatus') ? $exception->getStatus() : null;
        $basicMessage = method_exists($exception, 'getBasicMessage')
            ? $exception->getBasicMessage()
            : $exception->getMessage();
        $statusPart = $status ? " status={$status}" : '';

        return "Google Ads {$context} failed for customer {$customerId}{$statusPart}: {$basicMessage}";
    }
}
