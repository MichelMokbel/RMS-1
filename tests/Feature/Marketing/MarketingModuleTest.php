<?php

use App\Jobs\Marketing\SyncMarketingCampaignsJob;
use App\Jobs\Marketing\SyncMarketingSpendJob;
use App\Models\MarketingAd;
use App\Models\MarketingAdSet;
use App\Models\MarketingCampaign;
use App\Models\MarketingPlatformAccount;
use App\Models\MarketingSetting;
use App\Models\MarketingSpendSnapshot;
use App\Models\User;
use App\Services\Marketing\MarketingCampaignQueryService;
use App\Services\Marketing\MarketingCampaignSyncService;
use App\Services\Marketing\MarketingSettingsService;
use App\Services\Marketing\MarketingSpendSyncService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\FakeGoogleAdsApiService;

function seedMarketingCampaignPerformance(
    MarketingPlatformAccount $account,
    array $campaignAttributes,
    array $snapshots,
): MarketingCampaign {
    $campaign = MarketingCampaign::query()->create(array_merge([
        'platform_account_id' => $account->id,
    ], $campaignAttributes));

    foreach ($snapshots as $snapshot) {
        MarketingSpendSnapshot::query()->create(array_merge([
            'platform_account_id' => $account->id,
            'campaign_id' => $campaign->id,
            'ad_set_id' => null,
        ], $snapshot));
    }

    return $campaign;
}

function bindFakeGoogleAdsApi(
    array $campaigns = [],
    array $adGroups = [],
    array $dailySpend = [],
): \App\Services\Marketing\GoogleAdsApiService {
    $fake = new FakeGoogleAdsApiService($campaigns, $adGroups, $dailySpend);

    app()->instance(\App\Services\Marketing\GoogleAdsApiService::class, $fake);

    return $fake;
}

it('stores marketing credentials encrypted and gates sync by platform settings', function (): void {
    $user = User::factory()->create();

    $settings = app(MarketingSettingsService::class)->save([
        'meta_app_id' => '123456',
        'meta_system_user_token' => 'meta-secret-token',
        'meta_sync_enabled' => true,
        'google_sync_enabled' => false,
    ], $user->id);

    $rawSettings = MarketingSetting::query()->firstOrFail();

    expect($rawSettings->meta_system_user_token)->not->toBe('meta-secret-token')
        ->and(Crypt::decryptString($rawSettings->meta_system_user_token))->toBe('meta-secret-token')
        ->and(app(MarketingSettingsService::class)->decrypt($settings, 'meta_system_user_token'))->toBe('meta-secret-token')
        ->and(app(MarketingSettingsService::class)->isSyncEnabledFor('meta'))->toBeTrue()
        ->and(app(MarketingSettingsService::class)->isSyncEnabledFor('google'))->toBeFalse();
});

it('aggregates dashboard spend from ad set level snapshots', function (): void {
    $account = MarketingPlatformAccount::query()->create([
        'platform' => 'meta',
        'external_account_id' => 'act_123456',
        'account_name' => 'Meta Account',
        'status' => 'active',
    ]);

    $campaign = MarketingCampaign::query()->create([
        'platform_account_id' => $account->id,
        'external_campaign_id' => 'campaign-1',
        'name' => 'Spring Campaign',
        'status' => 'ACTIVE',
    ]);

    $adSet = MarketingAdSet::query()->create([
        'campaign_id' => $campaign->id,
        'external_adset_id' => 'adset-1',
        'name' => 'Prospecting',
        'status' => 'ACTIVE',
    ]);

    MarketingSpendSnapshot::query()->create([
        'platform_account_id' => $account->id,
        'campaign_id' => $campaign->id,
        'ad_set_id' => $adSet->id,
        'snapshot_date' => now()->toDateString(),
        'impressions' => 1000,
        'reach' => 750,
        'clicks' => 75,
        'spend_micro' => 12_500_000,
    ]);

    $query = app(MarketingCampaignQueryService::class);

    expect($query->getMtdSpendByPlatform()['meta'])->toBe(12.5);

    $dailyRow = $query->getDailySpendSeries()->first();

    expect((float) $dailyRow->spend)->toBe(12.5)
        ->and((int) $dailyRow->impressions)->toBe(1000)
        ->and((int) $dailyRow->reach)->toBe(750)
        ->and((int) $dailyRow->clicks)->toBe(75);
});

