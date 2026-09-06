<?php

use App\Models\AccountingCompany;
use App\Models\ArInvoice;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DailyDishMenu;
use App\Models\DailyDishMenuItem;
use App\Models\LedgerAccount;
use App\Models\MealSubscription;
use App\Models\MembershipBookingFunding;
use App\Models\MembershipBookingOperation;
use App\Models\MembershipPurchaseBlock;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentCheckoutAttempt;
use App\Models\PaymentSetting;
use App\Models\PaymentSource;
use App\Models\User;
use App\Services\AR\ArInvoiceService;
use App\Services\Payments\FakeSkipCashProvider;
use App\Services\Payments\SkipCashProvider;
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
    Config::set('payments.membership.booking_enabled', true);
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

    $this->customer = Customer::factory()->create(['email' => 'covered-member@example.test']);
    $this->portalUser = User::factory()->create([
        'customer_id' => $this->customer->id,
        'name' => 'Covered Member',
        'portal_name' => 'Covered Member',
        'portal_phone' => '+97455683442',
        'portal_phone_e164' => '+97455683442',
        'portal_delivery_address' => 'Doha',
        'email' => 'covered-member@example.test',
        'status' => 'active',
    ]);
    $this->portalUser->assignRole('customer');
    Sanctum::actingAs($this->portalUser, ['customer:*']);
});

function completeCoveredBookingMembership($test, string $planCode): PaymentCheckoutAttempt
{
    $payload = [
        'purpose' => 'membership',
        'selected_branch_id' => 1,
        'plan_code' => $planCode,
        'selections' => [],
        'promo_code' => null,
    ];
    $quote = $test->postJson('/api/customer/checkouts/quote', $payload)->assertOk();
    $test->postJson('/api/customer/checkouts', [
        'client_uuid' => (string) Str::uuid(),
        ...$payload,
        'quote_fingerprint' => $quote->json('quote_fingerprint'),
        'accepted_terms_version' => 'v1',
    ])->assertStatus(202);

    $attempt = PaymentCheckoutAttempt::query()->latest('id')->firstOrFail();
    $transaction = $attempt->providerTransactions()->firstOrFail();
    $provider = app(SkipCashProvider::class);
    expect($provider)->toBeInstanceOf(FakeSkipCashProvider::class);
    $provider->markPaid($transaction->provider_payment_id, visaId: 'booking-'.$attempt->id);
    $body = [
        'PaymentId' => $transaction->provider_payment_id,
        'Amount' => number_format($attempt->payable_amount_cents / 100, 2, '.', ''),
        'StatusId' => '2',
        'TransactionId' => str_replace('-', '', $attempt->reference),
        'Custom1' => '',
        'VisaId' => 'booking-'.$attempt->id,
    ];
    $signature = base64_encode(hash_hmac(
        'sha256',
        'PaymentId='.$body['PaymentId'].',Amount='.$body['Amount'].',StatusId=2,TransactionId='.$body['TransactionId'].',VisaId='.$body['VisaId'],
        'webhook-secret',
        true,
    ));
    $test->postJson('/api/integrations/skipcash/webhook', $body, ['Authorization' => $signature])
        ->assertOk();

    return $attempt->fresh();
}

