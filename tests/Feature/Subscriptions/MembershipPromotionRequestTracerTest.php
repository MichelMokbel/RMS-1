<?php

use App\Jobs\SendMembershipPromotionRequestConfirmation;
use App\Models\AccountingAuditLog;
use App\Models\AccountingCompany;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DailyDishMenu;
use App\Models\DailyDishMenuItem;
use App\Models\EmailLog;
use App\Models\LedgerAccount;
use App\Models\MealPlanRequest;
use App\Models\MealSubscription;
use App\Models\MembershipPlan;
use App\Models\MembershipPromotion;
use App\Models\MembershipPromotionRedemption;
use App\Models\MembershipPromotionReservation;
use App\Models\MembershipPurchaseBlock;
use App\Models\MenuItem;
use App\Models\Payment;
use App\Models\PaymentCheckoutAttempt;
use App\Models\PaymentSetting;
use App\Models\PaymentSource;
use App\Models\User;
use App\Services\Customers\CustomerMergeService;
use App\Services\Mail\EmailLogService;
use App\Services\Mail\MailSettingsService;
use App\Services\Payments\SkipCashRecoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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
    $clearingAccount = LedgerAccount::factory()->create([
        'company_id' => $this->company->id,
        'is_active' => true,
    ]);
    $this->source = PaymentSource::query()->create([
        'company_id' => $this->company->id,
        'code' => 'skipcash',
        'name' => 'SkipCash',
        'method' => 'skipcash',
        'clearing_account_id' => $clearingAccount->id,
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
    Config::set('payments.membership.promotions_enabled', true);
    Config::set('payments.skipcash.enabled', true);
    Config::set('mail.daily_dish_admin_emails', ['ops@example.test']);
    Config::set('payment_terms.published', [[
        'version' => 'v1',
        'effective_at' => '2026-01-01T00:00:00+00:00',
        'url' => 'https://orders.example.test/terms/v1',
        'content_path' => $termsPath,
        'content_hash' => hash_file('sha256', $termsPath),
    ]]);

    $this->customer = Customer::factory()->create(['email' => 'promo-request@example.test']);
    $this->portalUser = User::factory()->create([
        'customer_id' => $this->customer->id,
        'name' => 'Promotion Request Customer',
        'portal_name' => 'Promotion Request Customer',
        'portal_phone' => '+97455683442',
        'portal_phone_e164' => '+97455683442',
        'portal_delivery_address' => 'Doha',
        'email' => 'promo-request@example.test',
        'status' => 'active',
    ]);
    $this->portalUser->assignRole('customer');
    Sanctum::actingAs($this->portalUser, ['customer:*']);
});

function createZeroMembershipPromotion($test, int $basisPoints = 10000): MembershipPromotion
{
    $promotion = MembershipPromotion::query()->create([
        'company_id' => $test->company->id,
        'code' => 'FREEQAR23456',
        'discount_type' => 'percentage',
        'percentage_basis_points' => $basisPoints,
        'purchase_eligibility' => 'both',
        'starts_at' => now('UTC')->subDay(),
        'ends_at' => now('UTC')->addDay(),
        'total_limit' => 10,
        'per_customer_limit' => 1,
        'status' => 'active',
        'revision' => 1,
        'first_activated_at' => now('UTC'),
        'created_by' => $test->systemActor->id,
        'updated_by' => $test->systemActor->id,
    ]);
    $promotion->plans()->attach(
        MembershipPlan::query()
            ->where('company_id', $test->company->id)
            ->where('code', '20')
            ->value('id')
    );

    return $promotion;
}

function createPromotionRequestMenu(int $branchId, string $date): MenuItem
{
    $suffix = str_replace('-', '', $date);
    $main = MenuItem::factory()->create(['code' => 'PROMO-MAIN-'.$suffix, 'name' => 'Promotion Main '.$suffix]);
    $salad = MenuItem::factory()->create(['code' => 'PROMO-SALAD-'.$suffix, 'name' => 'Promotion Salad '.$suffix]);
    $dessert = MenuItem::factory()->create(['code' => 'PROMO-DESSERT-'.$suffix, 'name' => 'Promotion Dessert '.$suffix]);
    $menu = DailyDishMenu::query()->create([
        'branch_id' => $branchId,
        'service_date' => $date,
        'status' => 'published',
    ]);
    foreach ([[$main, 'main'], [$salad, 'salad'], [$dessert, 'dessert']] as $index => [$item, $role]) {
        DailyDishMenuItem::query()->create([
            'daily_dish_menu_id' => $menu->id,
            'menu_item_id' => $item->id,
            'role' => $role,
            'sort_order' => $index + 1,
            'is_required' => true,
        ]);
    }

    return $main;
}

