<?php

namespace App\Services\Marketing;

use App\Models\MarketingAd;
use App\Models\MarketingAdSet;
use App\Models\MarketingCampaign;
use App\Models\MarketingPlatformAccount;
use App\Models\MarketingSpendSnapshot;

class MarketingSpendSyncService
{
    public function __construct(
        protected MetaApiService $metaApi,
        protected GoogleAdsApiService $googleAdsApi,
    ) {}

    /**
     * Sync daily spend snapshots for a Meta account on a specific date.
     * Uses ad-level insights so each row can roll up to campaign and ad set reports.
     * Returns the number of snapshot rows upserted.
     *
     * @throws \RuntimeException on API failure
     */
    public function syncMetaSpend(MarketingPlatformAccount $account, string $date): int
    {
        $rows = $this->metaApi->fetchDailyInsights($account->external_account_id, $date);

        if (empty($rows)) {
            return 0;
        }

        // Build lookup maps from external IDs → local DB IDs to avoid N+1 queries.
        $externalCampaignIds = array_unique(array_filter(array_column($rows, 'campaign_id')));
        $externalAdSetIds = array_unique(array_filter(array_column($rows, 'adset_id')));
        $externalAdIds = array_unique(array_filter(array_column($rows, 'ad_id')));

        $campaignIdMap = MarketingCampaign::query()
            ->where('platform_account_id', $account->id)
            ->whereIn('external_campaign_id', $externalCampaignIds)
            ->pluck('id', 'external_campaign_id');

        $adSetIdMap = MarketingAdSet::query()
            ->whereHas('campaign', fn ($q) => $q->where('platform_account_id', $account->id))
            ->whereIn('external_adset_id', $externalAdSetIds)
            ->pluck('id', 'external_adset_id');

        $adIdMap = MarketingAd::query()
            ->whereHas('adSet.campaign', fn ($q) => $q->where('platform_account_id', $account->id))
            ->whereIn('external_ad_id', $externalAdIds)
            ->pluck('id', 'external_ad_id');

        $synced = 0;

        foreach ($rows as $row) {
            $localCampaignId = $campaignIdMap[$row['campaign_id'] ?? ''] ?? null;
            $localAdSetId = $adSetIdMap[$row['adset_id'] ?? ''] ?? null;
            $localAdId = $adIdMap[$row['ad_id'] ?? ''] ?? null;

            if (! $localCampaignId || ! $localAdSetId) {
                // Skip rows whose parent campaign/adset hasn't been synced yet.
                continue;
            }

            if (($row['ad_id'] ?? null) && ! $localAdId) {
                continue;
            }

            $snapshotDate = $row['date_start'] ?? $date;
            if ($localAdId) {
                $this->deleteAggregateAdSetSnapshot(
                    accountId: $account->id,
                    campaignId: $localCampaignId,
                    adSetId: $localAdSetId,
                    date: $snapshotDate,
                );
            }

            $this->upsertSnapshot(
                accountId: $account->id,
                campaignId: $localCampaignId,
                adSetId: $localAdSetId,
                adId: $localAdId,
                date: $snapshotDate,
                impressions: (int) ($row['impressions'] ?? 0),
                reach: (int) ($row['reach'] ?? 0),
                clicks: (int) ($row['clicks'] ?? 0),
                spendMicro: $this->spendToMicros((string) ($row['spend'] ?? '0')),
                conversions: $this->extractConversions($row['actions'] ?? []),
                rawRow: $row,
            );

            $synced++;
        }

        return $synced;
    }

    /**
     * Sync daily campaign-level spend snapshots for a Google Ads customer.
     * Google Ads V1 stores these rows with campaign_id set and ad_set_id null.
     *
     * @throws \RuntimeException on API failure
     */
    public function syncGoogleSpend(MarketingPlatformAccount $account, string $date): int
    {
        $rows = $this->googleAdsApi->fetchDailySpend($account->external_account_id, $date);

        if (empty($rows)) {
            return 0;
        }

        $externalCampaignIds = array_unique(array_filter(array_map(
            fn (array $row) => isset($row['campaign_id']) ? (string) $row['campaign_id'] : null,
            $rows,
        )));

        $campaignIdMap = MarketingCampaign::query()
            ->where('platform_account_id', $account->id)
            ->whereIn('external_campaign_id', $externalCampaignIds)
            ->pluck('id', 'external_campaign_id');

        $synced = 0;

        foreach ($rows as $row) {
            $localCampaignId = $campaignIdMap[(string) ($row['campaign_id'] ?? '')] ?? null;
            if (! $localCampaignId) {
                continue;
            }

            $this->upsertSnapshot(
                accountId: $account->id,
                campaignId: $localCampaignId,
                adSetId: null,
                adId: null,
                date: $row['date'] ?? $date,
                impressions: (int) ($row['impressions'] ?? 0),
                reach: (int) ($row['reach'] ?? 0),
                clicks: (int) ($row['clicks'] ?? 0),
                spendMicro: (int) ($row['cost_micros'] ?? 0),
                conversions: (int) round((float) ($row['conversions'] ?? $row['all_conversions'] ?? 0)),
                rawRow: $row,
            );

            $synced++;
        }

        return $synced;
    }

    private function upsertSnapshot(
        int $accountId,
        int $campaignId,
        ?int $adSetId,
        ?int $adId,
        string $date,
        int $impressions,
        int $reach,
        int $clicks,
        int $spendMicro,
        int $conversions,
        array $rawRow,
    ): void {
        $existing = MarketingSpendSnapshot::query()
            ->where('platform_account_id', $accountId)
            ->where('campaign_id', $campaignId)
            ->where('ad_set_id', $adSetId)
            ->where('ad_id', $adId)
            ->where('snapshot_date', $date)
            ->first();

        $data = [
            'impressions' => $impressions,
            'reach' => $reach,
            'clicks' => $clicks,
            'spend_micro' => $spendMicro,
            'conversions' => $conversions,
            'platform_data' => $rawRow,
        ];

        if ($existing) {
            $existing->update($data);
        } else {
            MarketingSpendSnapshot::query()->create(array_merge($data, [
                'platform_account_id' => $accountId,
                'campaign_id' => $campaignId,
                'ad_set_id' => $adSetId,
                'ad_id' => $adId,
                'snapshot_date' => $date,
            ]));
        }
    }

    private function deleteAggregateAdSetSnapshot(int $accountId, int $campaignId, int $adSetId, string $date): void
    {
        MarketingSpendSnapshot::query()
            ->where('platform_account_id', $accountId)
            ->where('campaign_id', $campaignId)
            ->where('ad_set_id', $adSetId)
            ->whereNull('ad_id')
            ->where('snapshot_date', $date)
            ->delete();
    }

    /**
     * Meta returns spend as a decimal string in the account's base currency
     * (e.g. "12.34" = $12.34 USD). Convert to micros for consistent storage.
     */
    private function spendToMicros(string $spend): int
    {
        return (int) round(floatval($spend) * 1_000_000);
    }

    /**
     * Extract total purchase/lead conversion count from Meta's actions array.
     * Meta returns actions as [{action_type: "purchase", value: "3"}, ...].
     */
    private function extractConversions(array $actions): int
    {
        $conversionTypes = ['purchase', 'lead', 'complete_registration', 'offsite_conversion.fb_pixel_purchase'];
        $total = 0;

        foreach ($actions as $action) {
            if (in_array($action['action_type'] ?? '', $conversionTypes, true)) {
                $total += (int) round(floatval($action['value'] ?? 0));
            }
        }

        return $total;
    }
}
