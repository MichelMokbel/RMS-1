<?php

namespace App\Services\Marketing;

use App\Models\MarketingAd;
use App\Models\MarketingAdSet;
use App\Models\MarketingCampaign;
use App\Models\MarketingPlatformAccount;
use Illuminate\Support\Carbon;

class MarketingCampaignSyncService
{
    public function __construct(
        protected MetaApiService $metaApi,
        protected GoogleAdsApiService $googleAdsApi,
        protected MarketingActivityLogService $activityLog,
    ) {}

    /**
     * Sync campaigns, ad sets, and ads for a single Meta platform account.
     * Returns the total number of records upserted.
     *
     * @throws \RuntimeException on API failure
     */
    public function syncMeta(MarketingPlatformAccount $account): int
    {
        $adAccountId = $account->external_account_id;
        $synced = 0;

        $synced += $this->syncMetaCampaigns($account, $adAccountId);
        $synced += $this->syncMetaAdSets($account, $adAccountId);
        $synced += $this->syncMetaAds($account, $adAccountId);

        $account->update([
            'last_synced_at' => now(),
            'sync_error' => null,
        ]);

        return $synced;
    }

    /**
     * Sync campaigns and ad groups for a single Google Ads customer account.
     * Returns the total number of records upserted.
     *
     * @throws \RuntimeException on API failure
     */
    public function syncGoogle(MarketingPlatformAccount $account): int
    {
        $customerId = $account->external_account_id;
        $synced = 0;

        $synced += $this->syncGoogleCampaigns($account, $customerId);
        $synced += $this->syncGoogleAdGroups($account, $customerId);

        $account->update([
            'last_synced_at' => now(),
            'sync_error' => null,
        ]);

        return $synced;
    }

    private function syncGoogleCampaigns(MarketingPlatformAccount $account, string $customerId): int
    {
        $campaigns = $this->googleAdsApi->fetchCampaigns($customerId);
        $count = 0;

        foreach ($campaigns as $row) {
            $this->upsertGoogleCampaign($account, $row);
            $count++;
        }

        return $count;
    }

    private function upsertGoogleCampaign(MarketingPlatformAccount $account, array $row): void
    {
        $existing = MarketingCampaign::query()
            ->where('platform_account_id', $account->id)
            ->where('external_campaign_id', (string) $row['id'])
            ->first();

        $data = [
            'name' => $row['name'] ?? 'Untitled',
            'status' => $row['status'] ?? 'UNKNOWN',
            'objective' => $row['advertising_channel_type'] ?? null,
            'daily_budget_micro' => $row['daily_budget_micro'] ?? null,
            'lifetime_budget_micro' => $row['lifetime_budget_micro'] ?? null,
            'start_date' => $row['start_date'] ?? null,
            'end_date' => $row['end_date'] ?? null,
            'platform_data' => $row,
            'last_synced_at' => now(),
        ];

        if ($existing) {
            $existing->update($data);
        } else {
            MarketingCampaign::query()->create(array_merge($data, [
                'platform_account_id' => $account->id,
                'external_campaign_id' => (string) $row['id'],
            ]));
        }
    }

    private function syncGoogleAdGroups(MarketingPlatformAccount $account, string $customerId): int
    {
        $adGroups = $this->googleAdsApi->fetchAdGroups($customerId);

        $externalCampaignIds = array_unique(array_filter(array_map(
            fn (array $row) => isset($row['campaign_id']) ? (string) $row['campaign_id'] : null,
            $adGroups,
        )));

        $campaignIdMap = MarketingCampaign::query()
            ->where('platform_account_id', $account->id)
            ->whereIn('external_campaign_id', $externalCampaignIds)
            ->pluck('id', 'external_campaign_id');

        $count = 0;

        foreach ($adGroups as $row) {
            $localCampaignId = $campaignIdMap[(string) ($row['campaign_id'] ?? '')] ?? null;
            if (! $localCampaignId) {
                continue;
            }

            $this->upsertGoogleAdGroup($localCampaignId, $row);
            $count++;
        }

        return $count;
    }

    private function upsertGoogleAdGroup(int $campaignId, array $row): void
    {
        $existing = MarketingAdSet::query()
            ->where('campaign_id', $campaignId)
            ->where('external_adset_id', (string) $row['id'])
            ->first();

        $data = [
            'name' => $row['name'] ?? 'Untitled',
            'status' => $row['status'] ?? 'UNKNOWN',
            'daily_budget_micro' => null,
            'platform_data' => $row,
            'last_synced_at' => now(),
        ];

        if ($existing) {
            $existing->update($data);
        } else {
            MarketingAdSet::query()->create(array_merge($data, [
                'campaign_id' => $campaignId,
                'external_adset_id' => (string) $row['id'],
            ]));
        }
    }

    private function syncMetaCampaigns(MarketingPlatformAccount $account, string $adAccountId): int
    {
        $campaigns = $this->metaApi->fetchCampaigns($adAccountId);
        $count = 0;

        foreach ($campaigns as $row) {
            $this->upsertCampaign($account, $row);
            $count++;
        }

        return $count;
    }

