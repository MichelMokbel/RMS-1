<?php

use App\Jobs\InitiateSkipCashCheckout;
use App\Mail\StorefrontMenuOrderConfirmationMail;
use App\Models\AccountingCompany;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\LedgerAccount;
use App\Models\MenuItem;
use App\Models\Payment;
use App\Models\PaymentCheckoutAttempt;
use App\Models\PaymentCheckoutTargetItem;
use App\Models\PaymentProviderTransaction;
use App\Models\PaymentSetting;
use App\Models\PaymentSource;
use App\Models\StorefrontCategory;
use App\Models\StorefrontClosedDate;
use App\Models\StorefrontItemProfile;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\AR\ArInvoiceService;
use App\Services\Payments\FakeSkipCashProvider;
use App\Services\Payments\SkipCashProvider;
use Carbon\Carbon;
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
    $this->clearingAccount = LedgerAccount::factory()->create([
        'company_id' => $this->company->id,
        'is_active' => true,
    ]);
    $this->source = PaymentSource::query()->create([
        'company_id' => $this->company->id,
        'code' => PaymentSource::CODE_SKIPCASH,
        'name' => 'SkipCash',
        'method' => PaymentSource::METHOD_SKIPCASH,
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
});

function createStorefrontTracerCustomer(): array
{
    $customer = Customer::factory()->create(['email' => 'storefront@example.test']);
    $user = User::factory()->create([
        'customer_id' => $customer->id,
        'name' => 'Storefront Customer',
        'portal_name' => 'Storefront Customer',
        'portal_phone' => '+97455683442',
        'portal_phone_e164' => '+97455683442',
        'portal_delivery_address' => 'West Bay',
        'email' => 'storefront@example.test',
        'status' => 'active',
    ]);
    $user->assignRole('customer');
    Sanctum::actingAs($user, ['customer:*']);

    return [$user, $customer];
}

