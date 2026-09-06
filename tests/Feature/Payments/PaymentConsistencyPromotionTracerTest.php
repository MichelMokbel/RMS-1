<?php

use App\Jobs\ContinuePaymentConsistencySweep;
use App\Jobs\RunPaymentConsistency;
use App\Jobs\RunPromotionPaymentConsistency;
use App\Jobs\SendPaymentConsistencyAlert;
use App\Models\AccountingAuditLog;
use App\Models\AccountingCompany;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\EmailLog;
use App\Models\MealPlanRequest;
use App\Models\MembershipPlan;
use App\Models\MembershipPromotion;
use App\Models\MembershipPromotionRedemption;
use App\Models\PaymentConsistencyFinding;
use App\Models\PaymentConsistencyRun;
use App\Models\User;
use App\Services\Payments\PaymentConsistencyAlertService;
use App\Services\Payments\PaymentConsistencyQueryService;
use App\Services\Payments\PaymentConsistencyRuleRegistry;
use App\Services\Payments\PaymentConsistencyService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

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

it('registers every required versioned rule and its explicit evidence readers', function (): void {
    $registry = app(PaymentConsistencyRuleRegistry::class);
    $expected = [
        'provider_checkout_v1',
        'ordinary_accounting_v1',
        'membership_purchase_v1',
        'membership_balance_v1',
        'membership_sequence_v1',
        'booking_correction_v1',
        'booking_policy_v1',
        'customer_ownership_v1',
        'promotion_usage_v1',
        'saved_credit_v1',
        'settlement_v1',
        'notification_operations_v1',
    ];

    expect($registry->codes())->toBe($expected)
        ->and($registry->versions())->toHaveCount(12)
        ->and($registry->hash())->toHaveLength(64);
    foreach ($expected as $ruleCode) {
        $definition = $registry->definition($ruleCode);
        expect($definition['version'])->toBe(1)
            ->and($definition['subject_types'])->not->toBeEmpty()
            ->and($definition['fingerprint_readers'])->not->toBeEmpty();
    }

    $definitions = config('payment_consistency.rules');
    $brokenDefinitions = $definitions;
    array_pop($brokenDefinitions['saved_credit_v1']['fingerprint_readers']);
    Config::set('payment_consistency.rules', $brokenDefinitions);
    expect(fn () => app(PaymentConsistencyRuleRegistry::class))->toThrow(
        LogicException::class,
        'Payment consistency rule readers are incomplete: saved_credit_v1',
    );
    Config::set('payment_consistency.rules', $definitions);
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

it('reclaims a stale running consistency job on queue redelivery', function (): void {
    ['promotion' => $promotion] = createConsistencyZeroPromotionUse($this);
    Config::set('payment_consistency.run_stale_seconds', 180);
    $service = app(PaymentConsistencyService::class);
    $run = $service->reserve(
        'promotion_usage_v1',
        'membership_promotion',
        $promotion->id,
        PaymentConsistencyRun::KIND_CATCHUP,
        'test:stale-worker-redelivery',
    );
    $run->update([
        'state' => PaymentConsistencyRun::STATE_RUNNING,
        'started_at' => now('UTC')->subMinutes(4),
        'heartbeat_at' => now('UTC')->subMinutes(4),
    ]);

    $recovered = $service->checkPromotion(
        $promotion,
        PaymentConsistencyRun::KIND_CATCHUP,
        triggerKey: 'test:stale-worker-redelivery',
    );

    expect($recovered->id)->toBe($run->id)
        ->and($recovered->state)->toBe(PaymentConsistencyRun::STATE_COMPLETED)
        ->and($recovered->checked_count)->toBe(1)
        ->and($recovered->open_count)->toBe(0);
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
            && str_starts_with($job->triggerKey, 'sweep:');
    });

    $this->artisan('payments:check-consistency', [
        '--mode' => 'full',
        '--company' => $this->company->id,
    ])->assertSuccessful();
    Queue::assertPushed(RunPromotionPaymentConsistency::class, function ($job) use ($promotion): bool {
        return $job->promotionId === (int) $promotion->id
            && $job->kind === PaymentConsistencyRun::KIND_FULL
            && str_starts_with($job->triggerKey, 'sweep:')
            && $job->parentRunId !== null;
    });

    $parents = PaymentConsistencyRun::query()
        ->where('rule_code', 'registry_sweep_v1')
        ->where('target_type', 'accounting_company')
        ->orderBy('id')
        ->get();
    $this->actor->assignRole('admin');
    expect($parents)->toHaveCount(2)
        ->and($parents->every(fn (PaymentConsistencyRun $run): bool => $run->state === PaymentConsistencyRun::STATE_RUNNING))->toBeTrue()
        ->and(app(PaymentConsistencyQueryService::class)->health($this->actor)['status'])->toBe('running');
});