function promotionRequestSelection(string $date, int $mainId, int $quantity): array
{
    return [
        'key' => $date,
        'mains' => [[
            'menu_item_id' => $mainId,
            'portion' => 'plate',
            'qty' => $quantity,
        ]],
        'salad_qty' => 0,
        'dessert_qty' => 0,
        'notes' => null,
    ];
}

function quoteZeroMembershipPromotion($test, MembershipPromotion $promotion): array
{
    $payload = [
        'purpose' => 'membership',
        'selected_branch_id' => 1,
        'plan_code' => '20',
        'selections' => [],
        'promo_code' => $promotion->code,
    ];
    $response = $test->postJson('/api/customer/checkouts/quote', $payload)
        ->assertOk()
        ->assertJsonPath('result_kind', 'pending_request')
        ->assertJsonPath('payable_amount_cents', 0);

    return [$payload, $response->json('quote_fingerprint')];
}

it('creates one permanent request only redemption and preserves exact replay', function (): void {
    Queue::fake([SendMembershipPromotionRequestConfirmation::class]);
    $promotion = createZeroMembershipPromotion($this);
    [, $quoteFingerprint] = quoteZeroMembershipPromotion($this, $promotion);
    $today = now('Asia/Qatar')->toDateString();
    $future = now('Asia/Qatar')->addDays(2)->toDateString();
    $todayMain = createPromotionRequestMenu($this->branch->id, $today);
    $futureMain = createPromotionRequestMenu($this->branch->id, $future);
    $selections = [
        promotionRequestSelection($today, $todayMain->id, 1),
        promotionRequestSelection($future, $futureMain->id, 2),
    ];
    $clientUuid = (string) Str::uuid();
    $payload = [
        'client_uuid' => $clientUuid,
        'selected_branch_id' => 1,
        'plan_code' => '20',
        'promo_code' => $promotion->code,
        'selections' => $selections,
        'quote_fingerprint' => $quoteFingerprint,
        'accepted_terms_version' => 'v1',
    ];

    $created = $this->postJson('/api/customer/membership-requests', $payload)
        ->assertCreated()
        ->assertJsonPath('reference', strtolower($clientUuid))
        ->assertJsonPath('result_kind', 'pending_request')
        ->assertJsonPath('status', 'new')
        ->assertJsonPath('replayed', false)
        ->assertJsonPath('gross_amount_cents', 90000)
        ->assertJsonPath('discount_amount_cents', 90000)
        ->assertJsonPath('payable_amount_cents', 0)
        ->assertJsonPath('main_quantity', 2)
        ->assertJsonCount(1, 'proposed_selections')
        ->assertJsonCount(1, 'excluded_today');

    $request = MealPlanRequest::query()->firstOrFail();
    $redemption = MembershipPromotionRedemption::query()->firstOrFail();
    expect($request->submission_kind)->toBe('promo_request')
        ->and($request->status)->toBe('new')
        ->and($request->converted_subscription_id)->toBeNull()
        ->and($request->checkout_id)->toBeNull()
        ->and($request->proposed_selections_snapshot['main_quantity'])->toBe(2)
        ->and(data_get($request->proposed_selections_snapshot, 'selections.0.salad_qty'))->toBe(2)
        ->and(data_get($request->proposed_selections_snapshot, 'selections.0.dessert_qty'))->toBe(2)
        ->and($redemption->kind)->toBe('zero_request')
        ->and($redemption->checkout_id)->toBeNull()
        ->and($redemption->reservation_id)->toBeNull()
        ->and($redemption->purchase_block_id)->toBeNull()
        ->and($redemption->net_cents)->toBe(0)
        ->and($redemption->zero_subject_key)->toHaveLength(64)
        ->and(PaymentCheckoutAttempt::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0)
        ->and(MealSubscription::query()->count())->toBe(0)
        ->and(MembershipPurchaseBlock::query()->count())->toBe(0)
        ->and(MembershipPromotionReservation::query()->count())->toBe(0)
        ->and(DB::table('orders')->count())->toBe(0)
        ->and(DB::table('ar_invoices')->count())->toBe(0)
        ->and(DB::table('payment_allocations')->count())->toBe(0)
        ->and(AccountingAuditLog::query()->where('action', 'membership_promotion.zero_redeemed')->count())->toBe(1);
    Queue::assertPushed(SendMembershipPromotionRequestConfirmation::class, 2);

    $this->getJson('/api/customer/membership-requests/'.$clientUuid)
        ->assertOk()
        ->assertJsonPath('request_id', $request->id)
        ->assertJsonPath('main_quantity', 2);

    $this->postJson('/api/customer/membership-requests', $payload)
        ->assertOk()
        ->assertJsonPath('reference', strtolower($clientUuid))
        ->assertJsonPath('replayed', true)
        ->assertJsonPath('main_quantity', 2);

    $this->postJson('/api/customer/membership-requests', [
        ...$payload,
        'plan_code' => '26',
    ])->assertStatus(409)->assertJsonPath('code', 'REQUEST_CHANGED');

    $request->update(['status' => 'closed']);
    $promotion->update(['status' => 'paused']);
    $this->postJson('/api/customer/membership-requests', [
        ...$payload,
        'client_uuid' => (string) Str::uuid(),
        'selections' => [],
    ])->assertOk()
        ->assertJsonPath('reference', strtolower($clientUuid))
        ->assertJsonPath('status', 'closed')
        ->assertJsonPath('replayed', true)
        ->assertJsonPath('main_quantity', 2);

    expect(MealPlanRequest::query()->count())->toBe(1)
        ->and(MembershipPromotionRedemption::query()->count())->toBe(1);

    Role::findOrCreate('admin', 'web');
    $this->systemActor->assignRole('admin');
    $this->actingAs($this->systemActor)
        ->get(route('meal-plan-requests.show', $request))
        ->assertOk()
        ->assertSee('Promotional Membership Request')
        ->assertSee($promotion->code)
        ->assertSee('No payment, subscription or meal allowance was created automatically.');
});

