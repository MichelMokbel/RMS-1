<?php

use App\Models\AccountingCompany;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\LedgerAccount;
use App\Models\MealPlanRequest;
use App\Models\MealSubscription;
use App\Models\MembershipPlan;
use App\Models\MembershipPromotion;
use App\Models\MembershipPromotionRedemption;
use App\Models\MembershipPromotionReservation;
use App\Models\MembershipPurchaseBlock;
use App\Models\Payment;
use App\Models\PaymentCheckoutAttempt;
use App\Models\PaymentProviderTransaction;
use App\Models\PaymentSetting;
use App\Models\PaymentSource;
use App\Models\User;
use App\Services\Payments\FakeSkipCashProvider;
use App\Services\Payments\SkipCashProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('customer', 'web');
    $this->company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $this->branch = Branch::query()->findOrFail(1);
    $this->branch->update(['company_id' => $this->company->id, 'is_active' => true]);
    $this->systemActor = User::factory()->create(['status' => 'active']);
    $this->clearingAccount = LedgerAccount::factory()->create([
        'company_id' => $this->company->id,
        'is_active' => true,
    ]);
    $this->source = PaymentSource::query()->create([
        'company_id' => $this->company->id,
        'code' => 'skipcash',
        'name' => 'SkipCash',
        'method' => 'skipcash',
        'clearing_account_id' => $this->clearingAccount->id,
        'is_active' => true,
        'created_by' => $this->systemActor->id,
        'updated_by' => $this->systemActor->id,
    ]);
    PaymentSetting::query()->create([
        'company_id' => $this->company->id,
        'checkout_duration_minutes' => 15,
        'booking_cutoff_time' => '23:00:00',
        'timezone' => 'Asia/Qatar',
        'order_support_phone' => '+974 55683442',
        'created_by' => $this->systemActor->id,
        'updated_by' => $this->systemActor->id,
    ]);

    $termsPath = base_path('tests/Fixtures/payment-terms-v1.md');
    Config::set('customers.verification_bypass', true);
    Config::set('payments.system_user_id', $this->systemActor->id);
    Config::set('payments.customer_direct_order_enabled', false);
    Config::set('payments.membership.checkout_enabled', true);
    Config::set('payments.membership.queue_enabled', true);
    Config::set('payments.skipcash.enabled', true);
    Config::set('payments.skipcash.driver', 'fake');
    Config::set('payments.skipcash.environment', 'sandbox');
    Config::set('payments.skipcash.base_url', 'https://api.sandbox.skipcash.test');
    Config::set('payments.skipcash.client_id', 'client-id');
    Config::set('payments.skipcash.key_id', 'key-id');
    Config::set('payments.skipcash.secret_key', 'secret-key');
    Config::set('payments.skipcash.webhook_secret', 'webhook-secret');
    Config::set('payments.skipcash.return_url', 'https://orders.example.test/orders/payment');
    Config::set('payments.skipcash.webhook_url', 'https://rms.example.test/api/integrations/skipcash/webhook');
    Config::set('payments.skipcash.pay_url_hosts', ['pay.skipcash.test']);
    Config::set('mail.daily_dish_admin_emails', ['ops@example.test']);
    Config::set('payment_terms.published', [[
        'version' => 'v1',
        'effective_at' => '2026-01-01T00:00:00+00:00',
        'url' => 'https://orders.example.test/terms/v1',
        'content_path' => $termsPath,
        'content_hash' => hash_file('sha256', $termsPath),
    ]]);
    app()->forgetInstance(SkipCashProvider::class);

    $this->customer = Customer::factory()->create(['email' => 'member@example.test']);
    $this->portalUser = User::factory()->create([
        'customer_id' => $this->customer->id,
        'name' => 'Membership Customer',
        'portal_name' => 'Membership Customer',
        'portal_phone' => '+97455683442',
        'portal_phone_e164' => '+97455683442',
        'portal_delivery_address' => 'Doha',
        'email' => 'member@example.test',
        'status' => 'active',
    ]);
    $this->portalUser->assignRole('customer');
    Sanctum::actingAs($this->portalUser, ['customer:*']);
});

