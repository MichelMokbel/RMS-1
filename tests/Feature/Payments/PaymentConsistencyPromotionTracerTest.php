<?php

use App\Jobs\RunPromotionPaymentConsistency;
use App\Models\AccountingAuditLog;
use App\Models\AccountingCompany;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\MealPlanRequest;
use App\Models\MembershipPlan;
use App\Models\MembershipPromotion;
use App\Models\MembershipPromotionRedemption;
use App\Models\PaymentConsistencyFinding;
use App\Models\PaymentConsistencyRun;
use App\Models\User;
use App\Services\Payments\PaymentConsistencyService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $this->branch = Branch::query()->findOrFail(1);
    $this->branch->update(['company_id' => $this->company->id, 'is_active' => true]);
    $this->actor = User::factory()->create(['status' => 'active']);
    $this->customer = Customer::factory()->create();
    $this->portalUser = User::factory()->create([
        'customer_id' => $this->customer->id,
        'status' => 'active',
    ]);
});

/** @return array{promotion:MembershipPromotion,request:MealPlanRequest,redemption:MembershipPromotionRedemption} */
function createConsistencyZeroPromotionUse($test): array
{
    $promotion = MembershipPromotion::query()->create([
        'company_id' => $test->company->id,
        'code' => 'CHKFREE23456',
        'discount_type' => 'percentage',
        'percentage_basis_points' => 10000,
        'purchase_eligibility' => 'both',
        'starts_at' => now('UTC')->subDay(),
        'ends_at' => now('UTC')->addDay(),
        'total_limit' => 10,
        'per_customer_limit' => 1,
        'status' => 'active',
        'revision' => 1,
        'first_activated_at' => now('UTC'),
        'created_by' => $test->actor->id,
        'updated_by' => $test->actor->id,
    ]);
    $promotion->plans()->attach(MembershipPlan::query()
        ->where('company_id', $test->company->id)
        ->where('code', '20')
        ->value('id'));
    $request = MealPlanRequest::query()->create([
        'customer_id' => $test->customer->id,
        'user_id' => $test->portalUser->id,
        'customer_name' => $test->customer->name,
        'customer_phone' => $test->customer->phone,
        'customer_email' => $test->customer->email,
        'plan_meals' => 20,
        'status' => 'new',
        'submission_kind' => 'promo_request',
        'client_uuid' => (string) Str::uuid(),
        'promotion_id' => $promotion->id,
        'proposed_selections_snapshot' => ['selections' => []],
        'promotion_terms_snapshot' => ['code' => $promotion->code, 'net_cents' => 0],
        'notification_snapshots' => ['customer_email' => $test->customer->email],
        'notification_dispatch' => ['customer_confirmation' => ['state' => 'pending']],
    ]);
    $redemption = MembershipPromotionRedemption::query()->create([
        'promotion_id' => $promotion->id,
        'company_id' => $test->company->id,
        'branch_id' => $test->branch->id,
        'original_customer_id' => $test->customer->id,
        'original_user_id' => $test->portalUser->id,
        'kind' => MembershipPromotionRedemption::KIND_ZERO_REQUEST,
        'meal_plan_request_id' => $request->id,
        'offer_snapshot' => ['code' => $promotion->code, 'revision' => 1],
        'eligibility_snapshot' => ['kind' => 'first', 'customer_ids' => [$test->customer->id]],
        'gross_cents' => 90000,
        'discount_cents' => 90000,
        'net_cents' => 0,
        'redeemed_at' => now('UTC'),
        'zero_subject_key' => hash('sha256', implode(':', [
            $test->company->id,
            $promotion->id,
            $test->customer->id,
        ])),
    ]);
    $request->update(['redemption_id' => $redemption->id]);

    return compact('promotion', 'request', 'redemption');
}

function consistencyFinancialSnapshot(): array
{
    return collect([
        'payments',
        'payment_allocations',
        'ar_invoices',
        'orders',
        'meal_subscriptions',
        'membership_purchase_blocks',
        'membership_promotion_reservations',
        'membership_promotion_redemptions',
    ])->mapWithKeys(fn (string $table): array => [
        $table => DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(),
    ])->all();
}

it('creates the bounded payment consistency foundation', function (): void {
    expect(Schema::hasTable('payment_consistency_runs'))->toBeTrue()
        ->and(Schema::hasTable('payment_consistency_findings'))->toBeTrue()
        ->and(DB::table('permissions')
            ->where('name', 'payments.consistency.run')
            ->where('guard_name', 'web')
            ->exists())->toBeTrue()
        ->and(Schema::hasColumns('payment_consistency_runs', [
            'reference',
            'company_id',
            'kind',
            'state',
            'rule_versions',
            'registry_hash',
            'trigger_key',
            'target_type',
            'target_id',
            'heartbeat_at',
        ]))->toBeTrue();

    $runTable = DB::selectOne('SHOW CREATE TABLE payment_consistency_runs');
    $findingTable = DB::selectOne('SHOW CREATE TABLE payment_consistency_findings');
    $runSql = (string) array_values((array) $runTable)[1];
    $findingSql = (string) array_values((array) $findingTable)[1];

    expect($runSql)->toContain('payment_consistency_runs_trigger_unique')
        ->and($runSql)->toContain('payment_consistency_runs_kind_chk')
        ->and($findingSql)->toContain('payment_consistency_findings_subject_rule_unique')
        ->and($findingSql)->toContain('payment_consistency_findings_state_chk');
});