it('rejects a nonzero promotion from the request only endpoint without side effects', function (): void {
    Queue::fake([SendMembershipPromotionRequestConfirmation::class]);
    $promotion = createZeroMembershipPromotion($this, 1000);
    $quotePayload = [
        'purpose' => 'membership',
        'selected_branch_id' => 1,
        'plan_code' => '20',
        'selections' => [],
        'promo_code' => $promotion->code,
    ];
    $quote = $this->postJson('/api/customer/checkouts/quote', $quotePayload)
        ->assertOk()
        ->assertJsonPath('payable_amount_cents', 81000);

    $this->postJson('/api/customer/membership-requests', [
        'client_uuid' => (string) Str::uuid(),
        'selected_branch_id' => 1,
        'plan_code' => '20',
        'promo_code' => $promotion->code,
        'selections' => [],
        'quote_fingerprint' => $quote->json('quote_fingerprint'),
        'accepted_terms_version' => 'v1',
    ])->assertStatus(422)->assertJsonPath('code', 'PROMOTION_REQUIRES_PAYMENT');

    expect(MealPlanRequest::query()->count())->toBe(0)
        ->and(MembershipPromotionRedemption::query()->count())->toBe(0)
        ->and(PaymentCheckoutAttempt::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0);
});

it('sends the durable customer and administrator request confirmations from saved recipients', function (): void {
    Queue::fake([SendMembershipPromotionRequestConfirmation::class]);
    $promotion = createZeroMembershipPromotion($this);
    [, $quoteFingerprint] = quoteZeroMembershipPromotion($this, $promotion);
    $this->postJson('/api/customer/membership-requests', [
        'client_uuid' => (string) Str::uuid(),
        'selected_branch_id' => 1,
        'plan_code' => '20',
        'promo_code' => $promotion->code,
        'selections' => [],
        'quote_fingerprint' => $quoteFingerprint,
        'accepted_terms_version' => 'v1',
    ])->assertCreated();
    $request = MealPlanRequest::query()->firstOrFail();

    foreach (SendMembershipPromotionRequestConfirmation::AUDIENCES as $audience) {
        (new SendMembershipPromotionRequestConfirmation($request->id, $audience))->handle(
            app(EmailLogService::class),
            app(MailSettingsService::class),
        );
    }

    $request->refresh();
    expect(data_get($request->notification_dispatch, 'customer_confirmation.state'))->toBe('sent')
        ->and(data_get($request->notification_dispatch, 'admin_confirmation.state'))->toBe('sent')
        ->and(EmailLog::query()->where('category', 'membership_promotion_request_confirmation')->count())->toBe(2)
        ->and(EmailLog::query()->where('meal_plan_request_id', $request->id)->count())->toBe(2);

    $dispatch = $request->notification_dispatch;
    $dispatch['customer_confirmation'] = [
        'state' => 'retryable',
        'attempts' => 1,
        'next_retry_at' => now('UTC')->subMinute()->toIso8601String(),
    ];
    $request->update(['notification_dispatch' => $dispatch]);
    $recovery = app(SkipCashRecoveryService::class)->recover();
    expect($recovery['promotion_request_confirmations_retried'])->toBe(1);
    Queue::assertPushed(SendMembershipPromotionRequestConfirmation::class, 3);
});