function membershipQuotePayload(string $planCode): array
{
    return [
        'purpose' => 'membership',
        'selected_branch_id' => 1,
        'plan_code' => $planCode,
        'selections' => [],
        'promo_code' => null,
    ];
}

function membershipWebhookPayload(PaymentCheckoutAttempt $attempt, PaymentProviderTransaction $transaction): array
{
    return [
        'PaymentId' => $transaction->provider_payment_id,
        'Amount' => number_format($attempt->payable_amount_cents / 100, 2, '.', ''),
        'StatusId' => '2',
        'TransactionId' => str_replace('-', '', $attempt->reference),
        'Custom1' => '',
        'VisaId' => 'membership-visa-'.$attempt->id,
    ];
}

function membershipWebhookSignature(array $payload): string
{
    $parts = [];
    foreach (['PaymentId', 'Amount', 'StatusId', 'TransactionId', 'Custom1', 'VisaId'] as $field) {
        if (array_key_exists($field, $payload) && (string) $payload[$field] !== '') {
            $parts[] = $field.'='.(string) $payload[$field];
        }
    }

    return base64_encode(hash_hmac(
        'sha256',
        implode(',', $parts),
        'webhook-secret',
        true,
    ));
}

function createMembershipCheckoutPromotion(
    $test,
    int $basisPoints = 1000,
    string $eligibility = 'both',
    array $overrides = [],
): MembershipPromotion {
    $planCode = (string) ($overrides['plan_code'] ?? '20');
    unset($overrides['plan_code']);
    $promotion = MembershipPromotion::query()->create(array_merge([
        'company_id' => $test->company->id,
        'code' => 'SAVEQAR23456',
        'discount_type' => 'percentage',
        'percentage_basis_points' => $basisPoints,
        'purchase_eligibility' => $eligibility,
        'starts_at' => now('UTC')->subDay(),
        'ends_at' => now('UTC')->addDay(),
        'total_limit' => 10,
        'per_customer_limit' => 1,
        'status' => 'active',
        'revision' => 1,
        'first_activated_at' => now('UTC'),
        'created_by' => $test->systemActor->id,
        'updated_by' => $test->systemActor->id,
    ], $overrides));
    $promotion->plans()->attach(
        MembershipPlan::query()
            ->where('company_id', $test->company->id)
            ->where('code', $planCode)
            ->value('id')
    );

    return $promotion;
}

function startMembershipCheckout($test, string $planCode): PaymentCheckoutAttempt
{
    $quotePayload = membershipQuotePayload($planCode);
    $quote = $test->postJson('/api/customer/checkouts/quote', $quotePayload)->assertOk();
    $test->postJson('/api/customer/checkouts', [
        'client_uuid' => (string) Str::uuid(),
        ...$quotePayload,
        'quote_fingerprint' => $quote->json('quote_fingerprint'),
        'accepted_terms_version' => 'v1',
    ])->assertStatus(202)->assertJsonPath('status', 'pending');

    return PaymentCheckoutAttempt::query()->latest('id')->firstOrFail();
}

function completeMembershipCheckout($test, PaymentCheckoutAttempt $attempt, ?CarbonImmutable $finishedAt = null): array
{
    $transaction = $attempt->providerTransactions()->firstOrFail();
    $provider = app(SkipCashProvider::class);
    expect($provider)->toBeInstanceOf(FakeSkipCashProvider::class);
    $provider->markPaid(
        $transaction->provider_payment_id,
        $finishedAt,
        'membership-visa-'.$attempt->id,
        'Credit Card',
    );
    $payload = membershipWebhookPayload($attempt, $transaction);
    $response = $test->postJson(
        '/api/integrations/skipcash/webhook',
        $payload,
        ['Authorization' => membershipWebhookSignature($payload)],
    );

    return [$response, $transaction->fresh()];
}