it('syncs google campaigns and ad groups through the bound google ads api service', function (): void {
    $account = MarketingPlatformAccount::query()->create([
        'platform' => 'google',
        'external_account_id' => '123-456-7890',
        'account_name' => 'Google Ads Account',
        'status' => 'active',
    ]);

    $existingCampaign = MarketingCampaign::query()->create([
        'platform_account_id' => $account->id,
        'external_campaign_id' => '100',
        'name' => 'Old Search',
        'status' => 'PAUSED',
        'objective' => 'OLD',
        'daily_budget_micro' => 500_000,
        'start_date' => '2026-03-01',
        'end_date' => '2026-03-31',
    ]);

    MarketingAdSet::query()->create([
        'campaign_id' => $existingCampaign->id,
        'external_adset_id' => '300',
        'name' => 'Old Ad Group',
        'status' => 'PAUSED',
        'daily_budget_micro' => null,
        'platform_data' => ['id' => '300', 'name' => 'Old Ad Group'],
    ]);

    $fakeGoogleAdsApi = bindFakeGoogleAdsApi(
        campaigns: [
            [
                'id' => '100',
                'name' => 'Search - Updated',
                'status' => 'ENABLED',
                'advertising_channel_type' => 'SEARCH',
                'daily_budget_micro' => 2_500_000,
                'start_date' => '2026-04-01',
                'end_date' => '2026-04-30',
            ],
            [
                'id' => '200',
                'name' => 'Display',
                'status' => 'PAUSED',
                'advertising_channel_type' => 'DISPLAY',
                'daily_budget_micro' => 1_000_000,
            ],
        ],
        adGroups: [
            [
                'id' => '300',
                'campaign_id' => '100',
                'name' => 'Search Ad Group Updated',
                'status' => 'ENABLED',
            ],
            [
                'id' => '400',
                'campaign_id' => '200',
                'name' => 'Display Ad Group',
                'status' => 'PAUSED',
            ],
        ],
    );

    $synced = app(MarketingCampaignSyncService::class)->syncGoogle($account);

    expect($synced)->toBe(4)
        ->and($fakeGoogleAdsApi->calls)->toHaveCount(2);

    $existingCampaign->refresh();
    $updatedCampaign = MarketingCampaign::query()->where('external_campaign_id', '200')->firstOrFail();
    $updatedAdSet = MarketingAdSet::query()->where('external_adset_id', '300')->firstOrFail();
    $newAdSet = MarketingAdSet::query()->where('external_adset_id', '400')->firstOrFail();

    expect(MarketingCampaign::query()->count())->toBe(2)
        ->and(MarketingAdSet::query()->count())->toBe(2)
        ->and($existingCampaign->name)->toBe('Search - Updated')
        ->and($existingCampaign->status)->toBe('ENABLED')
        ->and($existingCampaign->objective)->toBe('SEARCH')
        ->and($existingCampaign->daily_budget_micro)->toBe(2_500_000)
        ->and($existingCampaign->platform_data)->toBe($fakeGoogleAdsApi->campaigns[0])
        ->and($updatedCampaign->name)->toBe('Display')
        ->and($updatedCampaign->objective)->toBe('DISPLAY')
        ->and($updatedCampaign->daily_budget_micro)->toBe(1_000_000)
        ->and($updatedAdSet->name)->toBe('Search Ad Group Updated')
        ->and($updatedAdSet->status)->toBe('ENABLED')
        ->and($updatedAdSet->platform_data)->toBe($fakeGoogleAdsApi->adGroups[0])
        ->and($newAdSet->name)->toBe('Display Ad Group')
        ->and($newAdSet->campaign_id)->toBe($updatedCampaign->id)
        ->and($newAdSet->platform_data)->toBe($fakeGoogleAdsApi->adGroups[1]);
});