it('does not expose another customers promotional membership request', function (): void {
    Queue::fake([SendMembershipPromotionRequestConfirmation::class]);
    $promotion = createZeroMembershipPromotion($this);
    [, $quoteFingerprint] = quoteZeroMembershipPromotion($this, $promotion);
    $clientUuid = (string) Str::uuid();
    $this->postJson('/api/customer/membership-requests', [
        'client_uuid' => $clientUuid,
        'selected_branch_id' => 1,
        'plan_code' => '20',
        'promo_code' => $promotion->code,
        'selections' => [],
        'quote_fingerprint' => $quoteFingerprint,
        'accepted_terms_version' => 'v1',
    ])->assertCreated();

    $otherCustomer = Customer::factory()->create();
    $otherUser = User::factory()->create([
        'customer_id' => $otherCustomer->id,
        'status' => 'active',
    ]);
    $otherUser->assignRole('customer');
    Sanctum::actingAs($otherUser, ['customer:*']);

    $this->getJson('/api/customer/membership-requests/'.$clientUuid)->assertNotFound();
});

it('returns the original request to the destination customer login after a merge', function (): void {
    Queue::fake([SendMembershipPromotionRequestConfirmation::class]);
    $promotion = createZeroMembershipPromotion($this);
    [, $quoteFingerprint] = quoteZeroMembershipPromotion($this, $promotion);
    $originalReference = (string) Str::uuid();
    $payload = [
        'client_uuid' => $originalReference,
        'selected_branch_id' => 1,
        'plan_code' => '20',
        'promo_code' => $promotion->code,
        'selections' => [],
        'quote_fingerprint' => $quoteFingerprint,
        'accepted_terms_version' => 'v1',
    ];
    $this->postJson('/api/customer/membership-requests', $payload)->assertCreated();

    Role::findOrCreate('admin', 'web');
    $this->systemActor->assignRole('admin');
    $destination = Customer::factory()->create(['email' => 'destination@example.test']);
    $destinationUser = User::factory()->create([
        'customer_id' => $destination->id,
        'name' => 'Destination Customer',
        'portal_name' => 'Destination Customer',
        'portal_phone' => '+97455683443',
        'portal_phone_e164' => '+97455683443',
        'email' => 'destination@example.test',
        'status' => 'active',
    ]);
    $destinationUser->assignRole('customer');
    app(CustomerMergeService::class)->merge($this->customer, $destination, $this->systemActor->id);
    Sanctum::actingAs($destinationUser, ['customer:*']);

    $this->getJson('/api/customer/membership-requests/'.$originalReference)
        ->assertOk()
        ->assertJsonPath('reference', strtolower($originalReference));
    $this->postJson('/api/customer/membership-requests', [
        ...$payload,
        'client_uuid' => (string) Str::uuid(),
    ])->assertOk()
        ->assertJsonPath('reference', strtolower($originalReference))
        ->assertJsonPath('replayed', true);

    expect(MealPlanRequest::query()->count())->toBe(1)
        ->and(MealPlanRequest::query()->firstOrFail()->customer_id)->toBe($destination->id)
        ->and(MembershipPromotionRedemption::query()->count())->toBe(1)
        ->and(MembershipPromotionRedemption::query()->firstOrFail()->original_customer_id)->toBe($this->customer->id);
});