it('publishes the two company owned membership package prices', function (): void {
    $response = $this->getJson('/api/public/membership-plans')->assertOk();

    expect($response->json('data'))->toHaveCount(2)
        ->and($response->json('data.0.code'))->toBe('20')
        ->and($response->json('data.0.meal_count'))->toBe(20)
        ->and($response->json('data.0.package_price_cents'))->toBe(90000)
        ->and($response->json('data.0.currency'))->toBe('QAR')
        ->and($response->json('data.0.delivery_included'))->toBeTrue()
        ->and($response->json('data.1.code'))->toBe('26')
        ->and($response->json('data.1.meal_count'))->toBe(26)
        ->and($response->json('data.1.package_price_cents'))->toBe(120000);
});

it('creates one paid 20 meal allowance without orders invoices or immediate meal use', function (): void {
    $quotePayload = membershipQuotePayload('20');
    $quote = $this->postJson('/api/customer/checkouts/quote', $quotePayload)
        ->assertOk()
        ->assertJsonPath('gross_amount_cents', 90000)
        ->assertJsonPath('payable_amount_cents', 90000)
        ->assertJsonPath('purchase_allowance', 20)
        ->assertJsonPath('main_quantity', 0)
        ->assertJsonPath('delivery_included', true);
    expect(MealPlanRequest::query()->count())->toBe(0)
        ->and(MealSubscription::query()->count())->toBe(0)
        ->and(MembershipPurchaseBlock::query()->count())->toBe(0);

    $create = $this->postJson('/api/customer/checkouts', [
        'client_uuid' => (string) Str::uuid(),
        ...$quotePayload,
        'quote_fingerprint' => $quote->json('quote_fingerprint'),
        'accepted_terms_version' => 'v1',
    ])->assertStatus(202)->assertJsonPath('purchase_confirmed', false);

    $attempt = PaymentCheckoutAttempt::query()->firstOrFail();
    $request = MealPlanRequest::query()->firstOrFail();
    expect($attempt->purpose)->toBe('membership')
        ->and($request->status)->toBe('new')
        ->and($request->submission_kind)->toBe('paid_checkout')
        ->and((int) $request->checkout_id)->toBe((int) $attempt->id)
        ->and(DB::table('orders')->count())->toBe(0)
        ->and(DB::table('ar_invoices')->count())->toBe(0)
        ->and(Payment::query()->where('payment_source_id', $this->source->id)->count())->toBe(0)
        ->and($create->json('reference'))->toBe($attempt->reference);

    [$webhook, $providerTransaction] = completeMembershipCheckout($this, $attempt);
    $webhook->assertOk()->assertJsonPath('accepted', true);

    $attempt->refresh();
    $request->refresh();
    $subscription = MealSubscription::query()->firstOrFail();
    $block = MembershipPurchaseBlock::query()->firstOrFail();
    $payment = Payment::query()->where('payment_source_id', $this->source->id)->firstOrFail();
    expect($attempt->state)->toBe('completed')
        ->and($request->status)->toBe('converted')
        ->and((int) $request->converted_subscription_id)->toBe((int) $subscription->id)
        ->and($request->converted_at)->not->toBeNull()
        ->and($subscription->fulfillment_mode)->toBe('customer_selection')
        ->and($subscription->status)->toBe('active')
        ->and($subscription->end_date)->toBeNull()
        ->and((int) $subscription->plan_meals_total)->toBe(20)
        ->and((int) $subscription->meals_used)->toBe(0)
        ->and($subscription->days()->count())->toBe(7)
        ->and((int) $block->queue_position)->toBe(1)
        ->and((int) $block->meal_count)->toBe(20)
        ->and((int) $block->gross_price_cents)->toBe(90000)
        ->and((int) $block->final_price_cents)->toBe(90000)
        ->and((int) $block->payment_id)->toBe((int) $payment->id)
        ->and($payment->source)->toBe('ar')
        ->and($payment->method)->toBe('skipcash')
        ->and((int) $payment->amount_cents)->toBe(90000)
        ->and((int) $payment->unallocatedCents())->toBe(90000)
        ->and($providerTransaction->classification)->toBe('purchase')
        ->and((int) $providerTransaction->payment_id)->toBe((int) $payment->id)
        ->and(DB::table('orders')->count())->toBe(0)
        ->and(DB::table('ar_invoices')->count())->toBe(0)
        ->and(DB::table('payment_allocations')->count())->toBe(0)
        ->and(DB::table('bank_transactions')->where('source_type', 'ar_payment')->where('source_id', $payment->id)->count())->toBe(0)
        ->and($attempt->notification_dispatch['customer_confirmation']['state'])->toBe('sent')
        ->and($attempt->notification_dispatch['admin_confirmation']['state'])->toBe('sent');

    $this->getJson('/api/customer/checkouts/'.$attempt->reference)
        ->assertOk()
        ->assertJsonPath('status', 'completed')
        ->assertJsonPath('purchase_confirmed', true)
        ->assertJsonPath('paid_amount_cents', 90000)
        ->assertJsonPath('confirmed_amount_cents', 90000)
        ->assertJsonPath('retained_credit_amount_cents', 0)
        ->assertJsonPath('meal_plan_request_id', $request->id)
        ->assertJsonPath('subscription_id', $subscription->id)
        ->assertJsonPath('purchase_block_id', $block->id)
        ->assertJsonPath('queue.total_meals', 20)
        ->assertJsonPath('queue.available_meals', 20);

    $duplicate = $this->postJson(
        '/api/integrations/skipcash/webhook',
        membershipWebhookPayload($attempt, $providerTransaction),
        ['Authorization' => membershipWebhookSignature(membershipWebhookPayload($attempt, $providerTransaction))],
    );
    $duplicate->assertOk()->assertJsonPath('duplicate', true);
    expect(Payment::query()->where('payment_source_id', $this->source->id)->count())->toBe(1)
        ->and(MealSubscription::query()->count())->toBe(1)
        ->and(MembershipPurchaseBlock::query()->count())->toBe(1);
});