it('syncs google campaign spend snapshots at campaign level', function (): void {
    $account = MarketingPlatformAccount::query()->create([
        'platform' => 'google',
        'external_account_id' => '123-456-7890',
        'account_name' => 'Google Ads Account',
        'status' => 'active',
    ]);

    $campaignOne = MarketingCampaign::query()->create([
        'platform_account_id' => $account->id,
        'external_campaign_id' => '100',
        'name' => 'Search',
        'status' => 'ENABLED',
    ]);

    $campaignTwo = MarketingCampaign::query()->create([
        'platform_account_id' => $account->id,
        'external_campaign_id' => '200',
        'name' => 'Display',
        'status' => 'PAUSED',
    ]);

    MarketingSpendSnapshot::query()->create([
        'platform_account_id' => $account->id,
        'campaign_id' => $campaignOne->id,
        'ad_set_id' => null,
        'snapshot_date' => '2026-04-20',
        'impressions' => 10,
        'clicks' => 1,
        'spend_micro' => 100_000,
        'conversions' => 0,
        'platform_data' => ['date' => '2026-04-20', 'campaign_id' => '100'],
    ]);

    $fakeGoogleAdsApi = bindFakeGoogleAdsApi(dailySpend: [
        [
            'campaign_id' => '100',
            'date' => '2026-04-20',
            'impressions' => 120,
            'clicks' => 12,
            'cost_micros' => 1_230_000,
            'conversions' => '3',
        ],
        [
            'campaign_id' => '200',
            'date' => '2026-04-20',
            'impressions' => 220,
            'clicks' => 22,
            'cost_micros' => 2_340_000,
            'conversions' => 4,
        ],
    ]);

    $synced = app(MarketingSpendSyncService::class)->syncGoogleSpend($account, '2026-04-20');

    expect($synced)->toBe(2)
        ->and($fakeGoogleAdsApi->calls)->toHaveCount(1);

    $campaignOneSnapshot = MarketingSpendSnapshot::query()
        ->where('campaign_id', $campaignOne->id)
        ->where('ad_set_id', null)
        ->where('snapshot_date', '2026-04-20')
        ->firstOrFail();

    $campaignTwoSnapshot = MarketingSpendSnapshot::query()
        ->where('campaign_id', $campaignTwo->id)
        ->where('ad_set_id', null)
        ->where('snapshot_date', '2026-04-20')
        ->firstOrFail();

    expect(MarketingSpendSnapshot::query()->count())->toBe(2)
        ->and($campaignOneSnapshot->impressions)->toBe(120)
        ->and($campaignOneSnapshot->clicks)->toBe(12)
        ->and($campaignOneSnapshot->spend_micro)->toBe(1_230_000)
        ->and($campaignOneSnapshot->conversions)->toBe(3)
        ->and($campaignOneSnapshot->platform_data)->toBe($fakeGoogleAdsApi->dailySpend[0])
        ->and($campaignOneSnapshot->ad_set_id)->toBeNull()
        ->and($campaignTwoSnapshot->impressions)->toBe(220)
        ->and($campaignTwoSnapshot->clicks)->toBe(22)
        ->and($campaignTwoSnapshot->spend_micro)->toBe(2_340_000)
        ->and($campaignTwoSnapshot->conversions)->toBe(4)
        ->and($campaignTwoSnapshot->platform_data)->toBe($fakeGoogleAdsApi->dailySpend[1])
        ->and($campaignTwoSnapshot->ad_set_id)->toBeNull();
});

it('dispatches marketing sync jobs for google accounts from the sync trigger', function (): void {
    $user = User::factory()->create(['status' => 'active']);
    Permission::findOrCreate('marketing.manage', 'web');
    $user->givePermissionTo('marketing.manage');

    app(MarketingSettingsService::class)->save([
        'google_login_customer_id' => '123-456-7890',
        'google_client_id' => 'google-client-id',
        'google_developer_token' => 'google-developer-token',
        'google_refresh_token' => 'google-refresh-token',
        'google_client_secret' => 'google-client-secret',
        'google_sync_enabled' => true,
    ], $user->id);

    $googleAccount = MarketingPlatformAccount::query()->create([
        'platform' => 'google',
        'external_account_id' => '123-456-7890',
        'account_name' => 'Google Ads Account',
        'status' => 'active',
    ]);

    config()->set('marketing.sync.spend_lookback_days', 2);
    Bus::fake();
    Carbon::setTestNow('2026-04-21 09:00:00');

    try {
        $this->actingAs($user)
            ->post(route('marketing.sync.trigger'))
            ->assertOk()
            ->assertJson([
                'message' => 'Sync jobs dispatched for 1 account(s).',
                'accounts' => 1,
            ]);
    } finally {
        Carbon::setTestNow();
    }

    Bus::assertDispatched(SyncMarketingCampaignsJob::class, function (SyncMarketingCampaignsJob $job) use ($googleAccount): bool {
        return $job->platformAccountId === $googleAccount->id;
    });

    Bus::assertDispatched(SyncMarketingSpendJob::class, function (SyncMarketingSpendJob $job) use ($googleAccount): bool {
        return $job->platformAccountId === $googleAccount->id
            && $job->date === '2026-04-20';
    });

    Bus::assertDispatched(SyncMarketingSpendJob::class, function (SyncMarketingSpendJob $job) use ($googleAccount): bool {
        return $job->platformAccountId === $googleAccount->id
            && $job->date === '2026-04-19';
    });
});

