<?php

use App\Models\AccountingCompany;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\LedgerAccount;
use App\Models\PaymentCheckoutAttempt;
use App\Models\PaymentSource;
use App\Models\StorefrontEvent;
use App\Models\User;
use App\Services\Storefront\StorefrontFunnelReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('keeps storefront analytics catalog and Daily Dish traffic in separate rate limit buckets', function (): void {
    $middleware = fn (string $method, string $uri): array => Route::getRoutes()
        ->match(Request::create($uri, $method))
        ->gatherMiddleware();

    expect($middleware('POST', '/api/public/storefront/events'))
        ->toContain('throttle:public-storefront-events')
        ->not->toContain('throttle:public-storefront-read')
        ->and($middleware('GET', '/api/public/storefront/menu-items'))
        ->toContain('throttle:public-storefront-read')
        ->not->toContain('throttle:public-storefront-events')
        ->and($middleware('GET', '/api/public/daily-dish/menus'))
        ->toContain('throttle:public-daily-dish-read')
        ->not->toContain('throttle:public-storefront-read');
});

it('records only allowlisted anonymous storefront context and accepts an exact retry once', function (): void {
    $company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $eventUuid = (string) Str::uuid();
    $journeyUuid = (string) Str::uuid();
    $payload = [
        'event_uuid' => $eventUuid,
        'journey_uuid' => $journeyUuid,
        'event_name' => 'storefront_viewed',
        'path_code' => 'advance_menu',
    ];

    $this->postJson('/api/public/storefront/events', $payload)
        ->assertStatus(202)
        ->assertJsonPath('accepted', true)
        ->assertJsonPath('duplicate', false);
    $this->postJson('/api/public/storefront/events', $payload)
        ->assertStatus(202)
        ->assertJsonPath('duplicate', true);

    $event = StorefrontEvent::query()->sole();
    expect($event->company_id)->toBe($company->id)
        ->and($event->event_uuid)->toBe($eventUuid)
        ->and($event->journey_hash)->not->toBe($journeyUuid)
        ->and($event->journey_hash)->toHaveLength(64)
        ->and(StorefrontEvent::query()->count())->toBe(1);
});

it('rejects identity fields unsupported references and invalid event values', function (): void {
    $base = [
        'event_uuid' => (string) Str::uuid(),
        'journey_uuid' => (string) Str::uuid(),
        'event_name' => 'storefront_viewed',
        'path_code' => 'advance_menu',
    ];

    $this->postJson('/api/public/storefront/events', [...$base, 'customer_id' => 99])
        ->assertUnprocessable();
    $this->postJson('/api/public/storefront/events', [
        ...$base,
        'event_uuid' => (string) Str::uuid(),
        'path_code' => 'invented_path',
    ])->assertUnprocessable();
    $this->postJson('/api/public/storefront/events', [
        'event_uuid' => (string) Str::uuid(),
        'journey_uuid' => (string) Str::uuid(),
        'event_name' => 'category_viewed',
        'category_id' => 999999,
    ])->assertUnprocessable();

    expect(StorefrontEvent::query()->count())->toBe(0);
});

it('purges expired storefront events and retains the configured window', function (): void {
    CarbonImmutable::setTestNow('2026-09-09 12:00:00 UTC');

    try {
        $companyId = AccountingCompany::query()->where('is_default', true)->value('id');
        foreach ([181, 179] as $age) {
            DB::table('storefront_events')->insert([
                'company_id' => $companyId,
                'event_uuid' => (string) Str::uuid(),
                'journey_hash' => hash('sha256', (string) Str::uuid()),
                'event_name' => 'storefront_viewed',
                'source' => 'browser',
                'received_at' => now('UTC')->subDays($age),
                'path_code' => 'advance_menu',
                'created_at' => now('UTC')->subDays($age),
                'updated_at' => now('UTC')->subDays($age),
            ]);
        }

        $this->artisan('storefront:purge-events', ['--days' => 180])
            ->expectsOutput('Deleted 1 expired storefront events.')
            ->assertSuccessful();

        expect(StorefrontEvent::query()->count())->toBe(1)
            ->and(StorefrontEvent::query()->firstOrFail()->received_at->toDateString())
            ->toBe('2026-03-14');
    } finally {
        CarbonImmutable::setTestNow();
    }
});