it('appends a paid 26 meal purchase after the first block on the same queue', function (): void {
    $first = startMembershipCheckout($this, '20');
    completeMembershipCheckout($this, $first)[0]->assertOk();
    $second = startMembershipCheckout($this, '26');
    completeMembershipCheckout($this, $second)[0]->assertOk();

    $subscription = MealSubscription::query()->firstOrFail();
    $blocks = MembershipPurchaseBlock::query()->orderBy('queue_position')->get();
    expect(MealSubscription::query()->count())->toBe(1)
        ->and(MealPlanRequest::query()->where('status', 'converted')->count())->toBe(2)
        ->and(Payment::query()->where('payment_source_id', $this->source->id)->count())->toBe(2)
        ->and($blocks)->toHaveCount(2)
        ->and($blocks->pluck('queue_position')->all())->toBe([1, 2])
        ->and($blocks->pluck('meal_count')->all())->toBe([20, 26])
        ->and((int) $subscription->plan_meals_total)->toBe(46)
        ->and((int) $subscription->meals_used)->toBe(0)
        ->and((int) $subscription->queue_revision)->toBe(2)
        ->and($subscription->end_date)->toBeNull();
});

it('records an after expiry membership payment as credit without activating allowance', function (): void {
    $attempt = startMembershipCheckout($this, '20');
    $attempt->update(['expires_at' => now('UTC')->subMinute()]);
    $attempt->refresh();
    [$webhook, $transaction] = completeMembershipCheckout(
        $this,
        $attempt,
        CarbonImmutable::now('UTC'),
    );
    $webhook->assertOk();

    $attempt->refresh();
    $request = MealPlanRequest::query()->firstOrFail();
    $payment = Payment::query()->where('payment_source_id', $this->source->id)->firstOrFail();
    expect($attempt->state)->toBe('payment_received_as_credit')
        ->and($attempt->targets()->firstOrFail()->hold_state)->toBe('released')
        ->and($request->status)->toBe('new')
        ->and($request->converted_subscription_id)->toBeNull()
        ->and($transaction->classification)->toBe('retained_credit')
        ->and((int) $payment->unallocatedCents())->toBe(90000)
        ->and(MealSubscription::query()->count())->toBe(0)
        ->and(MembershipPurchaseBlock::query()->count())->toBe(0);

    $this->getJson('/api/customer/checkouts/'.$attempt->reference)
        ->assertOk()
        ->assertJsonPath('status', 'completed')
        ->assertJsonPath('purchase_confirmed', false)
        ->assertJsonPath('paid_amount_cents', 90000)
        ->assertJsonPath('confirmed_amount_cents', 0)
        ->assertJsonPath('retained_credit_amount_cents', 90000);
});

