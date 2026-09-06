<?php

use App\Models\AccountingAuditLog;
use App\Models\AccountingCompany;
use App\Models\MembershipPlan;
use App\Models\MembershipPromotion;
use App\Models\User;
use App\Services\Promotions\MembershipPromotionService;
use App\Services\Promotions\PromotionConflictException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-06 12:00:00', 'UTC'));
    $permission = Permission::findOrCreate('promotions.manage', 'web');
    $adminRole = Role::findOrCreate('admin', 'web');
    $adminRole->givePermissionTo($permission);
    Role::findOrCreate('staff', 'web');
    Role::findOrCreate('customer', 'web');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->admin = User::factory()->create(['status' => 'active']);
    $this->admin->assignRole('admin');
    $this->company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $this->plans = MembershipPlan::query()
        ->where('company_id', $this->company->id)
        ->whereIn('code', ['20', '26'])
        ->orderBy('meal_count')
        ->get();
    expect($this->plans)->toHaveCount(2);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** @return array<string, mixed> */
function validMembershipPromotionInput(array $overrides = []): array
{
    $test = test();

    return array_merge([
        'discount_type' => 'fixed',
        'fixed_amount' => '100.50',
        'percentage' => '',
        'purchase_eligibility' => 'both',
        'starts_on' => '2026-09-07',
        'ends_on' => '2026-09-30',
        'total_limit' => 10,
        'per_customer_limit' => 2,
        'eligible_plan_ids' => $test->plans->pluck('id')->all(),
    ], $overrides);
}

it('requires an active administrator with the dedicated permission', function (): void {
    $staff = User::factory()->create(['status' => 'active']);
    $staff->assignRole('staff');
    $staff->givePermissionTo('promotions.manage');

    $this->actingAs($staff)->get(route('membership-promotions.index'))->assertForbidden();
    expect(fn () => app(MembershipPromotionService::class)->create(
        $staff,
        validMembershipPromotionInput(),
        (string) Str::uuid(),
    ))->toThrow(AuthorizationException::class);

    $this->actingAs($this->admin)
        ->get(route('membership-promotions.index'))
        ->assertOk()
        ->assertSee('Membership Promotions')
        ->assertSee('Generate draft code');

    Role::findByName('admin', 'web')->revokePermissionTo('promotions.manage');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $adminWithoutPermission = User::factory()->create(['status' => 'active']);
    $adminWithoutPermission->assignRole('admin');

    $this->actingAs($adminWithoutPermission)->get(route('membership-promotions.index'))->assertForbidden();
    expect(fn () => app(MembershipPromotionService::class)->create(
        $adminWithoutPermission,
        validMembershipPromotionInput(),
        (string) Str::uuid(),
    ))->toThrow(AuthorizationException::class);
});

it('edits only a draft with revision and operation replay protection', function (): void {
    $service = app(MembershipPromotionService::class);
    $promotion = $service->create($this->admin, validMembershipPromotionInput(), (string) Str::uuid());
    $originalCode = $promotion->code;
    $operationUuid = (string) Str::uuid();
    $changedInput = validMembershipPromotionInput([
        'fixed_amount' => '50.25',
        'purchase_eligibility' => 'first',
        'total_limit' => 20,
        'per_customer_limit' => 1,
        'eligible_plan_ids' => [$this->plans->first()->id],
    ]);

    $updated = $service->updateDraft($this->admin, $promotion, 1, $changedInput, $operationUuid);
    $replay = $service->updateDraft($this->admin, $updated, 1, $changedInput, $operationUuid);

    expect($updated->code)->toBe($originalCode)
        ->and($updated->revision)->toBe(2)
        ->and($updated->fixed_amount_cents)->toBe(5025)
        ->and($updated->purchase_eligibility)->toBe('first')
        ->and($updated->total_limit)->toBe(20)
        ->and($updated->plans->pluck('code')->all())->toBe(['20'])
        ->and($replay->id)->toBe($updated->id)
        ->and($replay->revision)->toBe(2)
        ->and(AccountingAuditLog::query()->where('action', 'membership_promotion.updated')->count())->toBe(1);

    expect(fn () => $service->updateDraft($this->admin, $updated, 2, validMembershipPromotionInput([
        'fixed_amount' => '51.00',
    ]), $operationUuid))->toThrow(PromotionConflictException::class);
});