function createCoveredBookingMenu(int $branchId, string $date): MenuItem
{
    $suffix = str_replace('-', '', $date);
    $main = MenuItem::factory()->create(['code' => 'BOOK-MAIN-'.$suffix, 'name' => 'Booking Main '.$suffix]);
    $salad = MenuItem::factory()->create(['code' => 'BOOK-SALAD-'.$suffix, 'name' => 'Booking Salad '.$suffix]);
    $dessert = MenuItem::factory()->create(['code' => 'BOOK-DESSERT-'.$suffix, 'name' => 'Booking Dessert '.$suffix]);
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

function coveredBookingSelection(string $date, int $mainId, int $quantity): array
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

it('books future main quantities from the paid allowance without another payment', function (): void {
    completeCoveredBookingMembership($this, '20');
    $subscription = MealSubscription::query()->firstOrFail();
    $payment = Payment::query()->where('payment_source_id', $this->source->id)->firstOrFail();
    $today = now('Asia/Qatar')->toDateString();
    $future = now('Asia/Qatar')->addDays(2)->toDateString();
    $todayMain = createCoveredBookingMenu($this->branch->id, $today);
    $futureMain = createCoveredBookingMenu($this->branch->id, $future);

    $this->getJson('/api/customer/memberships?selected_branch_id=1')
        ->assertOk()
        ->assertJsonPath('data.queue_reference', $subscription->subscription_code)
        ->assertJsonPath('data.available_meals', 20)
        ->assertJsonPath('data.queue_revision', 1)
        ->assertJsonPath('booking_enabled', true);

    $selections = [
        coveredBookingSelection($today, $todayMain->id, 1),
        coveredBookingSelection($future, $futureMain->id, 2),
    ];
    $quote = $this->postJson('/api/customer/membership-bookings/quote', [
        'selected_branch_id' => 1,
        'queue_reference' => $subscription->subscription_code,
        'selections' => $selections,
    ])->assertOk()
        ->assertJsonPath('result_kind', 'covered_booking')
        ->assertJsonPath('main_quantity', 2)
        ->assertJsonPath('payable_amount_cents', 0)
        ->assertJsonCount(1, 'excluded_today')
        ->assertJsonPath('queue.available_meals', 20)
        ->assertJsonPath('can_book', true);

    $clientUuid = (string) Str::uuid();
    $request = [
        'client_uuid' => $clientUuid,
        'selected_branch_id' => 1,
        'queue_reference' => $subscription->subscription_code,
        'queue_revision' => 1,
        'selections' => $selections,
        'quote_fingerprint' => $quote->json('quote_fingerprint'),
        'accepted_terms_version' => 'v1',
    ];
    $created = $this->postJson('/api/customer/membership-bookings', $request)
        ->assertCreated()
        ->assertJsonPath('result_kind', 'covered_booking')
        ->assertJsonPath('payable_amount_cents', 0)
        ->assertJsonPath('main_quantity', 2)
        ->assertJsonPath('queue.used_meals', 2)
        ->assertJsonPath('queue.available_meals', 18)
        ->assertJsonCount(1, 'bookings');

    $invoice = ArInvoice::query()->firstOrFail();
    $funding = MembershipBookingFunding::query()->firstOrFail();
    $order = Order::query()->firstOrFail();
    expect(Payment::query()->where('payment_source_id', $this->source->id)->count())->toBe(1)
        ->and(MembershipBookingOperation::query()->count())->toBe(1)
        ->and(MembershipBookingFunding::query()->count())->toBe(1)
        ->and($funding->position_ranges)->toBe([[1, 2]])
        ->and((int) $funding->main_quantity)->toBe(2)
        ->and((int) $funding->invoice_net_cents)->toBe(9000)
        ->and($funding->state)->toBe('invoiced')
        ->and($funding->change_deadline_at?->setTimezone('Asia/Qatar')->format('Y-m-d H:i:s'))->toBe(now('Asia/Qatar')->addDay()->toDateString().' 23:00:00')
        ->and($invoice->status)->toBe('paid')
        ->and((int) $invoice->total_cents)->toBe(9000)
        ->and((int) $invoice->paid_total_cents)->toBe(9000)
        ->and((int) $invoice->balance_cents)->toBe(0)
        ->and((int) $payment->fresh()->unallocatedCents())->toBe(81000)
        ->and((int) $subscription->fresh()->meals_used)->toBe(2)
        ->and($order->scheduled_date?->toDateString())->toBe($future)
        ->and($order->items()->whereIn('role', ['main', 'diet', 'vegetarian'])->sum('quantity'))->toBe('2.000');

    $this->postJson('/api/customer/membership-bookings', $request)
        ->assertOk()
        ->assertJsonPath('replayed', true)
        ->assertJsonPath('bookings.0.booking_reference', $created->json('bookings.0.booking_reference'));
    expect(Order::query()->count())->toBe(1)
        ->and(ArInvoice::query()->count())->toBe(1)
        ->and(Payment::query()->count())->toBe(1)
        ->and(MembershipBookingFunding::query()->count())->toBe(1);

    app(ArInvoiceService::class)->void($invoice, $this->systemActor->id, 'Customer booking cancelled');
    expect($invoice->fresh()->status)->toBe('voided')
        ->and($order->fresh()->status)->toBe('Cancelled')
        ->and($funding->fresh()->state)->toBe('released')
        ->and((int) $subscription->fresh()->meals_used)->toBe(0)
        ->and((int) $payment->fresh()->unallocatedCents())->toBe(90000);
    $this->getJson('/api/customer/memberships?selected_branch_id=1')
        ->assertOk()
        ->assertJsonPath('data.available_meals', 20);
});

it('uses the first membership fully before funding a booking from the next purchase', function (): void {
    completeCoveredBookingMembership($this, '20');
    completeCoveredBookingMembership($this, '26');
    $subscription = MealSubscription::query()->firstOrFail();
    $date = now('Asia/Qatar')->addDays(3)->toDateString();
    $main = createCoveredBookingMenu($this->branch->id, $date);
    $selections = [coveredBookingSelection($date, $main->id, 21)];
    $quote = $this->postJson('/api/customer/membership-bookings/quote', [
        'selected_branch_id' => 1,
        'queue_reference' => $subscription->subscription_code,
        'selections' => $selections,
    ])->assertOk()->assertJsonPath('queue.available_meals', 46);

    $request = [
        'client_uuid' => (string) Str::uuid(),
        'selected_branch_id' => 1,
        'queue_reference' => $subscription->subscription_code,
        'queue_revision' => 2,
        'selections' => $selections,
        'quote_fingerprint' => $quote->json('quote_fingerprint'),
        'accepted_terms_version' => 'v1',
    ];
    $this->postJson('/api/customer/membership-bookings', $request)
        ->assertCreated()
        ->assertJsonPath('queue.used_meals', 21)
        ->assertJsonPath('queue.available_meals', 25);

    $blocks = MembershipPurchaseBlock::query()->orderBy('queue_position')->get();
    $funding = MembershipBookingFunding::query()->orderBy('purchase_block_id')->get();
    $payments = Payment::query()->where('payment_source_id', $this->source->id)->orderBy('id')->get();
    $invoice = ArInvoice::query()->firstOrFail();
    expect($funding)->toHaveCount(2)
        ->and((int) $funding[0]->purchase_block_id)->toBe((int) $blocks[0]->id)
        ->and($funding[0]->position_ranges)->toBe([[1, 20]])
        ->and((int) $funding[0]->invoice_net_cents)->toBe(90000)
        ->and((int) $funding[1]->purchase_block_id)->toBe((int) $blocks[1]->id)
        ->and($funding[1]->position_ranges)->toBe([[1, 1]])
        ->and((int) $funding[1]->invoice_net_cents)->toBe(4615)
        ->and((int) $invoice->total_cents)->toBe(94615)
        ->and($invoice->paymentAllocations()->count())->toBe(2)
        ->and((int) $payments[0]->unallocatedCents())->toBe(0)
        ->and((int) $payments[1]->unallocatedCents())->toBe(115385)
        ->and((int) $subscription->fresh()->plan_meals_total)->toBe(46)
        ->and((int) $subscription->fresh()->meals_used)->toBe(21)
        ->and(DB::table('orders')->count())->toBe(1);
});

it('does not disclose or spend another customer membership queue', function (): void {
    completeCoveredBookingMembership($this, '20');
    $ownedReference = MealSubscription::query()->firstOrFail()->subscription_code;
    $date = now('Asia/Qatar')->addDays(2)->toDateString();
    $main = createCoveredBookingMenu($this->branch->id, $date);

    $otherCustomer = Customer::factory()->create(['email' => 'another-member@example.test']);
    $otherPortalUser = User::factory()->create([
        'customer_id' => $otherCustomer->id,
        'name' => 'Another Member',
        'portal_name' => 'Another Member',
        'portal_phone' => '+97455000001',
        'portal_phone_e164' => '+97455000001',
        'email' => 'another-member@example.test',
        'status' => 'active',
    ]);
    $otherPortalUser->assignRole('customer');
    Sanctum::actingAs($otherPortalUser, ['customer:*']);

    $this->getJson('/api/customer/memberships?selected_branch_id=1')
        ->assertOk()
        ->assertJsonPath('data.queue_reference', null)
        ->assertJsonPath('data.total_meals', 0)
        ->assertJsonPath('data.available_meals', 0);
    $this->postJson('/api/customer/membership-bookings/quote', [
        'selected_branch_id' => 1,
        'queue_reference' => $ownedReference,
        'selections' => [coveredBookingSelection($date, $main->id, 1)],
    ])->assertStatus(422)
        ->assertJsonPath('code', 'MEMBERSHIP_NOT_AVAILABLE_FOR_BRANCH');

    expect(Order::query()->count())->toBe(0)
        ->and(MembershipBookingOperation::query()->count())->toBe(0)
        ->and(MembershipBookingFunding::query()->count())->toBe(0);
});