it('keeps paid purchase selections and promotion paths closed without affecting empty purchases', function (): void {
    $this->postJson('/api/customer/checkouts/quote', [
        ...membershipQuotePayload('20'),
        'selections' => [['date' => now('Asia/Qatar')->addDay()->toDateString()]],
    ])->assertStatus(503)->assertJsonPath('code', 'MEMBERSHIP_SELECTIONS_NOT_AVAILABLE');

    $this->postJson('/api/customer/checkouts/quote', [
        ...membershipQuotePayload('20'),
        'promo_code' => 'TESTCODE',
    ])->assertStatus(503)->assertJsonPath('code', 'MEMBERSHIP_PROMOTIONS_NOT_AVAILABLE');

    expect(PaymentCheckoutAttempt::query()->count())->toBe(0)
        ->and(MealPlanRequest::query()->count())->toBe(0)
        ->and(Payment::query()->where('payment_source_id', $this->source->id)->count())->toBe(0);
});

it('reserves and permanently redeems one partial membership promotion after verified payment', function (): void {
    Config::set('payments.membership.promotions_enabled', true);
    $promotion = createMembershipCheckoutPromotion($this);
    $quotePayload = [
        ...membershipQuotePayload('20'),
        'promo_code' => $promotion->code,
    ];
    $quote = $this->postJson('/api/customer/checkouts/quote', $quotePayload)
        ->assertOk()
        ->assertJsonPath('result_kind', 'paid_membership')
        ->assertJsonPath('gross_amount_cents', 90000)
        ->assertJsonPath('discount_amount_cents', 9000)
        ->assertJsonPath('payable_amount_cents', 81000)
        ->assertJsonPath('purchase_allowance', 20)
        ->assertJsonPath('promotion.code', $promotion->code);
    expect(MembershipPromotionReservation::query()->count())->toBe(0)
        ->and(MembershipPromotionRedemption::query()->count())->toBe(0);

    $clientUuid = (string) Str::uuid();
    $this->postJson('/api/customer/checkouts', [
        'client_uuid' => $clientUuid,
        ...$quotePayload,
        'quote_fingerprint' => $quote->json('quote_fingerprint'),
        'accepted_terms_version' => 'v1',
    ])->assertStatus(202)
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('discount_amount_cents', 9000)
        ->assertJsonPath('payable_amount_cents', 81000);

    $attempt = PaymentCheckoutAttempt::query()->latest('id')->firstOrFail();
    $reservation = MembershipPromotionReservation::query()->firstOrFail();
    expect($attempt->discount_amount_cents)->toBe(9000)
        ->and($attempt->payable_amount_cents)->toBe(81000)
        ->and($reservation->status)->toBe('held')
        ->and($reservation->offer_snapshot['code'])->toBe($promotion->code)
        ->and(MembershipPromotionRedemption::query()->count())->toBe(0);

    $promotion->update(['status' => 'paused']);
    [$response] = completeMembershipCheckout($this, $attempt);
    $response->assertOk()->assertJson(['accepted' => true]);
    $block = MembershipPurchaseBlock::query()->firstOrFail();
    $payment = Payment::query()->where('payment_source_id', $this->source->id)->firstOrFail();
    $redemption = MembershipPromotionRedemption::query()->firstOrFail();

    expect($attempt->fresh()->state)->toBe('completed')
        ->and($reservation->fresh()->status)->toBe('redeemed')
        ->and($redemption->kind)->toBe('paid_purchase')
        ->and($redemption->purchase_block_id)->toBe($block->id)
        ->and($block->meal_count)->toBe(20)
        ->and($block->gross_price_cents)->toBe(90000)
        ->and($block->discount_cents)->toBe(9000)
        ->and($block->final_price_cents)->toBe(81000)
        ->and($payment->amount_cents)->toBe(81000)
        ->and($payment->unallocatedCents())->toBe(81000);

    $replay = $this->postJson('/api/customer/checkouts', [
        'client_uuid' => $clientUuid,
        ...$quotePayload,
        'quote_fingerprint' => $quote->json('quote_fingerprint'),
        'accepted_terms_version' => 'v1',
    ])->assertOk()->assertJsonPath('replayed', true);
    expect($replay->json('promotion.code'))->toBe($promotion->code)
        ->and(MembershipPromotionRedemption::query()->count())->toBe(1);
});