it('creates a company scoped generated draft with exact values and replay safe audit', function (): void {
    $service = app(MembershipPromotionService::class);
    $operationUuid = (string) Str::uuid();
    $input = validMembershipPromotionInput();

    $promotion = $service->create($this->admin, $input, $operationUuid);
    $replay = $service->create($this->admin, $input, $operationUuid);

    expect($promotion->id)->toBe($replay->id)
        ->and($promotion->code)->toMatch('/^[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]{12}$/')
        ->and($promotion->company_id)->toBe($this->company->id)
        ->and($promotion->fixed_amount_cents)->toBe(10050)
        ->and($promotion->percentage_basis_points)->toBeNull()
        ->and($promotion->status)->toBe('draft')
        ->and($promotion->revision)->toBe(1)
        ->and($promotion->starts_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-09-06 21:00:00')
        ->and($promotion->ends_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-09-30 21:00:00')
        ->and($promotion->plans)->toHaveCount(2)
        ->and(MembershipPromotion::query()->count())->toBe(1)
        ->and(AccountingAuditLog::query()->where('action', 'membership_promotion.created')->count())->toBe(1);

    $audit = AccountingAuditLog::query()->where('action', 'membership_promotion.created')->firstOrFail();
    expect($audit->payload['operation_uuid'])->toBe(strtolower($operationUuid))
        ->and($audit->payload['after']['code'])->toBe($promotion->code)
        ->and($audit->payload['after']['fixed_amount_cents'])->toBe(10050)
        ->and($audit->payload['after']['plan_codes'])->toBe(['20', '26']);

    expect(fn () => $service->create($this->admin, validMembershipPromotionInput([
        'fixed_amount' => '101.00',
    ]), $operationUuid))->toThrow(PromotionConflictException::class);
});

it('validates decimal money, percentages, dates, limits and same company plans', function (array $overrides, string $field): void {
    try {
        app(MembershipPromotionService::class)->create(
            $this->admin,
            validMembershipPromotionInput($overrides),
            (string) Str::uuid(),
        );
        $this->fail('Expected validation failure.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
})->with([
    'three fixed decimals' => [['fixed_amount' => '5.123'], 'fixed_amount'],
    'percentage above one hundred' => [[
        'discount_type' => 'percentage',
        'fixed_amount' => '',
        'percentage' => '100.01',
    ], 'percentage'],
    'customer limit above total' => [['total_limit' => 2, 'per_customer_limit' => 3], 'per_customer_limit'],
    'invalid Qatar date' => [['starts_on' => '2026-02-30'], 'starts_on'],
    'no eligible plans' => [['eligible_plan_ids' => []], 'eligible_plan_ids'],
]);

it('rejects a membership plan belonging to another company', function (): void {
    $otherCompany = AccountingCompany::query()->create([
        'name' => 'Other Company',
        'code' => 'OTHER-PROMO',
        'base_currency' => 'QAR',
        'is_active' => true,
        'is_default' => false,
    ]);
    $otherPlan = MembershipPlan::query()->create([
        'company_id' => $otherCompany->id,
        'code' => '20',
        'meal_count' => 20,
        'package_price_cents' => 90000,
        'currency' => 'QAR',
        'delivery_included' => true,
        'is_active' => true,
        'effective_from' => now('UTC'),
    ]);

    expect(fn () => app(MembershipPromotionService::class)->create(
        $this->admin,
        validMembershipPromotionInput(['eligible_plan_ids' => [$otherPlan->id]]),
        (string) Str::uuid(),
    ))->toThrow(ValidationException::class);
});

it('freezes activated terms and supports pause resume limit increase and terminal expiry', function (): void {
    $service = app(MembershipPromotionService::class);
    $promotion = $service->create($this->admin, validMembershipPromotionInput([
        'discount_type' => 'percentage',
        'fixed_amount' => '',
        'percentage' => '100.00',
        'per_customer_limit' => 4,
    ]), (string) Str::uuid());

    $promotion = $service->activate($this->admin, $promotion, 1, (string) Str::uuid());
    expect($promotion->status)->toBe('active')
        ->and($promotion->revision)->toBe(2)
        ->and($promotion->per_customer_limit)->toBe(1)
        ->and($promotion->first_activated_at)->not->toBeNull();

    expect(fn () => $service->updateDraft(
        $this->admin,
        $promotion,
        2,
        validMembershipPromotionInput(),
        (string) Str::uuid(),
    ))->toThrow(PromotionConflictException::class);
    expect(fn () => $service->pause($this->admin, $promotion, 1, (string) Str::uuid()))
        ->toThrow(PromotionConflictException::class);

    $promotion = $service->pause($this->admin, $promotion, 2, (string) Str::uuid());
    $promotion = $service->resume($this->admin, $promotion, 3, (string) Str::uuid());
    $promotion = $service->increaseLimit($this->admin, $promotion, 4, 15, (string) Str::uuid());
    $promotion = $service->expire($this->admin, $promotion, 5, (string) Str::uuid());

    expect($promotion->status)->toBe('expired')
        ->and($promotion->revision)->toBe(6)
        ->and($promotion->total_limit)->toBe(15)
        ->and($promotion->expired_at)->not->toBeNull()
        ->and(AccountingAuditLog::query()->where('subject_id', $promotion->id)->count())->toBe(6);
    expect(fn () => $service->resume($this->admin, $promotion, 6, (string) Str::uuid()))
        ->toThrow(PromotionConflictException::class);
});

it('forces a fixed offer that makes an eligible plan free to one use per customer', function (): void {
    $service = app(MembershipPromotionService::class);
    $promotion = $service->create($this->admin, validMembershipPromotionInput([
        'fixed_amount' => '900.00',
        'per_customer_limit' => 3,
        'eligible_plan_ids' => [$this->plans->first()->id],
    ]), (string) Str::uuid());

    $promotion = $service->activate($this->admin, $promotion, 1, (string) Str::uuid());

    expect($promotion->per_customer_limit)->toBe(1)
        ->and($promotion->fixed_amount_cents)->toBe(90000)
        ->and($promotion->plans->pluck('code')->all())->toBe(['20']);
});

it('copies an offer into a new editable code without copying lifecycle or usage', function (): void {
    $service = app(MembershipPromotionService::class);
    $source = $service->create($this->admin, validMembershipPromotionInput(), (string) Str::uuid());
    $source = $service->activate($this->admin, $source, 1, (string) Str::uuid());
    $copy = $service->copyAsDraft($this->admin, $source, (string) Str::uuid());

    expect($copy->id)->not->toBe($source->id)
        ->and($copy->code)->not->toBe($source->code)
        ->and($copy->status)->toBe('draft')
        ->and($copy->revision)->toBe(1)
        ->and($copy->first_activated_at)->toBeNull()
        ->and($copy->fixed_amount_cents)->toBe($source->fixed_amount_cents)
        ->and($copy->plans->pluck('code')->sort()->values()->all())->toBe(['20', '26'])
        ->and(AccountingAuditLog::query()->where('action', 'membership_promotion.copied')->count())->toBe(1);
});

it('uses the exclusive UTC boundary for natural expiry and refuses a late resume', function (): void {
    $service = app(MembershipPromotionService::class);
    $promotion = $service->create($this->admin, validMembershipPromotionInput([
        'starts_on' => '2026-09-06',
        'ends_on' => '2026-09-06',
    ]), (string) Str::uuid());
    $promotion = $service->activate($this->admin, $promotion, 1, (string) Str::uuid());

    Carbon::setTestNow(Carbon::parse('2026-09-06 20:59:59', 'UTC'));
    expect($promotion->displayStatus())->toBe('Live');
    $promotion = $service->pause($this->admin, $promotion, 2, (string) Str::uuid());

    Carbon::setTestNow(Carbon::parse('2026-09-06 21:00:00', 'UTC'));
    expect($promotion->displayStatus())->toBe('Expired');
    expect(fn () => $service->resume($this->admin, $promotion, 3, (string) Str::uuid()))
        ->toThrow(PromotionConflictException::class);
});

it('runs the promotion lifecycle from the administrator detail page', function (): void {
    $service = app(MembershipPromotionService::class);
    $promotion = $service->create($this->admin, validMembershipPromotionInput(), (string) Str::uuid());
    $this->actingAs($this->admin);

    Volt::test('membership-promotions.show', ['promotion' => $promotion])
        ->call('activate')
        ->assertHasNoErrors()
        ->assertSet('expected_revision', 2)
        ->assertSee('Fixed offer terms')
        ->assertSee('Scheduled')
        ->call('pause')
        ->assertHasNoErrors()
        ->assertSet('expected_revision', 3)
        ->assertSee('Paused')
        ->call('resume')
        ->assertHasNoErrors()
        ->assertSet('expected_revision', 4)
        ->set('increase_total_limit', 12)
        ->call('increaseLimit')
        ->assertHasNoErrors()
        ->assertSet('expected_revision', 5)
        ->call('expire')
        ->assertHasNoErrors()
        ->assertSet('expected_revision', 6)
        ->assertSee('Expired');
});

it('creates a draft through the responsive administrator page', function (): void {
    $this->actingAs($this->admin);
    $component = Volt::test('membership-promotions.index')
        ->set('discount_type', 'fixed')
        ->set('fixed_amount', '75.00')
        ->set('purchase_eligibility', 'first')
        ->set('starts_on', '2026-09-07')
        ->set('ends_on', '2026-09-30')
        ->set('total_limit', 25)
        ->set('per_customer_limit', 1)
        ->set('eligible_plan_ids', [$this->plans->first()->id])
        ->call('createPromotion')
        ->assertHasNoErrors();

    $promotion = MembershipPromotion::query()->firstOrFail();
    $component->assertRedirect(route('membership-promotions.show', $promotion));
    $this->get(route('membership-promotions.show', $promotion))
        ->assertOk()
        ->assertSee($promotion->code)
        ->assertSee('Copy code')
        ->assertSee('Activate promotion');
});
