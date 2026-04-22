<?php

namespace App\Services\Marketing;

use App\Models\MarketingCampaign;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MarketingCampaignQueryService
{
    public function paginate(
        ?string $search = null,
        ?string $platform = null,
        ?string $status = null,
        int $perPage = 20,
    ): LengthAwarePaginator {
        return MarketingCampaign::query()
            ->with('platformAccount')
            ->when($search, fn (Builder $q) => $q->search($search))
            ->when($platform, fn (Builder $q) => $q->forPlatform($platform))
            ->when($status, fn (Builder $q) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function paginateWithPerformance(
        ?string $search = null,
        ?string $platform = null,
        ?string $status = null,
        int $perPage = 20,
        ?string $sort = null,
        string $direction = 'desc',
    ): LengthAwarePaginator {
        $query = $this->campaignPerformanceQuery($search, $platform, $status);

        $this->applyPerformanceSort($query, $sort, $direction);

        return $query->paginate($perPage);
    }

    public function find(int $id): ?MarketingCampaign
    {
        return MarketingCampaign::query()
            ->with(['platformAccount', 'adSets', 'utms', 'briefs'])
            ->find($id);
    }

    /**
     * MTD performance aggregates grouped by campaign.
     *
     * Returns rows with campaign_id, mtd_spend_micro, mtd_impressions,
     * mtd_clicks, and mtd_conversions.
     */
    public function getMtdCampaignPerformanceAggregates(?array $campaignIds = null): Collection
    {
        return $this->campaignPerformanceAggregateSubquery(null, null, $campaignIds)->get();
    }

    public function getTopCampaignsByMtdPerformance(int $limit = 5): Collection
    {
        return $this->campaignPerformanceQuery()
            ->orderByDesc('mtd_spend_micro')
            ->orderBy('marketing_campaigns.name')
            ->limit($limit)
            ->get();
    }

    /**
     * Daily performance series grouped by campaign and date.
     *
     * Returns rows with campaign_id, date, spend_micro, impressions,
     * clicks, and conversions.
     */
    public function getCampaignDailyPerformanceSeries(int $days = 30, ?array $campaignIds = null): Collection
    {
        $fromDate = now()->startOfDay()->subDays(max($days - 1, 0))->toDateString();

        return DB::table('marketing_spend_snapshots as s')
            ->whereNotNull('s.campaign_id')
            ->when($campaignIds, fn ($query) => $query->whereIn('s.campaign_id', $campaignIds))
            ->where('s.snapshot_date', '>=', $fromDate)
            ->groupBy('s.campaign_id', 's.snapshot_date')
            ->orderBy('s.campaign_id')
            ->orderBy('s.snapshot_date')
            ->select(
                's.campaign_id',
                DB::raw('s.snapshot_date as date'),
                DB::raw('SUM(s.spend_micro) as spend_micro'),
                DB::raw('SUM(s.impressions) as impressions'),
                DB::raw('SUM(s.clicks) as clicks'),
                DB::raw('SUM(s.conversions) as conversions'),
            )
            ->get();
    }

    /**
     * MTD spend totals aggregated per platform.
     * Returns ['meta' => float, 'google' => float].
     */
    public function getMtdSpendByPlatform(): array
    {
        $year = now()->year;
        $month = now()->month;

        $rows = DB::table('marketing_spend_snapshots as s')
            ->join('marketing_platform_accounts as a', 'a.id', '=', 's.platform_account_id')
            ->whereYear('s.snapshot_date', $year)
            ->whereMonth('s.snapshot_date', $month)
            ->groupBy('a.platform')
            ->select('a.platform', DB::raw('SUM(s.spend_micro) as total_micro'))
            ->get()
            ->pluck('total_micro', 'platform');

        return [
            'meta' => ($rows['meta'] ?? 0) / 1_000_000,
            'google' => ($rows['google'] ?? 0) / 1_000_000,
        ];
    }

    /**
     * Daily spend totals for the last N days, for the dashboard chart.
     * Returns a Collection of ['date', 'spend', 'impressions', 'clicks'].
     */
    public function getDailySpendSeries(
        int $days = 30,
        ?string $startDate = null,
        ?string $endDate = null,
    ): Collection {
        [$fromDate, $toDate] = $this->resolveDateRange($days, $startDate, $endDate);

        return DB::table('marketing_spend_snapshots as s')
            ->whereBetween('s.snapshot_date', [$fromDate, $toDate])
            ->groupBy('s.snapshot_date')
            ->orderBy('s.snapshot_date')
            ->select(
                's.snapshot_date as date',
                DB::raw('SUM(s.spend_micro) / 1000000 as spend'),
                DB::raw('SUM(s.impressions) as impressions'),
                DB::raw('SUM(s.clicks) as clicks'),
            )
            ->get();
    }

    /**
     * Counts of active campaigns by platform.
     */
    public function getActiveCampaignCounts(): array
    {
        $rows = DB::table('marketing_campaigns as c')
            ->join('marketing_platform_accounts as a', 'a.id', '=', 'c.platform_account_id')
            ->where('c.status', 'ACTIVE')
            ->groupBy('a.platform')
            ->select('a.platform', DB::raw('COUNT(*) as total'))
            ->get()
            ->pluck('total', 'platform');

        return [
            'meta' => (int) ($rows['meta'] ?? 0),
            'google' => (int) ($rows['google'] ?? 0),
        ];
    }

    public function getCampaignPerformanceRows(
        ?string $search = null,
        ?string $platform = null,
        ?string $status = null,
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $sort = 'name',
        string $direction = 'asc',
    ): Collection {
        $query = $this->campaignPerformanceQuery($search, $platform, $status, $startDate, $endDate);

        $this->applyPerformanceSort($query, $sort, $direction);

        return $query->get();
    }

    /**
     * @param  array<int, int>|null  $campaignIds
     */
    private function campaignPerformanceAggregateSubquery(
        ?string $startDate = null,
        ?string $endDate = null,
        ?array $campaignIds = null,
    ) {
        if ($startDate === null && $endDate === null) {
            $fromDate = now()->startOfMonth()->toDateString();
            $toDate = now()->toDateString();
        } else {
            [$fromDate, $toDate] = $this->resolveDateRange(30, $startDate, $endDate);
        }

        return DB::table('marketing_spend_snapshots as s')
            ->whereNotNull('s.campaign_id')
            ->when($campaignIds, fn ($query) => $query->whereIn('s.campaign_id', $campaignIds))
            ->whereBetween('s.snapshot_date', [$fromDate, $toDate])
            ->groupBy('s.campaign_id')
            ->select(
                's.campaign_id',
                DB::raw('SUM(s.spend_micro) as mtd_spend_micro'),
                DB::raw('SUM(s.impressions) as mtd_impressions'),
                DB::raw('SUM(s.clicks) as mtd_clicks'),
                DB::raw('SUM(s.conversions) as mtd_conversions'),
            );
    }

    private function campaignPerformanceQuery(
        ?string $search = null,
        ?string $platform = null,
        ?string $status = null,
        ?string $startDate = null,
        ?string $endDate = null,
    ): Builder {
        $performance = $this->campaignPerformanceAggregateSubquery($startDate, $endDate);

        return MarketingCampaign::query()
            ->with('platformAccount')
            ->leftJoin('marketing_platform_accounts as platform_accounts', 'marketing_campaigns.platform_account_id', '=', 'platform_accounts.id')
            ->when($search, fn (Builder $q) => $q->search($search))
            ->when($platform, fn (Builder $q) => $q->where('platform_accounts.platform', $platform))
            ->when($status, fn (Builder $q) => $q->where('marketing_campaigns.status', $status))
            ->leftJoinSub($performance, 'campaign_performance', function ($join) {
                $join->on('marketing_campaigns.id', '=', 'campaign_performance.campaign_id');
            })
            ->select('marketing_campaigns.*')
            ->addSelect($this->campaignPerformanceSelects());
    }

    /**
     * @return array<int, \Illuminate\Database\Query\Expression>
     */
    private function campaignPerformanceSelects(): array
    {
        return [
            DB::raw('COALESCE(campaign_performance.mtd_spend_micro, 0) as mtd_spend_micro'),
            DB::raw('COALESCE(campaign_performance.mtd_impressions, 0) as mtd_impressions'),
            DB::raw('COALESCE(campaign_performance.mtd_clicks, 0) as mtd_clicks'),
            DB::raw('COALESCE(campaign_performance.mtd_conversions, 0) as mtd_conversions'),
        ];
    }

    private function applyPerformanceSort(Builder $query, ?string $sort, string $direction): void
    {
        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';

        $sortMap = [
            'name' => 'marketing_campaigns.name',
            'platform' => 'platform_accounts.platform',
            'status' => 'marketing_campaigns.status',
            'daily_budget' => 'marketing_campaigns.daily_budget_micro',
            'mtd_spend' => 'mtd_spend_micro',
            'impressions' => 'mtd_impressions',
            'clicks' => 'mtd_clicks',
            'conversions' => 'mtd_conversions',
            'last_synced' => 'marketing_campaigns.last_synced_at',
        ];

        if (! isset($sortMap[$sort ?? ''])) {
            $query->orderByDesc('marketing_campaigns.created_at')
                ->orderBy('marketing_campaigns.id');

            return;
        }

        $query->orderBy($sortMap[$sort], $direction)
            ->orderBy('marketing_campaigns.name')
            ->orderBy('marketing_campaigns.id');
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveDateRange(int $days = 30, ?string $startDate = null, ?string $endDate = null): array
    {
        $from = $startDate !== null
            ? Carbon::parse($startDate)->startOfDay()
            : now()->subDays(max($days - 1, 0))->startOfDay();

        $to = $endDate !== null
            ? Carbon::parse($endDate)->endOfDay()
            : now()->endOfDay();

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        return [$from->toDateString(), $to->toDateString()];
    }
}