function publishStorefrontTracerItem(object $test): array
{
    StorefrontSetting::query()->create([
        'company_id' => $test->company->id,
        'portal_branch_id' => $test->branch->id,
        'normal_menu_enabled' => true,
        'menu_cutoff_time' => '23:00:00',
        'timezone' => 'Asia/Qatar',
        'revision' => 1,
        'created_by' => $test->systemActor->id,
        'updated_by' => $test->systemActor->id,
    ]);
    $category = StorefrontCategory::query()->create([
        'company_id' => $test->company->id,
        'slug' => 'family-trays',
        'title' => 'Family Trays',
        'is_active' => true,
        'created_by' => $test->systemActor->id,
        'updated_by' => $test->systemActor->id,
    ]);
    $item = MenuItem::factory()->create([
        'code' => 'STORE-MAIN',
        'name' => 'Canonical Chicken Tray',
        'selling_price_per_unit' => '45.250',
        'unit' => MenuItem::UNIT_EACH,
        'is_active' => true,
    ]);
    DB::table('menu_item_branches')->updateOrInsert([
        'menu_item_id' => $item->id,
        'branch_id' => $test->branch->id,
    ], [
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $profile = StorefrontItemProfile::query()->create([
        'company_id' => $test->company->id,
        'branch_id' => $test->branch->id,
        'menu_item_id' => $item->id,
        'category_id' => $category->id,
        'customer_title' => 'Chicken Tray for Four',
        'short_description' => 'Chicken with rice and included delivery.',
        'direct_order_enabled' => true,
        'advance_days' => 2,
        'minimum_quantity' => '1.000',
        'quantity_increment' => '1.000',
        'maximum_quantity' => '5.000',
        'is_chef_pick' => true,
        'created_by' => $test->systemActor->id,
        'updated_by' => $test->systemActor->id,
    ]);

    return [$item, $profile];
}

function storefrontTracerGroup(string $serviceDate, int $itemId): array
{
    return [
        'version' => 'menu-order-v1',
        'service_date' => $serviceDate,
        'items' => [[
            'menu_item_id' => $itemId,
            'quantity' => '2',
        ]],
        'note' => 'Please call on arrival.',
    ];
}

function payStorefrontTracer(object $test, PaymentCheckoutAttempt $attempt, PaymentProviderTransaction $transaction): void
{
    $provider = app(SkipCashProvider::class);
    expect($provider)->toBeInstanceOf(FakeSkipCashProvider::class);
    $provider->markPaid($transaction->provider_payment_id, visaId: 'menu-visa', cardType: 'Credit Card');
    $amount = number_format($attempt->payable_amount_cents / 100, 2, '.', '');
    $payload = [
        'PaymentId' => $transaction->provider_payment_id,
        'Amount' => $amount,
        'StatusId' => '2',
        'TransactionId' => str_replace('-', '', $attempt->reference),
        'Custom1' => '',
        'VisaId' => 'menu-visa',
    ];
    $signature = base64_encode(hash_hmac(
        'sha256',
        'PaymentId='.$payload['PaymentId'].',Amount='.$amount.',StatusId=2,TransactionId='.$payload['TransactionId'].',VisaId=menu-visa',
        'webhook-secret',
        true,
    ));

    $test->postJson('/api/integrations/skipcash/webhook', $payload, ['Authorization' => $signature])
        ->assertOk()
        ->assertJsonPath('accepted', true);
}

it('keeps the advance menu hidden and rejects browsing while the feature is off', function (): void {
    $this->getJson('/api/public/storefront')
        ->assertNotFound()
        ->assertJsonPath('code', 'MENU_ORDER_DISABLED');
    $this->getJson('/api/public/storefront/menu-items')
        ->assertNotFound()
        ->assertJsonPath('code', 'MENU_ORDER_DISABLED');
});

it('creates one paid normal menu order and invoice from the retained checkout snapshot', function (): void {
    Carbon::setTestNow('2026-09-09 08:00:00 UTC');

    try {
        [, $customer] = createStorefrontTracerCustomer();
        [$item, $profile] = publishStorefrontTracerItem($this);
        $serviceDate = '2026-09-12';
        $group = storefrontTracerGroup($serviceDate, $item->id);

        $this->getJson('/api/public/storefront')
            ->assertOk()
            ->assertJsonPath('normal_menu_enabled', true)
            ->assertJsonPath('delivery_included', true)
            ->assertJsonPath('categories.0.title', 'Family Trays')
            ->assertJsonPath('featured.source', 'chef_picks')
            ->assertJsonPath('featured.items.0.menu_item_id', $item->id);
        $this->getJson('/api/public/storefront/menu-items')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Chicken Tray for Four')
            ->assertJsonPath('data.0.unit_price_cents', 4525)
            ->assertJsonPath('data.0.earliest_service_date', '2026-09-11');
        $this->getJson('/api/public/storefront/menu-item/'.$item->id)
            ->assertOk()
            ->assertJsonPath('id', $profile->id)
            ->assertJsonPath('title', 'Chicken Tray for Four');

        $quote = $this->postJson('/api/customer/checkouts/quote', [
            'purpose' => 'menu_order',
            'group' => $group,
        ])->assertOk()
            ->assertJsonPath('payable_amount_cents', 9050)
            ->assertJsonPath('group.service_date', $serviceDate)
            ->assertJsonPath('items.0.canonical_name', 'Canonical Chicken Tray');

        $clientUuid = (string) Str::uuid();
        $createPayload = [
            'client_uuid' => $clientUuid,
            'purpose' => 'menu_order',
            'group' => $group,
            'quote_fingerprint' => $quote->json('quote_fingerprint'),
            'accepted_terms_version' => 'v1',
        ];
        $create = $this->postJson('/api/customer/checkouts', $createPayload)
            ->assertStatus(202)
            ->assertJsonPath('status', 'pending');
        $attempt = PaymentCheckoutAttempt::query()->where('purpose', 'menu_order')->firstOrFail();
        $transaction = PaymentProviderTransaction::query()->firstOrFail();
        expect($attempt->provider_create_outcome)->toBe('created')
            ->and($attempt->targets()->count())->toBe(1)
            ->and(PaymentCheckoutTargetItem::query()->count())->toBe(1)
            ->and(DB::table('orders')->count())->toBe(0)
            ->and(DB::table('ar_invoices')->count())->toBe(0)
            ->and($attempt->notification_snapshots['admin_emails'])->toBe(['ops@example.test']);

        $this->postJson('/api/customer/checkouts', $createPayload)
            ->assertOk()
            ->assertJsonPath('replayed', true)
            ->assertJsonPath('reference', $create->json('reference'));

        $item->update(['name' => 'Changed Canonical Name', 'selling_price_per_unit' => '99.000']);
        $profile->update([
            'customer_title' => 'Changed Customer Title',
            'short_description' => 'Changed description.',
            'direct_order_enabled' => false,
        ]);

        payStorefrontTracer($this, $attempt, $transaction);

        $attempt = $attempt->fresh('targets.invoice.items');
        $target = $attempt->targets->firstOrFail();
        $order = $target->order()->with('items')->firstOrFail();
        $invoice = $target->invoice;
        $payment = Payment::query()->where('payment_source_id', $this->source->id)->firstOrFail();
        expect($attempt->state)->toBe('completed')
            ->and($target->hold_state)->toBe('activated')
            ->and($order->is_daily_dish)->toBeFalse()
            ->and($order->source)->toBe('Website')
            ->and($order->status)->toBe('Draft')
            ->and($order->scheduled_date->toDateString())->toBe($serviceDate)
            ->and($order->notes)->toBe('Please call on arrival.')
            ->and((string) $order->total_amount)->toBe('90.500')
            ->and($order->items->first()->description_snapshot)->toBe('Chicken Tray for Four')
            ->and((string) $order->items->first()->quantity)->toBe('2.000')
            ->and((string) $order->items->first()->unit_price)->toBe('45.250')
            ->and($invoice->status)->toBe('paid')
            ->and((int) $invoice->total_cents)->toBe(9050)
            ->and((int) $invoice->balance_cents)->toBe(0)
            ->and($invoice->items->first()->description)->toBe('Chicken Tray for Four')
            ->and($invoice->items->first()->name_snapshot)->toBe('Canonical Chicken Tray')
            ->and($payment->source)->toBe('ar')
            ->and($payment->method)->toBe('skipcash')
            ->and((int) $payment->amount_cents)->toBe(9050)
            ->and((int) $payment->allocations()->sum('amount_cents'))->toBe(9050)
            ->and($payment->unallocatedCents())->toBe(0)
            ->and(DB::table('bank_transactions')->where('source_type', 'ar_payment')->where('source_id', $payment->id)->count())->toBe(0);

        $customerConfirmation = (new StorefrontMenuOrderConfirmationMail($attempt->notification_snapshots, 'customer'))->render();
        $adminConfirmation = (new StorefrontMenuOrderConfirmationMail($attempt->notification_snapshots, 'admin'))->render();
        expect($customerConfirmation)->toContain('Chicken Tray for Four')
            ->and($customerConfirmation)->toContain($serviceDate)
            ->and($customerConfirmation)->toContain($transaction->provider_payment_id)
            ->and($customerConfirmation)->toContain('does not mark the order as delivered')
            ->and($adminConfirmation)->toContain('A menu order was paid')
            ->and($adminConfirmation)->toContain('West Bay');

        $this->getJson('/api/customer/orders')
            ->assertOk()
            ->assertJsonPath('data.0.id', $order->id);
        $this->getJson('/api/customer/checkouts/'.$attempt->reference)
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('confirmed_targets.0.order_id', $order->id)
            ->assertJsonPath('confirmed_targets.0.invoice_id', $invoice->id);

        expect(Payment::query()->where('customer_id', $customer->id)->count())->toBe(1)
            ->and(DB::table('meal_subscriptions')->where('customer_id', $customer->id)->count())->toBe(0);

        $replacement = app(ArInvoiceService::class)->voidAndDuplicate(
            $invoice,
            $this->systemActor->id,
            'Customer cancelled the advance menu order.',
        );
        $order->refresh()->load('items');
        $payment->refresh();
        expect($invoice->fresh()->status)->toBe('voided')
            ->and($replacement->status)->toBe('draft')
            ->and((int) $replacement->source_order_id)->toBe((int) $order->id)
            ->and($order->status)->toBe('Cancelled')
            ->and($order->items->pluck('status')->unique()->all())->toBe(['Cancelled'])
            ->and($payment->unallocatedCents())->toBe(9050)
            ->and($payment->allocations()->whereNull('voided_at')->count())->toBe(0);

        app(ArInvoiceService::class)->void($invoice->fresh(), $this->systemActor->id, 'Repeated request');
        expect($order->fresh()->status)->toBe('Cancelled')
            ->and($payment->fresh()->unallocatedCents())->toBe(9050);
    } finally {
        Carbon::setTestNow();
    }
});

it('adds the selected checkout category to the same normal menu payment order and invoice', function (): void {
    Carbon::setTestNow('2026-09-09 08:00:00 UTC');

    try {
        createStorefrontTracerCustomer();
        [$main] = publishStorefrontTracerItem($this);
        $category = StorefrontCategory::query()->create([
            'company_id' => $this->company->id,
            'slug' => 'appetizers',
            'title' => 'Appetizers',
            'is_active' => true,
            'created_by' => $this->systemActor->id,
            'updated_by' => $this->systemActor->id,
        ]);
        $addOn = MenuItem::factory()->create([
            'code' => 'STORE-ADDON',
            'name' => 'Canonical Hummus Cup',
            'selling_price_per_unit' => '12.500',
            'unit' => MenuItem::UNIT_EACH,
            'is_active' => true,
        ]);
        DB::table('menu_item_branches')->insertOrIgnore([
            'menu_item_id' => $addOn->id,
            'branch_id' => $this->branch->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $profile = StorefrontItemProfile::query()->create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'menu_item_id' => $addOn->id,
            'category_id' => $category->id,
            'customer_title' => 'Hummus Cup',
            'direct_order_enabled' => true,
            'advance_days' => 1,
            'minimum_quantity' => '1.000',
            'quantity_increment' => '1.000',
            'maximum_quantity' => '5.000',
            'created_by' => $this->systemActor->id,
            'updated_by' => $this->systemActor->id,
        ]);
        StorefrontSetting::query()->update([
            'checkout_upsell_enabled' => true,
            'upsell_category_id' => $category->id,
        ]);
        $serviceDate = '2026-09-12';
        $group = storefrontTracerGroup($serviceDate, $main->id) + [
            'add_ons' => [['menu_item_id' => $addOn->id, 'quantity' => '1']],
        ];

        $this->getJson('/api/public/storefront/upsell-items?service_dates[]='.$serviceDate)
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('category.title', 'Appetizers')
            ->assertJsonPath('dates.0.items.0.id', $profile->id);
        $quote = $this->postJson('/api/customer/checkouts/quote', [
            'purpose' => 'menu_order',
            'group' => $group,
        ])->assertOk()
            ->assertJsonPath('payable_amount_cents', 10300)
            ->assertJsonPath('add_on_amount_cents', 1250)
            ->assertJsonPath('items.1.line_role', 'checkout_add_on');
        $this->postJson('/api/customer/checkouts', [
            'client_uuid' => (string) Str::uuid(),
            'purpose' => 'menu_order',
            'group' => $group,
            'quote_fingerprint' => $quote->json('quote_fingerprint'),
            'accepted_terms_version' => 'v1',
        ])->assertStatus(202);

        $attempt = PaymentCheckoutAttempt::query()->where('purpose', 'menu_order')->sole();
        expect($attempt->pricing_snapshot['add_on_amount_cents'])->toBe(1250)
            ->and(PaymentCheckoutTargetItem::query()->where('line_role', 'checkout_add_on')->count())->toBe(1);
        payStorefrontTracer($this, $attempt, $attempt->providerTransactions()->sole());

        $target = $attempt->fresh('targets.order.items', 'targets.invoice')->targets->sole();
        expect($target->order->items)->toHaveCount(2)
            ->and($target->order->items->where('role', 'checkout_add_on')->count())->toBe(1)
            ->and((int) $target->invoice->total_cents)->toBe(10300)
            ->and((int) Payment::query()->where('payment_source_id', $this->source->id)->sole()->amount_cents)->toBe(10300);
    } finally {
        Carbon::setTestNow();
    }
});

it('releases an unstarted menu checkout without contacting SkipCash when the feature is disabled', function (): void {
    Carbon::setTestNow('2026-09-09 08:00:00 UTC');

    try {
        createStorefrontTracerCustomer();
        [$item] = publishStorefrontTracerItem($this);
        $group = storefrontTracerGroup('2026-09-12', $item->id);
        $quote = $this->postJson('/api/customer/checkouts/quote', [
            'purpose' => 'menu_order',
            'group' => $group,
        ])->assertOk();

        Queue::fake();
        $this->postJson('/api/customer/checkouts', [
            'client_uuid' => (string) Str::uuid(),
            'purpose' => 'menu_order',
            'group' => $group,
            'quote_fingerprint' => $quote->json('quote_fingerprint'),
            'accepted_terms_version' => 'v1',
        ])->assertStatus(202);

        $attempt = PaymentCheckoutAttempt::query()->where('purpose', 'menu_order')->firstOrFail();
        expect($attempt->provider_create_outcome)->toBe('not_sent')
            ->and(PaymentProviderTransaction::query()->count())->toBe(0);

        StorefrontSetting::query()->where('company_id', $this->company->id)->update([
            'normal_menu_enabled' => false,
            'revision' => 2,
        ]);
        app()->call([new InitiateSkipCashCheckout($attempt->id), 'handle']);

        expect($attempt->fresh()->state)->toBe('declined')
            ->and($attempt->fresh()->provider_create_outcome)->toBe('not_sent')
            ->and($attempt->fresh()->last_error_code)->toBe('MENU_ORDER_DISABLED')
            ->and($attempt->targets()->firstOrFail()->hold_state)->toBe('released')
            ->and(PaymentProviderTransaction::query()->count())->toBe(0)
            ->and(DB::table('orders')->count())->toBe(0)
            ->and(DB::table('ar_invoices')->count())->toBe(0)
            ->and(Payment::query()->count())->toBe(0);
    } finally {
        Carbon::setTestNow();
    }
});

it('rejects a selected closed date and rechecks it before provider dispatch', function (): void {
    Carbon::setTestNow('2026-09-09 08:00:00 UTC');

    try {
        createStorefrontTracerCustomer();
        [$item] = publishStorefrontTracerItem($this);
        $group = storefrontTracerGroup('2026-09-13', $item->id);
        StorefrontClosedDate::query()->create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'service_date' => '2026-09-13',
        ]);

        $this->postJson('/api/customer/checkouts/quote', [
            'purpose' => 'menu_order',
            'group' => $group,
        ])->assertStatus(422)
            ->assertJsonPath('code', 'MENU_SERVICE_DATE_CLOSED')
            ->assertJsonPath('quote.selected_service_date_available', false)
            ->assertJsonPath('quote.can_checkout', false);

        StorefrontClosedDate::query()->delete();
        $quote = $this->postJson('/api/customer/checkouts/quote', [
            'purpose' => 'menu_order',
            'group' => $group,
        ])->assertOk();
        Queue::fake();
        $this->postJson('/api/customer/checkouts', [
            'client_uuid' => (string) Str::uuid(),
            'purpose' => 'menu_order',
            'group' => $group,
            'quote_fingerprint' => $quote->json('quote_fingerprint'),
            'accepted_terms_version' => 'v1',
        ])->assertStatus(202);
        $attempt = PaymentCheckoutAttempt::query()->where('purpose', 'menu_order')->firstOrFail();

        StorefrontClosedDate::query()->create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'service_date' => '2026-09-13',
        ]);
        app()->call([new InitiateSkipCashCheckout($attempt->id), 'handle']);

        expect($attempt->fresh()->state)->toBe('declined')
            ->and($attempt->fresh()->last_error_code)->toBe('MENU_CART_CHANGED')
            ->and($attempt->targets()->firstOrFail()->hold_state)->toBe('released')
            ->and(PaymentProviderTransaction::query()->count())->toBe(0);
    } finally {
        Carbon::setTestNow();
    }
});