it('redirects admins to google oauth with the ads scope', function (): void {
    $user = User::factory()->create(['status' => 'active']);
    Permission::findOrCreate('marketing.manage', 'web');
    $user->givePermissionTo('marketing.manage');

    app(MarketingSettingsService::class)->save([
        'google_client_id' => 'google-client-id.apps.googleusercontent.com',
        'google_client_secret' => 'google-client-secret',
    ], $user->id);

    $response = $this->actingAs($user)->get(route('marketing.google.oauth.redirect'));

    $response->assertRedirect();

    $location = (string) $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth?')
        ->and($query['client_id'])->toBe('google-client-id.apps.googleusercontent.com')
        ->and($query['scope'])->toBe('https://www.googleapis.com/auth/adwords')
        ->and($query['access_type'])->toBe('offline')
        ->and($query['prompt'])->toBe('consent')
        ->and($query['redirect_uri'])->toBe(route('marketing.google.oauth.callback'))
        ->and(session('marketing_google_oauth_state'))->toBe($query['state']);
});

it('exchanges google oauth callback code and stores the refresh token encrypted', function (): void {
    $user = User::factory()->create(['status' => 'active']);
    Permission::findOrCreate('marketing.manage', 'web');
    $user->givePermissionTo('marketing.manage');

    app(MarketingSettingsService::class)->save([
        'google_client_id' => 'google-client-id.apps.googleusercontent.com',
        'google_client_secret' => 'google-client-secret',
    ], $user->id);

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'google-access-token',
            'expires_in' => 3600,
            'refresh_token' => 'google-refresh-token-from-oauth',
            'scope' => 'https://www.googleapis.com/auth/adwords',
            'token_type' => 'Bearer',
        ]),
    ]);

    $response = $this
        ->actingAs($user)
        ->withSession(['marketing_google_oauth_state' => 'state-token'])
        ->get(route('marketing.google.oauth.callback', [
            'code' => 'authorization-code',
            'state' => 'state-token',
        ]));

    $response->assertRedirect(route('marketing.settings'));
    $response->assertSessionHas('status', 'Google Ads connected. The OAuth refresh token has been saved securely.');

    $settings = MarketingSetting::query()->firstOrFail();

    expect($settings->google_refresh_token)->not->toBe('google-refresh-token-from-oauth')
        ->and(Crypt::decryptString($settings->google_refresh_token))->toBe('google-refresh-token-from-oauth');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://oauth2.googleapis.com/token'
        && $request['code'] === 'authorization-code'
        && $request['client_id'] === 'google-client-id.apps.googleusercontent.com'
        && $request['client_secret'] === 'google-client-secret'
        && $request['redirect_uri'] === route('marketing.google.oauth.callback')
        && $request['grant_type'] === 'authorization_code');
});

it('rejects google oauth callbacks with an invalid state', function (): void {
    $user = User::factory()->create(['status' => 'active']);
    Permission::findOrCreate('marketing.manage', 'web');
    $user->givePermissionTo('marketing.manage');

    Http::fake();

    $response = $this
        ->actingAs($user)
        ->withSession(['marketing_google_oauth_state' => 'expected-state'])
        ->get(route('marketing.google.oauth.callback', [
            'code' => 'authorization-code',
            'state' => 'wrong-state',
        ]));

    $response->assertRedirect(route('marketing.settings'));
    $response->assertSessionHas('status', 'Google Ads connection failed because the OAuth state did not match. Try connecting again.');

    expect(MarketingSetting::query()->exists())->toBeFalse();
    Http::assertNothingSent();
});

