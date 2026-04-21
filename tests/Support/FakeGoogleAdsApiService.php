<?php

namespace Tests\Support;

use App\Services\Marketing\GoogleAdsApiService;

class FakeGoogleAdsApiService extends GoogleAdsApiService
{
    /**
     * @param  array<int, array<string, mixed>>  $campaigns
     * @param  array<int, array<string, mixed>>  $adGroups
     * @param  array<int, array<string, mixed>>  $dailySpend
     */
    public function __construct(
        public array $campaigns = [],
        public array $adGroups = [],
        public array $dailySpend = [],
    ) {}

    /**
     * @var array<int, array{method: string, customer_id: string, date?: string}>
     */
    public array $calls = [];

    public function fetchCampaigns(string $customerId): array
    {
        $this->calls[] = [
            'method' => 'fetchCampaigns',
            'customer_id' => $customerId,
        ];

        return $this->campaigns;
    }

    public function fetchAdGroups(string $customerId): array
    {
        $this->calls[] = [
            'method' => 'fetchAdGroups',
            'customer_id' => $customerId,
        ];

        return $this->adGroups;
    }

    public function fetchDailySpend(string $customerId, string $date): array
    {
        $this->calls[] = [
            'method' => 'fetchDailySpend',
            'customer_id' => $customerId,
            'date' => $date,
        ];

        return $this->dailySpend;
    }
}