it('recovers the same open menu checkout after a reload instead of creating another payment attempt', function (): void {
    Carbon::setTestNow('2026-09-09 08:00:00 UTC');

    try {
        createStorefrontTracerCustomer();
        [$item] = publishStorefrontTracerItem($this);
        $group = storefrontTracerGroup('2026-09-12', $item->id);
        $quote = $this->postJson('/api/customer/checkouts/quote', [
            'purpose' => 'menu_order',
            'group' => $group,
        ])->assertOk();
        Queue::fake();
        $first = $this->postJson('/api/customer/checkouts', [
            'client_uuid' => (string) Str::uuid(),
            'purpose' => 'menu_order',
            'group' => $group,
            'quote_fingerprint' => $quote->json('quote_fingerprint'),
            'accepted_terms_version' => 'v1',
        ])->assertStatus(202);

        $reloadedGroup = $group;
        $reloadedGroup['note'] = null;
        $retry = $this->postJson('/api/customer/checkouts', [
            'client_uuid' => (string) Str::uuid(),
            'purpose' => 'menu_order',
            'group' => $reloadedGroup,
            'quote_fingerprint' => str_repeat('0', 64),
            'accepted_terms_version' => 'v1',
        ])->assertStatus(202)
            ->assertJsonPath('replayed', true);

        expect($retry->json('reference'))->toBe($first->json('reference'))
            ->and(PaymentCheckoutAttempt::query()->where('purpose', 'menu_order')->count())->toBe(1)
            ->and(PaymentCheckoutTargetItem::query()->count())->toBe(1);
    } finally {
        Carbon::setTestNow();
    }
});