it('adds google ads customer accounts from marketing settings', function (): void {
    $user = User::factory()->create(['status' => 'active']);
    Permission::findOrCreate('marketing.manage', 'web');
    $user->givePermissionTo('marketing.manage');

    $this->actingAs($user);

    Volt::test('marketing.settings')
        ->set('google_customer_id', '123-456-7890')
        ->set('google_account_name', 'Layla Search Account')
        ->call('addGoogleAccount')
        ->assertHasNoErrors()
        ->assertSet('google_customer_id', '')
        ->assertSet('google_account_name', '');

    $this->assertDatabaseHas('marketing_platform_accounts', [
        'platform' => 'google',
        'external_account_id' => '1234567890',
        'account_name' => 'Layla Search Account',
        'status' => 'active',
        'created_by' => $user->id,
    ]);
});

it('prevents duplicate google ads customer accounts after id normalization', function (): void {
    $user = User::factory()->create(['status' => 'active']);
    Permission::findOrCreate('marketing.manage', 'web');
    $user->givePermissionTo('marketing.manage');

    MarketingPlatformAccount::query()->create([
        'platform' => 'google',
        'external_account_id' => '1234567890',
        'account_name' => 'Existing Google Account',
        'status' => 'active',
        'created_by' => $user->id,
    ]);

    $this->actingAs($user);

    Volt::test('marketing.settings')
        ->set('google_customer_id', '123-456-7890')
        ->set('google_account_name', 'Duplicate Google Account')
        ->call('addGoogleAccount')
        ->assertHasErrors(['google_customer_id']);

    expect(MarketingPlatformAccount::query()->where('platform', 'google')->count())->toBe(1);
});

it('sorts marketing campaigns by supported performance columns', function (): void {
    $metaAccount = MarketingPlatformAccount::query()->create([
        'platform' => 'meta',
        'external_account_id' => 'act_meta_1',
        'account_name' => 'Meta Account',
        'status' => 'active',
    ]);

    $googleAccount = MarketingPlatformAccount::query()->create([
        'platform' => 'google',
        'external_account_id' => 'act_google_1',
        'account_name' => 'Google Account',
        'status' => 'active',
    ]);

    seedMarketingCampaignPerformance($metaAccount, [
        'external_campaign_id' => 'camp-alpha',
        'name' => 'Alpha',
        'status' => 'ACTIVE',
        'daily_budget_micro' => 1_000_000,
        'last_synced_at' => '2026-04-01 10:00:00',
    ], [[
        'snapshot_date' => '2026-04-10',
        'impressions' => 100,
        'clicks' => 10,
        'spend_micro' => 1_000_000,
        'conversions' => 1,
    ]]);

    seedMarketingCampaignPerformance($googleAccount, [
        'external_campaign_id' => 'camp-bravo',
        'name' => 'Bravo',
        'status' => 'PAUSED',
        'daily_budget_micro' => 3_000_000,
        'last_synced_at' => '2026-04-03 10:00:00',
    ], [[
        'snapshot_date' => '2026-04-10',
        'impressions' => 300,
        'clicks' => 30,
        'spend_micro' => 3_000_000,
        'conversions' => 3,
    ]]);

    seedMarketingCampaignPerformance($metaAccount, [
        'external_campaign_id' => 'camp-charlie',
        'name' => 'Charlie',
        'status' => 'ARCHIVED',
        'daily_budget_micro' => 2_000_000,
        'last_synced_at' => '2026-04-02 10:00:00',
    ], [[
        'snapshot_date' => '2026-04-10',
        'impressions' => 200,
        'clicks' => 20,
        'spend_micro' => 2_000_000,
        'conversions' => 2,
    ]]);

    $query = app(MarketingCampaignQueryService::class);

    $cases = [
        ['name', 'asc', ['Alpha', 'Bravo', 'Charlie']],
        ['name', 'desc', ['Charlie', 'Bravo', 'Alpha']],
        ['platform', 'asc', ['Bravo', 'Alpha', 'Charlie']],
        ['platform', 'desc', ['Alpha', 'Charlie', 'Bravo']],
        ['status', 'asc', ['Alpha', 'Charlie', 'Bravo']],
        ['status', 'desc', ['Bravo', 'Charlie', 'Alpha']],
        ['daily_budget', 'asc', ['Alpha', 'Charlie', 'Bravo']],
        ['daily_budget', 'desc', ['Bravo', 'Charlie', 'Alpha']],
        ['mtd_spend', 'asc', ['Alpha', 'Charlie', 'Bravo']],
        ['mtd_spend', 'desc', ['Bravo', 'Charlie', 'Alpha']],
        ['impressions', 'asc', ['Alpha', 'Charlie', 'Bravo']],
        ['clicks', 'asc', ['Alpha', 'Charlie', 'Bravo']],
        ['conversions', 'asc', ['Alpha', 'Charlie', 'Bravo']],
        ['last_synced', 'asc', ['Alpha', 'Charlie', 'Bravo']],
        ['last_synced', 'desc', ['Bravo', 'Charlie', 'Alpha']],
    ];

    foreach ($cases as [$sort, $direction, $expected]) {
        $names = $query->paginateWithPerformance(
            perPage: 10,
            sort: $sort,
            direction: $direction,
        )->getCollection()->pluck('name')->all();

        expect($names)->toBe($expected);
    }
});