it('continues full scans through persisted keyset batches of at most one hundred subjects', function (): void {
    Customer::factory()->count(205)->create();
    Config::set('payment_consistency.enabled', true);
    Config::set('payment_consistency.batch_size', 100);
    Queue::fake([RunPaymentConsistency::class, ContinuePaymentConsistencySweep::class]);

    $sweeps = app(\App\Services\Payments\PaymentConsistencySweepService::class);
    $sweeps->dispatch(PaymentConsistencyRun::KIND_FULL, $this->company->id);
    $parent = PaymentConsistencyRun::query()
        ->where('rule_code', 'registry_sweep_v1')
        ->where('kind', PaymentConsistencyRun::KIND_FULL)
        ->firstOrFail();
    $batchSizes = [];

    for ($iteration = 0; $iteration < 10 && $parent->fresh()->state !== PaymentConsistencyRun::STATE_COMPLETED; $iteration++) {
        $parent->refresh();
        $manifest = collect((array) data_get($parent->cursors, 'manifest', []));
        if ($manifest->isEmpty()) {
            $sweeps->continue((int) $parent->id);
            $parent->refresh();
            $manifest = collect((array) data_get($parent->cursors, 'manifest', []));
        }
        $batchSizes[] = $manifest->count();
        expect(PaymentConsistencyRun::query()
            ->where('parent_run_id', $parent->id)
            ->whereIn('trigger_key', $manifest->pluck('trigger_key'))
            ->count())->toBe($manifest->count());
        foreach ($manifest as $subject) {
            $run = PaymentConsistencyRun::query()
                ->where('parent_run_id', $parent->id)
                ->where('rule_code', $subject['rule_code'])
                ->where('target_type', $subject['subject_type'])
                ->where('target_id', $subject['subject_id'])
                ->orderByDesc('id')
                ->firstOrFail();
            app(PaymentConsistencyService::class)->check(
                $subject['rule_code'],
                $subject['subject_type'],
                (int) $subject['subject_id'],
                PaymentConsistencyRun::KIND_FULL,
                triggerKey: (string) $run->trigger_key,
                parentRunId: (int) $parent->id,
            );
        }
    }

    $parent->refresh();
    expect($parent->state)->toBe(PaymentConsistencyRun::STATE_COMPLETED)
        ->and($parent->checked_count)->toBeGreaterThanOrEqual(206)
        ->and(max($batchSizes))->toBeLessThanOrEqual(100)
        ->and(count($batchSizes))->toBeGreaterThanOrEqual(3)
        ->and(data_get($parent->cursors, 'scan_complete'))->toBeTrue();
});

it('shows scoped findings and queues an audited administrator recheck', function (): void {
    ['promotion' => $promotion, 'request' => $request] = createConsistencyZeroPromotionUse($this);
    Config::set('payment_consistency.enabled', true);
    Queue::fake([SendPaymentConsistencyAlert::class, RunPaymentConsistency::class]);
    DB::table('meal_plan_requests')->where('id', $request->id)->update(['promotion_id' => null]);
    app(PaymentConsistencyService::class)->checkPromotion($promotion, triggerKey: 'test:ui:open');
    $finding = PaymentConsistencyFinding::query()->firstOrFail();

    Permission::findOrCreate('payments.consistency.run', 'web');
    Permission::findOrCreate('payments.support.view', 'web');
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('customer', 'web');
    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');
    $admin->givePermissionTo('payments.consistency.run');

    $this->actingAs($admin)
        ->get(route('receivables.payments.consistency.index'))
        ->assertOk()
        ->assertSee('Payment Consistency')
        ->assertSee('Promotion Usage V1');
    $this->actingAs($admin)
        ->get(route('receivables.payments.consistency.show', $finding))
        ->assertOk()
        ->assertSee('Detected differences');

    Volt::actingAs($admin)
        ->test('receivables.payments.consistency.show', ['finding' => $finding])
        ->call('recheck')
        ->assertHasNoErrors()
        ->assertSee('Consistency recheck queued.');
    Queue::assertPushed(RunPaymentConsistency::class, fn ($job): bool => $job->ruleCode === 'promotion_usage_v1'
        && $job->subjectId === (int) $promotion->id
        && $job->kind === PaymentConsistencyRun::KIND_MANUAL
        && $job->requestedBy === (int) $admin->id);
    expect(AccountingAuditLog::query()->where('action', 'payment_consistency.manual_recheck_queued')->count())->toBe(1);

    $staff = User::factory()->create(['status' => 'active']);
    $staff->givePermissionTo('payments.support.view');
    $staff->branches()->attach($this->branch->id);
    $this->actingAs($staff)
        ->get(route('receivables.payments.consistency.show', $finding))
        ->assertForbidden();

    $customer = User::factory()->create(['status' => 'active']);
    $customer->assignRole('customer');
    $this->actingAs($customer)
        ->get(route('receivables.payments.consistency.index'))
        ->assertForbidden();
});

it('sends one administrator alert per non checkout finding episode', function (): void {
    ['promotion' => $promotion, 'request' => $request] = createConsistencyZeroPromotionUse($this);
    Config::set('payment_consistency.enabled', true);
    Config::set('mail.default', 'array');
    Config::set('mail.daily_dish_admin_emails', ['consistency@example.test']);
    DB::table('meal_plan_requests')->where('id', $request->id)->update(['promotion_id' => null]);
    $service = app(PaymentConsistencyService::class);

    $service->checkPromotion($promotion, triggerKey: 'test:alert:first');
    $service->checkPromotion($promotion, triggerKey: 'test:alert:repeat');

    $finding = PaymentConsistencyFinding::query()->firstOrFail();
    expect($finding->alert_dispatch['state'])->toBe('sent')
        ->and(EmailLog::query()->where('category', 'payment_consistency_alert')->count())->toBe(1)
        ->and(AccountingAuditLog::query()->where('action', 'payment_consistency.alert_sent')->count())->toBe(1);

    $finding->update(['alert_dispatch' => [
        'state' => 'sending',
        'attempts' => 1,
        'claim_uuid' => (string) Str::uuid(),
        'claimed_at' => now('UTC')->subMinutes(11)->toIso8601String(),
    ]]);
    app(PaymentConsistencyAlertService::class)->recoverDue($this->company->id);
    expect($finding->fresh()->alert_dispatch['state'])->toBe('unknown')
        ->and(EmailLog::query()->where('category', 'payment_consistency_alert')->count())->toBe(1)
        ->and(AccountingAuditLog::query()->where('action', 'payment_consistency.alert_unknown')->count())->toBe(1);
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