it('reports distinct browser journeys and canonical checkout outcomes from one Qatar start cohort', function (): void {
    CarbonImmutable::setTestNow('2026-09-09 12:00:00 UTC');

    try {
        $company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
        $branch = Branch::query()->findOrFail(1);
        $branch->update(['company_id' => $company->id, 'is_active' => true]);
        $actor = User::factory()->create(['status' => 'active']);
        $customer = Customer::factory()->create();
        $portalUser = User::factory()->create(['customer_id' => $customer->id, 'status' => 'active']);
        $clearing = LedgerAccount::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $source = PaymentSource::query()->create([
            'company_id' => $company->id,
            'code' => 'skipcash',
            'name' => 'SkipCash',
            'method' => 'skipcash',
            'clearing_account_id' => $clearing->id,
            'is_active' => true,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        $journey = hash('sha256', 'one-journey');
        foreach (['2026-08-31 21:00:00', '2026-09-02 20:59:59'] as $receivedAt) {
            StorefrontEvent::query()->create([
                'company_id' => $company->id,
                'event_uuid' => (string) Str::uuid(),
                'journey_hash' => $journey,
                'event_name' => 'item_added',
                'source' => 'browser',
                'received_at' => $receivedAt,
                'source_section' => 'menu_list',
                'quantity_bucket' => 'one',
            ]);
        }
        StorefrontEvent::query()->create([
            'company_id' => $company->id,
            'event_uuid' => (string) Str::uuid(),
            'journey_hash' => hash('sha256', 'outside'),
            'event_name' => 'item_added',
            'source' => 'browser',
            'received_at' => '2026-09-02 21:00:00',
            'source_section' => 'menu_list',
            'quantity_bucket' => 'one',
        ]);

        $makeAttempt = function (string $state, string $startedAt, string $purpose = 'menu_order') use ($company, $branch, $customer, $portalUser, $source): void {
            $uuid = (string) Str::uuid();
            PaymentCheckoutAttempt::query()->create([
                'reference' => $uuid,
                'company_id' => $company->id,
                'branch_id' => $branch->id,
                'customer_id' => $customer->id,
                'portal_user_id' => $portalUser->id,
                'payment_source_id' => $source->id,
                'client_uuid' => (string) Str::uuid(),
                'purpose' => $purpose,
                'currency' => 'QAR',
                'gross_amount_cents' => 1000,
                'discount_amount_cents' => 0,
                'payable_amount_cents' => 1000,
                'cart_fingerprint' => hash('sha256', 'cart-'.$uuid),
                'quote_fingerprint' => hash('sha256', 'quote-'.$uuid),
                'request_fingerprint' => hash('sha256', 'request-'.$uuid),
                'recovery_fingerprint' => hash('sha256', 'recovery-'.$uuid),
                'state' => $state,
                'started_at' => $startedAt,
                'expires_at' => CarbonImmutable::parse($startedAt, 'UTC')->addMinutes(15),
                'completed_at' => $state === 'completed' ? CarbonImmutable::parse($startedAt, 'UTC')->addMinute() : null,
                'cart_snapshot' => [],
                'customer_snapshot' => [],
                'pricing_snapshot' => [],
                'terms_snapshot' => [],
                'request_snapshot' => [],
                'source_account_snapshot' => [],
                'provider_request_uuid' => (string) Str::uuid(),
                'provider_create_outcome' => 'created',
            ]);
        };
        $makeAttempt('declined', '2026-08-31 21:00:00');
        $makeAttempt('completed', '2026-09-02 20:59:59');
        $makeAttempt('completed', '2026-09-02 21:00:00');
        $makeAttempt('completed', '2026-09-01 10:00:00', 'ordinary_order');

        $report = app(StorefrontFunnelReportService::class)->report($company->id, '2026-09-01', '2026-09-02');
        expect($report['browser_directional']['item_adds'])->toBe(1)
            ->and($report['checkout_canonical']['checkout_starts'])->toBe(2)
            ->and($report['checkout_canonical']['declines'])->toBe(1)
            ->and($report['checkout_canonical']['paid_processing'])->toBe(0)
            ->and($report['checkout_canonical']['paid_completions'])->toBe(1);

        $default = app(StorefrontFunnelReportService::class)->report($company->id);
        expect($default['from'])->toBe('2026-08-10')
            ->and($default['to'])->toBe('2026-09-08');
    } finally {
        CarbonImmutable::setTestNow();
    }
});