it('filters daily spend series by an explicit date range', function (): void {
    $account = MarketingPlatformAccount::query()->create([
        'platform' => 'meta',
        'external_account_id' => 'act_456789',
        'account_name' => 'Meta Range Account',
        'status' => 'active',
    ]);

    $campaign = MarketingCampaign::query()->create([
        'platform_account_id' => $account->id,
        'external_campaign_id' => 'camp-range',
        'name' => 'Range Campaign',
        'status' => 'ACTIVE',
    ]);

    MarketingSpendSnapshot::query()->create([
        'platform_account_id' => $account->id,
        'campaign_id' => $campaign->id,
        'ad_set_id' => null,
        'snapshot_date' => '2026-04-08',
        'impressions' => 100,
        'clicks' => 10,
        'spend_micro' => 1_000_000,
        'conversions' => 1,
    ]);

    MarketingSpendSnapshot::query()->create([
        'platform_account_id' => $account->id,
        'campaign_id' => $campaign->id,
        'ad_set_id' => null,
        'snapshot_date' => '2026-04-10',
        'impressions' => 300,
        'clicks' => 30,
        'spend_micro' => 3_500_000,
        'conversions' => 4,
    ]);

    $rows = app(MarketingCampaignQueryService::class)->getDailySpendSeries(
        days: 30,
        startDate: '2026-04-10',
        endDate: '2026-04-10',
    );

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->date)->toBe('2026-04-10')
        ->and((float) $rows->first()->spend)->toBe(3.5)
        ->and((int) $rows->first()->impressions)->toBe(300)
        ->and((int) $rows->first()->clicks)->toBe(30);
});

it('builds campaign drilldown aggregates for campaign ad sets and ads', function (): void {
    $account = MarketingPlatformAccount::query()->create([
        'platform' => 'meta',
        'external_account_id' => 'act_drilldown',
        'account_name' => 'Meta Drilldown Account',
        'status' => 'active',
    ]);

    $campaign = MarketingCampaign::query()->create([
        'platform_account_id' => $account->id,
        'external_campaign_id' => 'camp-drilldown',
        'name' => 'Drilldown Campaign',
        'status' => 'ACTIVE',
    ]);

    $adSet = MarketingAdSet::query()->create([
        'campaign_id' => $campaign->id,
        'external_adset_id' => 'adset-drilldown',
        'name' => 'Prospecting',
        'status' => 'ACTIVE',
        'daily_budget_micro' => 5_000_000,
    ]);

    $ad = MarketingAd::query()->create([
        'ad_set_id' => $adSet->id,
        'external_ad_id' => 'ad-drilldown',
        'name' => 'Carousel Ad',
        'status' => 'ACTIVE',
        'creative_type' => 'carousel',
    ]);

    MarketingSpendSnapshot::query()->create([
        'platform_account_id' => $account->id,
        'campaign_id' => $campaign->id,
        'ad_set_id' => $adSet->id,
        'ad_id' => $ad->id,
        'snapshot_date' => '2026-04-20',
        'impressions' => 1000,
        'reach' => 800,
        'clicks' => 50,
        'spend_micro' => 2_500_000,
        'conversions' => 4,
    ]);

    $query = app(MarketingCampaignQueryService::class);

    $summary = $query->getCampaignPerformanceSummary($campaign->id, '2026-04-20', '2026-04-20');
    $adSetRows = $query->getAdSetPerformanceRows($campaign->id, '2026-04-20', '2026-04-20');
    $adRows = $query->getAdPerformanceRows($campaign->id, '2026-04-20', '2026-04-20', $adSet->id);
    $dailySeries = $query->getCampaignDailySeriesForRange($campaign->id, '2026-04-20', '2026-04-20', $adSet->id);

    expect((int) $summary->spend_micro)->toBe(2_500_000)
        ->and((int) $summary->reach)->toBe(800)
        ->and($adSetRows)->toHaveCount(1)
        ->and($adSetRows->first()->name)->toBe('Prospecting')
        ->and((int) $adSetRows->first()->spend_micro)->toBe(2_500_000)
        ->and($adRows)->toHaveCount(1)
        ->and($adRows->first()->name)->toBe('Carousel Ad')
        ->and((int) $adRows->first()->reach)->toBe(800)
        ->and($dailySeries)->toHaveCount(1)
        ->and((int) $dailySeries->first()->clicks)->toBe(50);
});