it('enforces first and renewal eligibility from completed membership history', function (): void {
    Config::set('payments.membership.promotions_enabled', true);
    $first = createMembershipCheckoutPromotion($this, 1000, 'first', [
        'code' => 'STARTQA23456',
    ]);
    $renewal = createMembershipCheckoutPromotion($this, 1000, 'renewal', [
        'code' => 'RENEWQA23456',
    ]);

    $this->postJson('/api/customer/checkouts/quote', [
        ...membershipQuotePayload('20'),
        'promo_code' => $first->code,
    ])->assertOk()->assertJsonPath('promotion.purchase_eligibility', 'first');
    $this->postJson('/api/customer/checkouts/quote', [
        ...membershipQuotePayload('20'),
        'promo_code' => $renewal->code,
    ])->assertStatus(422)->assertJsonPath('code', 'PROMOTION_PURCHASE_INELIGIBLE');

    $attempt = startMembershipCheckout($this, '20');
    completeMembershipCheckout($this, $attempt)[0]->assertOk();

    $this->postJson('/api/customer/checkouts/quote', [
        ...membershipQuotePayload('20'),
        'promo_code' => $first->code,
    ])->assertStatus(422)->assertJsonPath('code', 'PROMOTION_PURCHASE_INELIGIBLE');
    $this->postJson('/api/customer/checkouts/quote', [
        ...membershipQuotePayload('20'),
        'promo_code' => $renewal->code,
    ])->assertOk()->assertJsonPath('promotion.purchase_eligibility', 'renewal');
});

it('returns a review conflict when an accepted promotion changes before checkout creation', function (): void {
    Config::set('payments.membership.promotions_enabled', true);
    $promotion = createMembershipCheckoutPromotion($this);
    $payload = [
        ...membershipQuotePayload('20'),
        'promo_code' => $promotion->code,
    ];
    $quote = $this->postJson('/api/customer/checkouts/quote', $payload)->assertOk();
    $promotion->update(['status' => 'paused', 'revision' => 2]);

    $this->postJson('/api/customer/checkouts', [
        'client_uuid' => (string) Str::uuid(),
        ...$payload,
        'quote_fingerprint' => $quote->json('quote_fingerprint'),
        'accepted_terms_version' => 'v1',
    ])->assertStatus(409)->assertJsonPath('code', 'QUOTE_CHANGED');

    expect(PaymentCheckoutAttempt::query()->count())->toBe(0)
        ->and(MealPlanRequest::query()->count())->toBe(0)
        ->and(MembershipPromotionReservation::query()->count())->toBe(0);
});

