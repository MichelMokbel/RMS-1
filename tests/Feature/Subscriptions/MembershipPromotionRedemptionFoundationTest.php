<?php

use App\Models\AccountingCompany;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\MealPlanRequest;
use App\Models\MealSubscription;
use App\Models\MembershipPlan;
use App\Models\MembershipPromotion;
use App\Models\MembershipPromotionRedemption;
use App\Models\MembershipPurchaseBlock;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Customers\CustomerMergeService;
use App\Services\Subscriptions\MembershipPurchaseHistoryService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $this->branch = Branch::query()->findOrFail(1);
    $this->branch->update(['company_id' => $this->company->id, 'is_active' => true]);
    $this->plan = MembershipPlan::query()
        ->where('company_id', $this->company->id)
        ->where('code', '20')
        ->firstOrFail();
});

/** @return array{request:MealPlanRequest,subscription:MealSubscription} */
function createCompletedLegacyMembership(Customer $customer, Branch $branch, ?AccountingCompany $company = null): array
{
    $request = MealPlanRequest::query()->create([
        'customer_id' => $customer->id,
        'customer_name' => $customer->name,
        'customer_phone' => $customer->phone,
        'customer_email' => $customer->email,
        'plan_meals' => 20,
        'status' => 'converted',
        'submission_kind' => 'legacy',
    ]);
    $subscription = MealSubscription::factory()->create([
        'customer_id' => $customer->id,
        'branch_id' => $branch->id,
        'status' => 'active',
        'plan_meals_total' => 20,
        'meals_used' => 0,
        'meal_plan_request_id' => $request->id,
        'fulfillment_mode' => $company ? 'customer_selection' : 'standing',
        'queue_company_id' => $company?->id,
        'queue_currency' => $company ? 'QAR' : null,
    ]);
    $request->update([
        'converted_subscription_id' => $subscription->id,
        'converted_at' => now('UTC'),
    ]);

    return ['request' => $request->fresh(), 'subscription' => $subscription];
}

function createRedemptionPromotion(AccountingCompany $company, MembershipPlan $plan, User $actor): MembershipPromotion
{
    $promotion = MembershipPromotion::query()->create([
        'company_id' => $company->id,
        'code' => 'ABCDEFGH2345',
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
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
    ]);
    $promotion->plans()->attach($plan->id);

    return $promotion;
}

it('creates the constrained reservation redemption and request foundation', function (): void {
    expect(Schema::hasTable('membership_promotion_reservations'))->toBeTrue()
        ->and(Schema::hasTable('membership_promotion_redemptions'))->toBeTrue()
        ->and(Schema::hasColumns('meal_plan_requests', [
            'client_uuid',
            'promotion_id',
            'redemption_id',
            'proposed_selections_snapshot',
            'promotion_terms_snapshot',
            'notification_snapshots',
            'notification_dispatch',
        ]))->toBeTrue();

    $reservationTable = DB::selectOne('SHOW CREATE TABLE membership_promotion_reservations');
    $redemptionTable = DB::selectOne('SHOW CREATE TABLE membership_promotion_redemptions');
    $reservationSql = (string) array_values((array) $reservationTable)[1];
    $redemptionSql = (string) array_values((array) $redemptionTable)[1];

    expect($reservationSql)->toContain('membership_promo_reservations_money_chk')
        ->and($reservationSql)->toContain('membership_promo_reservations_state_chk')
        ->and($redemptionSql)->toContain('membership_promo_redemptions_kind_chk')
        ->and($redemptionSql)->toContain('membership_promo_redemptions_zero_subject_unique');
});