it('opens one promotion finding episode and resolves it without changing money', function (): void {
    ['promotion' => $promotion, 'request' => $request] = createConsistencyZeroPromotionUse($this);
    $service = app(PaymentConsistencyService::class);
    $financialBefore = consistencyFinancialSnapshot();

    $healthy = $service->checkPromotion($promotion, triggerKey: 'test:healthy');
    $healthyReplay = $service->checkPromotion($promotion, triggerKey: 'test:healthy');

    expect($healthy->state)->toBe(PaymentConsistencyRun::STATE_COMPLETED)
        ->and($healthy->checked_count)->toBe(1)
        ->and($healthy->open_count)->toBe(0)
        ->and($healthyReplay->id)->toBe($healthy->id)
        ->and(PaymentConsistencyRun::query()->count())->toBe(1)
        ->and(PaymentConsistencyFinding::query()->count())->toBe(0)
        ->and(consistencyFinancialSnapshot())->toBe($financialBefore);

    DB::table('meal_plan_requests')->where('id', $request->id)->update(['promotion_id' => null]);
    $brokenFinancialState = consistencyFinancialSnapshot();
    $openedRun = $service->checkPromotion($promotion, triggerKey: 'test:broken:1');
    $finding = PaymentConsistencyFinding::query()->firstOrFail();
    $episodeUuid = $finding->episode_uuid;

    expect($openedRun->state)->toBe(PaymentConsistencyRun::STATE_COMPLETED)
        ->and($openedRun->open_count)->toBe(1)
        ->and($finding->state)->toBe(PaymentConsistencyFinding::STATE_OPEN)
        ->and(collect($finding->observed['issues'])->pluck('code')->all())
        ->toContain('PROMOTION_ZERO_USE_REQUEST_MISMATCH')
        ->and(AccountingAuditLog::query()
            ->where('action', 'payment_consistency.finding_opened')
            ->count())->toBe(1)
        ->and(consistencyFinancialSnapshot())->toBe($brokenFinancialState);

    $repeatedRun = $service->checkPromotion($promotion, triggerKey: 'test:broken:2');
    $finding->refresh();

    expect($repeatedRun->open_count)->toBe(1)
        ->and(PaymentConsistencyFinding::query()->count())->toBe(1)
        ->and($finding->episode_uuid)->toBe($episodeUuid)
        ->and(AccountingAuditLog::query()
            ->where('action', 'payment_consistency.finding_opened')
            ->count())->toBe(1)
        ->and(consistencyFinancialSnapshot())->toBe($brokenFinancialState);

    DB::table('meal_plan_requests')->where('id', $request->id)->update(['promotion_id' => $promotion->id]);
    $correctedFinancialState = consistencyFinancialSnapshot();
    $resolvedRun = $service->checkPromotion($promotion, triggerKey: 'test:resolved');
    $finding->refresh();

    expect($resolvedRun->open_count)->toBe(0)
        ->and($resolvedRun->resolved_count)->toBe(1)
        ->and($finding->state)->toBe(PaymentConsistencyFinding::STATE_RESOLVED)
        ->and($finding->episode_uuid)->toBe($episodeUuid)
        ->and($finding->resolved_at)->not->toBeNull()
        ->and(AccountingAuditLog::query()
            ->where('action', 'payment_consistency.finding_resolved')
            ->count())->toBe(1)
        ->and(consistencyFinancialSnapshot())->toBe($correctedFinancialState);
});

it('keeps sweeps disabled until configured and queues bounded catchup and full work', function (): void {
    ['promotion' => $promotion, 'request' => $request] = createConsistencyZeroPromotionUse($this);
    Queue::fake([RunPromotionPaymentConsistency::class]);

    Config::set('payment_consistency.enabled', false);
    $this->artisan('payments:check-consistency', [
        '--mode' => 'catchup',
        '--company' => $this->company->id,
    ])->assertSuccessful();
    Queue::assertNothingPushed();

    Config::set('payment_consistency.enabled', true);
    $request->touch();
    $this->artisan('payments:check-consistency', [
        '--mode' => 'catchup',
        '--company' => $this->company->id,
    ])->assertSuccessful();
    Queue::assertPushed(RunPromotionPaymentConsistency::class, function ($job) use ($promotion): bool {
        return $job->promotionId === (int) $promotion->id
            && $job->kind === PaymentConsistencyRun::KIND_CATCHUP
            && str_starts_with($job->triggerKey, 'catchup:');
    });

    $this->artisan('payments:check-consistency', [
        '--mode' => 'full',
        '--company' => $this->company->id,
    ])->assertSuccessful();
    Queue::assertPushed(RunPromotionPaymentConsistency::class, function ($job) use ($promotion): bool {
        return $job->promotionId === (int) $promotion->id
            && $job->kind === PaymentConsistencyRun::KIND_FULL
            && str_starts_with($job->triggerKey, 'full:');
    });
});

it('registers Qatar full scans and fifteen minute catchup scans', function (): void {
    $this->artisan('schedule:list')->assertSuccessful();
    $events = collect(app(Schedule::class)->events());
    $catchup = $events->first(fn ($event) => str_contains(
        $event->command ?? '',
        'payments:check-consistency --mode=catchup',
    ));
    $full = $events->first(fn ($event) => str_contains(
        $event->command ?? '',
        'payments:check-consistency --mode=full',
    ));

    expect($catchup)->not->toBeNull()
        ->and($catchup->expression)->toBe('*/15 * * * *')
        ->and($catchup->withoutOverlapping)->toBeTrue()
        ->and($full)->not->toBeNull()
        ->and($full->expression)->toBe('0 2 * * *')
        ->and($full->timezone)->toBe('Asia/Qatar')
        ->and($full->withoutOverlapping)->toBeTrue();
});