it('holds the final promotion use during payment and releases it after a decline', function (): void {
    Config::set('payments.membership.promotions_enabled', true);
    $promotion = createMembershipCheckoutPromotion($this, 1000, 'both', [
        'code' => 'CAPQAR234567',
        'total_limit' => 1,
    ]);
    $payload = [
        ...membershipQuotePayload('20'),
        'promo_code' => $promotion->code,
    ];
    $quote = $this->postJson('/api/customer/checkouts/quote', $payload)->assertOk();
    $this->postJson('/api/customer/checkouts', [
        'client_uuid' => (string) Str::uuid(),
        ...$payload,
        'quote_fingerprint' => $quote->json('quote_fingerprint'),
        'accepted_terms_version' => 'v1',
    ])->assertStatus(202);
    $attempt = PaymentCheckoutAttempt::query()->latest('id')->firstOrFail();

    $otherCustomer = Customer::factory()->create(['email' => 'other-promo@example.test']);
    $otherUser = User::factory()->create([
        'customer_id' => $otherCustomer->id,
        'portal_name' => 'Other Promotion Customer',
        'portal_phone' => '+97455000002',
        'portal_phone_e164' => '+97455000002',
        'portal_delivery_address' => 'Doha',
        'email' => 'other-promo@example.test',
        'status' => 'active',
    ]);
    $otherUser->assignRole('customer');
    Sanctum::actingAs($otherUser, ['customer:*']);

    $this->postJson('/api/customer/checkouts/quote', $payload)
        ->assertStatus(422)
        ->assertJsonPath('code', 'PROMOTION_EXHAUSTED');

    $transaction = $attempt->providerTransactions()->firstOrFail();
    $decline = membershipWebhookPayload($attempt, $transaction);
    $decline['StatusId'] = '3';
    $this->postJson(
        '/api/integrations/skipcash/webhook',
        $decline,
        ['Authorization' => membershipWebhookSignature($decline)],
    )->assertOk()->assertJson(['accepted' => true]);

    $this->postJson('/api/customer/checkouts/quote', $payload)
        ->assertOk()
        ->assertJsonPath('promotion.code', $promotion->code);
    expect($attempt->fresh()->promotionReservation()->firstOrFail()->status)->toBe('released')
        ->and(MembershipPromotionRedemption::query()->count())->toBe(0);
});

it('releases a partial promotion hold after a terminal unpaid result', function (): void {
    Config::set('payments.membership.promotions_enabled', true);
    $promotion = createMembershipCheckoutPromotion($this);
    $quotePayload = [
        ...membershipQuotePayload('20'),
        'promo_code' => $promotion->code,
    ];
    $quote = $this->postJson('/api/customer/checkouts/quote', $quotePayload)->assertOk();
    $this->postJson('/api/customer/checkouts', [
        'client_uuid' => (string) Str::uuid(),
        ...$quotePayload,
        'quote_fingerprint' => $quote->json('quote_fingerprint'),
        'accepted_terms_version' => 'v1',
    ])->assertStatus(202);
    $attempt = PaymentCheckoutAttempt::query()->latest('id')->firstOrFail();
    $transaction = $attempt->providerTransactions()->firstOrFail();
    $payload = membershipWebhookPayload($attempt, $transaction);
    $payload['StatusId'] = '3';

    $this->postJson(
        '/api/integrations/skipcash/webhook',
        $payload,
        ['Authorization' => membershipWebhookSignature($payload)],
    )->assertOk()->assertJson(['accepted' => true]);

    expect($attempt->fresh()->state)->toBe('declined')
        ->and($attempt->targets()->firstOrFail()->hold_state)->toBe('released')
        ->and(MembershipPromotionReservation::query()->firstOrFail()->status)->toBe('released')
        ->and(MembershipPromotionRedemption::query()->count())->toBe(0);
});