it('stores one immutable zero request redemption and enforces its kind shape', function (): void {
    $customer = Customer::factory()->create();
    $user = User::factory()->create(['customer_id' => $customer->id, 'status' => 'active']);
    $promotion = createRedemptionPromotion($this->company, $this->plan, $user);
    $request = MealPlanRequest::query()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'customer_name' => $customer->name,
        'customer_phone' => $customer->phone,
        'customer_email' => $customer->email,
        'plan_meals' => 20,
        'status' => 'new',
        'submission_kind' => 'promo_request',
        'client_uuid' => (string) Str::uuid(),
        'promotion_id' => $promotion->id,
        'proposed_selections_snapshot' => ['selections' => []],
        'promotion_terms_snapshot' => ['code' => $promotion->code, 'net_cents' => 0],
        'notification_snapshots' => ['customer_email' => $customer->email],
        'notification_dispatch' => ['customer_confirmation' => ['state' => 'pending']],
    ]);
    $zeroSubjectKey = hash('sha256', implode(':', [$this->company->id, $promotion->id, $customer->id]));
    $redemption = MembershipPromotionRedemption::query()->create([
        'promotion_id' => $promotion->id,
        'company_id' => $this->company->id,
        'branch_id' => $this->branch->id,
        'original_customer_id' => $customer->id,
        'original_user_id' => $user->id,
        'kind' => 'zero_request',
        'meal_plan_request_id' => $request->id,
        'offer_snapshot' => ['code' => $promotion->code, 'revision' => 1],
        'eligibility_snapshot' => ['kind' => 'first', 'customer_ids' => [$customer->id]],
        'gross_cents' => 90000,
        'discount_cents' => 90000,
        'net_cents' => 0,
        'redeemed_at' => now('UTC'),
        'zero_subject_key' => $zeroSubjectKey,
    ]);
    $request->update(['redemption_id' => $redemption->id]);

    expect($redemption->fresh()->offer_snapshot['code'])->toBe($promotion->code)
        ->and($request->fresh()->promotionRedemption->is($redemption))->toBeTrue()
        ->and($promotion->fresh()->redemptions()->count())->toBe(1);

    $invalidRequest = MealPlanRequest::query()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'customer_name' => $customer->name,
        'customer_phone' => $customer->phone,
        'plan_meals' => 20,
        'status' => 'new',
        'submission_kind' => 'promo_request',
        'client_uuid' => (string) Str::uuid(),
        'promotion_id' => $promotion->id,
    ]);

    expect(fn () => MembershipPromotionRedemption::query()->create([
        'promotion_id' => $promotion->id,
        'company_id' => $this->company->id,
        'branch_id' => $this->branch->id,
        'original_customer_id' => $customer->id,
        'original_user_id' => $user->id,
        'kind' => 'zero_request',
        'meal_plan_request_id' => $invalidRequest->id,
        'offer_snapshot' => ['code' => $promotion->code],
        'eligibility_snapshot' => ['kind' => 'first'],
        'gross_cents' => 90000,
        'discount_cents' => 89999,
        'net_cents' => 1,
        'redeemed_at' => now('UTC'),
        'zero_subject_key' => hash('sha256', $zeroSubjectKey.':invalid'),
    ]))->toThrow(QueryException::class);

    $duplicateRequest = MealPlanRequest::query()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'customer_name' => $customer->name,
        'customer_phone' => $customer->phone,
        'plan_meals' => 20,
        'status' => 'new',
        'submission_kind' => 'promo_request',
        'client_uuid' => (string) Str::uuid(),
        'promotion_id' => $promotion->id,
    ]);
    expect(fn () => MembershipPromotionRedemption::query()->create([
        'promotion_id' => $promotion->id,
        'company_id' => $this->company->id,
        'branch_id' => $this->branch->id,
        'original_customer_id' => $customer->id,
        'original_user_id' => $user->id,
        'kind' => 'zero_request',
        'meal_plan_request_id' => $duplicateRequest->id,
        'offer_snapshot' => ['code' => $promotion->code],
        'eligibility_snapshot' => ['kind' => 'first'],
        'gross_cents' => 90000,
        'discount_cents' => 90000,
        'net_cents' => 0,
        'redeemed_at' => now('UTC'),
        'zero_subject_key' => $zeroSubjectKey,
    ]))->toThrow(QueryException::class);
});

it('resolves paid blocks and legacy conversions once across a customer merge', function (): void {
    $source = Customer::factory()->create(['name' => 'Original Member']);
    $target = Customer::factory()->create(['name' => 'Surviving Member']);
    $actor = User::factory()->create(['status' => 'active']);
    $actor->assignRole(Role::findOrCreate('admin', 'web'));
    $paid = createCompletedLegacyMembership($source, $this->branch, $this->company);
    createCompletedLegacyMembership($source, $this->branch);
    $payment = Payment::factory()->create([
        'company_id' => $this->company->id,
        'branch_id' => $this->branch->id,
        'customer_id' => $source->id,
        'method' => 'skipcash',
        'amount_cents' => 90000,
        'currency' => 'QAR',
    ]);
    MembershipPurchaseBlock::query()->create([
        'subscription_id' => $paid['subscription']->id,
        'plan_id' => $this->plan->id,
        'payment_id' => $payment->id,
        'meal_plan_request_id' => $paid['request']->id,
        'company_id' => $this->company->id,
        'branch_id' => $this->branch->id,
        'original_customer_id' => $source->id,
        'queue_position' => 1,
        'meal_count' => 20,
        'gross_price_cents' => 90000,
        'discount_cents' => 0,
        'final_price_cents' => 90000,
        'currency' => 'QAR',
        'origin' => 'checkout',
        'origin_key' => 'test:'.Str::uuid(),
        'quote_fingerprint' => hash('sha256', 'completed-membership'),
        'pricing_snapshot' => ['gross_amount_cents' => 90000],
        'terms_snapshot' => ['version' => 'v1'],
        'funded_at' => now('UTC')->subDay(),
        'cancelled_at' => now('UTC'),
        'cancelled_by' => $actor->id,
        'created_by' => $actor->id,
    ]);

    app(CustomerMergeService::class)->merge($source, $target, $actor->id);
    $history = app(MembershipPurchaseHistoryService::class)->resolve($target->id, $this->company->id);

    expect($history['canonical_customer_id'])->toBe($target->id)
        ->and($history['customer_ids'])->toBe([$source->id, $target->id])
        ->and($history['completed_purchase_count'])->toBe(2)
        ->and($history['has_completed_purchase'])->toBeTrue()
        ->and(collect($history['evidence'])->pluck('source')->all())
        ->toBe(['purchase_block', 'legacy_conversion'])
        ->and($history['evidence'][0]['cancelled'])->toBeTrue()
        ->and(app(MembershipPurchaseHistoryService::class)->hasCompletedPurchase($source->id, $this->company->id))
        ->toBeTrue();
});

it('does not treat an ordinary customer order as a completed membership purchase', function (): void {
    $customer = Customer::factory()->create();
    Order::factory()->create([
        'branch_id' => $this->branch->id,
        'customer_id' => $customer->id,
        'source' => 'Website',
        'status' => 'Confirmed',
    ]);

    $history = app(MembershipPurchaseHistoryService::class)->resolve($customer->id, $this->company->id);

    expect($history['has_completed_purchase'])->toBeFalse()
        ->and($history['completed_purchase_count'])->toBe(0)
        ->and($history['evidence'])->toBe([]);
});