    private function upsertCampaign(MarketingPlatformAccount $account, array $row): void
    {
        $existing = MarketingCampaign::query()
            ->where('platform_account_id', $account->id)
            ->where('external_campaign_id', $row['id'])
            ->first();

        $data = [
            'name' => $row['name'] ?? 'Untitled',
            'status' => $row['status'] ?? 'UNKNOWN',
            'objective' => $row['objective'] ?? null,
            'daily_budget_micro' => isset($row['daily_budget'])
                ? $this->centsToMicros((int) $row['daily_budget'])
                : null,
            'lifetime_budget_micro' => isset($row['lifetime_budget'])
                ? $this->centsToMicros((int) $row['lifetime_budget'])
                : null,
            'start_date' => isset($row['start_time'])
                ? Carbon::parse($row['start_time'])->toDateString()
                : null,
            'end_date' => isset($row['stop_time'])
                ? Carbon::parse($row['stop_time'])->toDateString()
                : null,
            'platform_data' => $row,
            'last_synced_at' => now(),
        ];

        if ($existing) {
            $existing->update($data);
        } else {
            MarketingCampaign::query()->create(array_merge($data, [
                'platform_account_id' => $account->id,
                'external_campaign_id' => $row['id'],
            ]));
        }
    }

    private function syncMetaAdSets(MarketingPlatformAccount $account, string $adAccountId): int
    {
        $adSets = $this->metaApi->fetchAdSets($adAccountId);

        // Build local campaign_id map from external_campaign_id to avoid N+1
        $externalCampaignIds = array_unique(array_column($adSets, 'campaign_id'));
        $campaignIdMap = MarketingCampaign::query()
            ->where('platform_account_id', $account->id)
            ->whereIn('external_campaign_id', $externalCampaignIds)
            ->pluck('id', 'external_campaign_id');

        $count = 0;

        foreach ($adSets as $row) {
            $localCampaignId = $campaignIdMap[$row['campaign_id'] ?? ''] ?? null;
            if (! $localCampaignId) {
                // Parent campaign not found locally — skip
                continue;
            }

            $this->upsertAdSet($localCampaignId, $row);
            $count++;
        }

        return $count;
    }

    private function upsertAdSet(int $campaignId, array $row): void
    {
        $existing = MarketingAdSet::query()
            ->where('campaign_id', $campaignId)
            ->where('external_adset_id', $row['id'])
            ->first();

        $data = [
            'name' => $row['name'] ?? 'Untitled',
            'status' => $row['status'] ?? 'UNKNOWN',
            'daily_budget_micro' => isset($row['daily_budget'])
                ? $this->centsToMicros((int) $row['daily_budget'])
                : null,
            'platform_data' => $row,
            'last_synced_at' => now(),
        ];

        if ($existing) {
            $existing->update($data);
        } else {
            MarketingAdSet::query()->create(array_merge($data, [
                'campaign_id' => $campaignId,
                'external_adset_id' => $row['id'],
            ]));
        }
    }

    private function syncMetaAds(MarketingPlatformAccount $account, string $adAccountId): int
    {
        $ads = $this->metaApi->fetchAds($adAccountId);

        $externalAdSetIds = array_unique(array_filter(array_column($ads, 'adset_id')));
        $adSetIdMap = MarketingAdSet::query()
            ->whereHas('campaign', fn ($q) => $q->where('platform_account_id', $account->id))
            ->whereIn('external_adset_id', $externalAdSetIds)
            ->pluck('id', 'external_adset_id');

        $count = 0;

        foreach ($ads as $row) {
            $localAdSetId = $adSetIdMap[$row['adset_id'] ?? ''] ?? null;
            if (! $localAdSetId) {
                continue;
            }

            $this->upsertAd($localAdSetId, $row);
            $count++;
        }

        return $count;
    }

    private function upsertAd(int $adSetId, array $row): void
    {
        $existing = MarketingAd::query()
            ->where('ad_set_id', $adSetId)
            ->where('external_ad_id', $row['id'])
            ->first();

        $data = [
            'name' => $row['name'] ?? 'Untitled',
            'status' => $row['status'] ?? 'UNKNOWN',
            'creative_type' => $this->extractCreativeType($row['creative'] ?? null),
            'platform_data' => $row,
            'last_synced_at' => now(),
        ];

        if ($existing) {
            $existing->update($data);
        } else {
            MarketingAd::query()->create(array_merge($data, [
                'ad_set_id' => $adSetId,
                'external_ad_id' => $row['id'],
            ]));
        }
    }

    /**
     * Meta budgets are in the currency's minor unit (cents for USD).
     * Convert to micros: 1 cent = 10,000 micros; 1 dollar = 1,000,000 micros.
     */
    private function centsToMicros(int $cents): int
    {
        return $cents * 10_000;
    }

    private function extractCreativeType(mixed $creative): ?string
    {
        if (! is_array($creative)) {
            return null;
        }

        $type = $creative['object_type']
            ?? $creative['type']
            ?? $creative['creative_type']
            ?? null;

        return is_string($type) && $type !== '' ? $type : null;
    }
}