it('releases a partial promotion hold when verified payment finishes after checkout expiry', function (): void {
    Config::set('payments.membership.promotions_enabled', true);
    $promotion = createMembershipCheckoutPromotion($this);
    $quotePayload = [
        ...membershipQuotePayload('20'),
        'promo_code' => $promotion->code,
    ];
    $quote = $this->postJson('/api/customer/checkouts/quote', $quotePayload)->assertOk();
    $this->postJson('/api/customer/checkouts', [
        'client_uuid' => (string) Str::uuid(),
        ...$quotePayload,
        'quote_fingerprint' => $quote->json('quote_fingerprint'),
        'accepted_terms_version' => 'v1',
    ])->assertStatus(202);

    $attempt = PaymentCheckoutAttempt::query()->latest('id')->firstOrFail();
    $attempt->update(['expires_at' => now('UTC')->subMinute()]);
    [$response] = completeMembershipCheckout($this, $attempt->fresh(), CarbonImmutable::now('UTC'));
    $response->assertOk()->assertJson(['accepted' => true]);

    expect($attempt->fresh()->state)->toBe('payment_received_as_credit')
        ->and($attempt->promotionReservation()->firstOrFail()->status)->toBe('released')
        ->and(MembershipPromotionRedemption::query()->count())->toBe(0)
        ->and(MembershipPurchaseBlock::query()->count())->toBe(0)
        ->and(Payment::query()->where('payment_source_id', $this->source->id)->firstOrFail()->amount_cents)->toBe(81000);
});

it('quotes a zero membership promotion but refuses to start a payment checkout', function (): void {
    Config::set('payments.membership.promotions_enabled', true);
    $promotion = createMembershipCheckoutPromotion($this, 10000);
    $quotePayload = [
        ...membershipQuotePayload('20'),
        'promo_code' => $promotion->code,
    ];
    $quote = $this->postJson('/api/customer/checkouts/quote', $quotePayload)
        ->assertOk()
        ->assertJsonPath('result_kind', 'pending_request')
        ->assertJsonPath('discount_amount_cents', 90000)
        ->assertJsonPath('payable_amount_cents', 0)
        ->assertJsonPath('can_checkout', false)
        ->assertJsonPath('can_submit_request', true);

    $this->postJson('/api/customer/checkouts', [
        'client_uuid' => (string) Str::uuid(),
        ...$quotePayload,
        'quote_fingerprint' => $quote->json('quote_fingerprint'),
        'accepted_terms_version' => 'v1',
    ])->assertStatus(409)->assertJsonPath('code', 'PROMOTION_REQUEST_REQUIRED');

    expect(PaymentCheckoutAttempt::query()->count())->toBe(0)
        ->and(MealPlanRequest::query()->count())->toBe(0)
        ->and(MembershipPromotionReservation::query()->count())->toBe(0)
        ->and(MembershipPromotionRedemption::query()->count())->toBe(0);
});

it('keeps membership checkout closed unless checkout and queue launch flags are enabled', function (): void {
    Config::set('payments.membership.queue_enabled', false);

    $this->postJson('/api/customer/checkouts/quote', membershipQuotePayload('20'))
        ->assertStatus(503)
        ->assertJsonPath('code', 'MEMBERSHIP_CHECKOUT_DISABLED');

    expect(PaymentCheckoutAttempt::query()->count())->toBe(0)
        ->and(MealPlanRequest::query()->count())->toBe(0)
        ->and(Payment::query()->where('payment_source_id', $this->source->id)->count())->toBe(0);
});