it('exports marketing campaigns using the requested date range', function (): void {
    Role::findOrCreate('manager', 'web');

    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('manager');

    $account = MarketingPlatformAccount::query()->create([
        'platform' => 'meta',
        'external_account_id' => 'act_export_1',
        'account_name' => 'Meta Export Account',
        'status' => 'active',
    ]);

    MarketingCampaign::query()->create([
        'platform_account_id' => $account->id,
        'external_campaign_id' => 'camp-export-1',
        'name' => 'Range Campaign',
        'status' => 'ACTIVE',
        'objective' => 'Awareness',
        'daily_budget_micro' => 1_000_000,
        'last_synced_at' => '2026-04-10 09:00:00',
    ]);

    MarketingCampaign::query()->create([
        'platform_account_id' => $account->id,
        'external_campaign_id' => 'camp-export-2',
        'name' => 'Other Campaign',
        'status' => 'PAUSED',
        'objective' => 'Traffic',
        'daily_budget_micro' => 2_000_000,
        'last_synced_at' => '2026-04-10 10:00:00',
    ]);

    $rangeCampaign = MarketingCampaign::query()->where('name', 'Range Campaign')->firstOrFail();
    $otherCampaign = MarketingCampaign::query()->where('name', 'Other Campaign')->firstOrFail();

    MarketingSpendSnapshot::query()->create([
        'platform_account_id' => $account->id,
        'campaign_id' => $rangeCampaign->id,
        'ad_set_id' => null,
        'snapshot_date' => '2026-04-01',
        'impressions' => 100,
        'clicks' => 10,
        'spend_micro' => 1_000_000,
        'conversions' => 1,
    ]);

    MarketingSpendSnapshot::query()->create([
        'platform_account_id' => $account->id,
        'campaign_id' => $rangeCampaign->id,
        'ad_set_id' => null,
        'snapshot_date' => '2026-04-10',
        'impressions' => 900,
        'clicks' => 90,
        'spend_micro' => 9_000_000,
        'conversions' => 9,
    ]);

    MarketingSpendSnapshot::query()->create([
        'platform_account_id' => $account->id,
        'campaign_id' => $otherCampaign->id,
        'ad_set_id' => null,
        'snapshot_date' => '2026-04-10',
        'impressions' => 200,
        'clicks' => 20,
        'spend_micro' => 2_000_000,
        'conversions' => 2,
    ]);

    $response = $this->actingAs($user)->get(route('marketing.campaigns.export', [
        'date_from' => '2026-04-10',
        'date_to' => '2026-04-10',
    ]));

    $response->assertOk();
    expect((string) $response->headers->get('content-type'))->toContain('text/csv');

    $csv = $response->streamedContent();

    expect($csv)->toContain('"Range Campaign",meta,"Meta Export Account",ACTIVE,Awareness,1.00,9.00,900,90,9,"2026-04-10 09:00:00"')
        ->and($csv)->toContain('"Other Campaign",meta,"Meta Export Account",PAUSED,Traffic,2.00,2.00,200,20,2,"2026-04-10 10:00:00"')
        ->and($csv)->not->toContain('1.00,10.00,1000,100,10');
});