it('returns the changed quote contract when an item becomes unavailable before checkout starts', function (): void {
    Carbon::setTestNow('2026-09-09 08:00:00 UTC');

    try {
        createStorefrontTracerCustomer();
        [$item, $profile] = publishStorefrontTracerItem($this);
        $group = storefrontTracerGroup('2026-09-12', $item->id);
        $quote = $this->postJson('/api/customer/checkouts/quote', [
            'purpose' => 'menu_order',
            'group' => $group,
        ])->assertOk();
        $profile->update(['direct_order_enabled' => false]);

        $this->postJson('/api/customer/checkouts', [
            'client_uuid' => (string) Str::uuid(),
            'purpose' => 'menu_order',
            'group' => $group,
            'quote_fingerprint' => $quote->json('quote_fingerprint'),
            'accepted_terms_version' => 'v1',
        ])->assertStatus(409)
            ->assertJsonPath('code', 'MENU_CART_CHANGED')
            ->assertJsonPath('quote.removed_item_ids.0', $item->id)
            ->assertJsonPath('quote.can_checkout', false);

        expect(PaymentCheckoutAttempt::query()->count())->toBe(0);
    } finally {
        Carbon::setTestNow();
    }
});

it('rejects a menu quantity that exceeds the retained decimal capacity', function (): void {
    Carbon::setTestNow('2026-09-09 08:00:00 UTC');

    try {
        createStorefrontTracerCustomer();
        [$item] = publishStorefrontTracerItem($this);
        $group = storefrontTracerGroup('2026-09-12', $item->id);
        $group['items'][0]['quantity'] = '1000000000';

        $this->postJson('/api/customer/checkouts/quote', [
            'purpose' => 'menu_order',
            'group' => $group,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('group.items.0');

        expect(PaymentCheckoutAttempt::query()->count())->toBe(0);
    } finally {
        Carbon::setTestNow();
    }
});
