<?php

use App\Models\AccountingCompany;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DailyDishMenu;
use App\Models\DailyDishMenuItem;
use App\Models\LedgerAccount;
use App\Models\MealSubscription;
use App\Models\MenuItem;
use App\Models\Payment;
use App\Models\PaymentCheckoutAttempt;
use App\Models\PaymentCheckoutTarget;
use App\Models\PaymentProviderEvent;
use App\Models\PaymentProviderTransaction;
use App\Models\PaymentSetting;
use App\Models\PaymentSource;
use App\Models\User;
use App\Services\Accounting\AccountingPeriodGateService;
use App\Services\AR\ArInvoiceService;
use App\Services\Customers\CustomerMergeService;
use App\Services\Payments\FakeSkipCashProvider;
use App\Services\Payments\SkipCashProvider;
use App\Services\Payments\SkipCashRecoveryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
        'order_support_phone' => '+974 5555 0000',
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

function createSkipCashTracerCustomer(): array
{
    $customer = Customer::factory()->create(['email' => 'tracer@example.com']);
    $user = User::factory()->create([
        'customer_id' => $customer->id,
        'name' => 'Tracer Customer',
        'portal_name' => 'Tracer Customer',
        'portal_phone' => '+97455555555',
        'portal_phone_e164' => '+97455555555',
        'portal_delivery_address' => 'West Bay',
        'email' => 'tracer@example.com',
        'status' => 'active',
    ]);
    $user->assignRole('customer');
    Sanctum::actingAs($user, ['customer:*']);

    return [$user, $customer];
}

function createSkipCashTracerMenu(int $branchId, int $daysAhead = 2): array
{
    $main = MenuItem::factory()->create(['code' => 'TRACE-MAIN', 'name' => 'Tracer Main']);
    $salad = MenuItem::factory()->create(['code' => 'TRACE-SALAD', 'name' => 'Tracer Salad']);
    $dessert = MenuItem::factory()->create(['code' => 'TRACE-DESSERT', 'name' => 'Tracer Dessert']);
    $date = now('Asia/Qatar')->addDays($daysAhead)->toDateString();
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

    return [$date, $main];
}

function skipCashTracerCart(string $date, int $mainId): array
{
    return [
        'items' => [[
            'key' => $date,
            'mains' => [[
                'menu_item_id' => $mainId,
                'portion' => 'plate',
                'qty' => 1,
            ]],
            'salad_qty' => 1,
            'dessert_qty' => 1,
        ]],
    ];
}

it('creates a paid ordinary SkipCash order only after verified provider evidence', function (): void {
    [, $customer] = createSkipCashTracerCustomer();
    [$date, $main] = createSkipCashTracerMenu($this->branch->id);
    $cart = skipCashTracerCart($date, $main->id);
    Payment::factory()->create([
        'customer_id' => $customer->id,
        'company_id' => $this->company->id,
        'branch_id' => $this->branch->id,
        'amount_cents' => 30000,
        'source' => 'ar',
        'method' => 'cash',
    ]);

    $quote = $this->postJson('/api/customer/checkouts/quote', [
        'purpose' => 'ordinary_order',
        'cart' => $cart,
    ])->assertOk()
        ->assertJsonPath('payable_amount_cents', 6500);

    expect(PaymentCheckoutAttempt::query()->count())->toBe(0)
        ->and(DB::table('orders')->count())->toBe(0)
        ->and(DB::table('ar_invoices')->count())->toBe(0);

    $create = $this->postJson('/api/customer/checkouts', [
        'client_uuid' => (string) Str::uuid(),
        'purpose' => 'ordinary_order',
        'cart' => $cart,
        'quote_fingerprint' => $quote->json('quote_fingerprint'),
        'accepted_terms_version' => 'v1',
    ])->assertStatus(202)
        ->assertJsonPath('status', 'pending');

    $attempt = PaymentCheckoutAttempt::query()->firstOrFail();
    $providerTransaction = PaymentProviderTransaction::query()->firstOrFail();
    expect(PaymentCheckoutTarget::query()->count())->toBe(1)
        ->and(DB::table('orders')->count())->toBe(0)
        ->and(DB::table('ar_invoices')->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(1)
        ->and($create->json('reference'))->toBe($attempt->reference);

    $provider = app(SkipCashProvider::class);
    expect($provider)->toBeInstanceOf(FakeSkipCashProvider::class);
    $provider->markPaid($providerTransaction->provider_payment_id);
    $payload = [
        'PaymentId' => $providerTransaction->provider_payment_id,
        'Amount' => '65.00',
        'StatusId' => '2',
        'TransactionId' => str_replace('-', '', $attempt->reference),
        'Custom1' => '',
        'VisaId' => 'fake-visa',
    ];
    $signature = base64_encode(hash_hmac(
        'sha256',
        'PaymentId='.$payload['PaymentId'].',Amount=65.00,StatusId=2,TransactionId='.$payload['TransactionId'].',VisaId=fake-visa',
        'webhook-secret',
        true,
    ));

    $this->postJson('/api/integrations/skipcash/webhook', $payload, ['Authorization' => $signature])
        ->assertOk()
        ->assertJsonPath('accepted', true);

    $attempt = $attempt->fresh('targets.invoice');
    $payment = Payment::query()->where('payment_source_id', $this->source->id)->firstOrFail();
    $invoice = $attempt->targets->firstOrFail()->invoice;
    expect($attempt->state)->toBe('completed')
        ->and($attempt->targets->first()->hold_state)->toBe('activated')
        ->and($invoice->status)->toBe('paid')
        ->and((int) $invoice->total_cents)->toBe(6500)
        ->and((int) $invoice->balance_cents)->toBe(0)
        ->and($payment->source)->toBe('ar')
        ->and($payment->method)->toBe('skipcash')
        ->and((int) $payment->amount_cents)->toBe(6500)
        ->and((int) $payment->payment_source_id)->toBe((int) $this->source->id)
        ->and((int) $payment->allocations()->sum('amount_cents'))->toBe(6500)
        ->and(Payment::query()->where('customer_id', $customer->id)->where('amount_cents', 30000)->firstOrFail()->unallocatedCents())->toBe(30000)
        ->and(DB::table('bank_transactions')->where('source_type', 'ar_payment')->where('source_id', $payment->id)->count())->toBe(0)
        ->and($attempt->notification_dispatch['customer_confirmation']['state'])->toBe('sent')
        ->and($attempt->notification_dispatch['admin_confirmation']['state'])->toBe('sent');

    $attempt->update([
        'notification_dispatch' => [
            'customer_confirmation' => [
                'state' => 'retryable',
                'attempts' => 1,
                'next_retry_at' => now('UTC')->subMinute()->toIso8601String(),
            ],
            'admin_confirmation' => ['state' => 'sent'],
        ],
    ]);
    $recovery = app(SkipCashRecoveryService::class)->recover();

    expect($recovery['confirmations_retried'])->toBe(1)
        ->and($attempt->fresh()->notification_dispatch['customer_confirmation']['state'])->toBe('sent')
        ->and($attempt->fresh()->notification_dispatch['admin_confirmation']['state'])->toBe('sent')
        ->and(Payment::query()->where('payment_source_id', $this->source->id)->count())->toBe(1);
});

it('creates and fully allocates one paid invoice for every future service date', function (): void {
    createSkipCashTracerCustomer();
    [$firstDate, $main] = createSkipCashTracerMenu($this->branch->id);
    $secondDate = now('Asia/Qatar')->addDays(3)->toDateString();
    $salad = MenuItem::factory()->create(['code' => 'TRACE-SALAD-SECOND', 'name' => 'Tracer Second Salad']);
    $dessert = MenuItem::factory()->create(['code' => 'TRACE-DESSERT-SECOND', 'name' => 'Tracer Second Dessert']);
    $secondMenu = DailyDishMenu::query()->create([
        'branch_id' => $this->branch->id,
        'service_date' => $secondDate,
        'status' => 'published',
    ]);
    foreach ([[$main, 'main'], [$salad, 'salad'], [$dessert, 'dessert']] as $index => [$item, $role]) {
        DailyDishMenuItem::query()->create([
            'daily_dish_menu_id' => $secondMenu->id,
            'menu_item_id' => $item->id,
            'role' => $role,
            'sort_order' => $index + 1,
            'is_required' => true,
        ]);
    }
    $cart = [
        'items' => array_merge(
            skipCashTracerCart($firstDate, $main->id)['items'],
            skipCashTracerCart($secondDate, $main->id)['items'],
        ),
    ];

    $quote = $this->postJson('/api/customer/checkouts/quote', [
        'purpose' => 'ordinary_order',
        'cart' => $cart,
    ])->assertOk()
        ->assertJsonPath('payable_amount_cents', 13000)
        ->assertJsonCount(2, 'day_totals');

    $this->postJson('/api/customer/checkouts', [
        'client_uuid' => (string) Str::uuid(),
        'purpose' => 'ordinary_order',
        'cart' => $cart,
        'quote_fingerprint' => $quote->json('quote_fingerprint'),
        'accepted_terms_version' => 'v1',
    ])->assertStatus(202);

    $attempt = PaymentCheckoutAttempt::query()->firstOrFail();
    $providerTransaction = PaymentProviderTransaction::query()->firstOrFail();
    app(SkipCashProvider::class)->markPaid($providerTransaction->provider_payment_id);
    $payload = [
        'PaymentId' => $providerTransaction->provider_payment_id,
        'Amount' => '130.00',
        'StatusId' => '2',
        'TransactionId' => str_replace('-', '', $attempt->reference),
        'Custom1' => '',
        'VisaId' => 'fake-visa-multi',
    ];
    $signature = base64_encode(hash_hmac(
        'sha256',
        'PaymentId='.$payload['PaymentId'].',Amount=130.00,StatusId=2,TransactionId='.$payload['TransactionId'].',VisaId=fake-visa-multi',
        'webhook-secret',
        true,
    ));

    $this->postJson('/api/integrations/skipcash/webhook', $payload, ['Authorization' => $signature])
        ->assertOk()
        ->assertJsonPath('accepted', true);

    $attempt->refresh();
    $payment = Payment::query()->where('payment_source_id', $this->source->id)->firstOrFail();
    expect($attempt->state)->toBe('completed')
        ->and($attempt->targets()->count())->toBe(2)
        ->and($attempt->targets()->where('hold_state', 'activated')->count())->toBe(2)
        ->and($attempt->targets()->whereNotNull('order_id')->count())->toBe(2)
        ->and($attempt->targets()->whereNotNull('invoice_id')->count())->toBe(2)
        ->and((int) $payment->amount_cents)->toBe(13000)
        ->and((int) $payment->allocations()->sum('amount_cents'))->toBe(13000)
        ->and($payment->allocations()->count())->toBe(2)
        ->and($attempt->targets()->with('invoice')->get()->every(fn ($target): bool => $target->invoice->status === 'paid' && (int) $target->invoice->balance_cents === 0))->toBeTrue();
});

it('completes the original checkout once when verified payment arrives after expiry', function (): void {
    createSkipCashTracerCustomer();
    [$date, $main] = createSkipCashTracerMenu($this->branch->id);
    $cart = skipCashTracerCart($date, $main->id);
    $quote = $this->postJson('/api/customer/checkouts/quote', [
        'purpose' => 'ordinary_order',
        'cart' => $cart,
    ])->assertOk();
    $this->postJson('/api/customer/checkouts', [
        'client_uuid' => (string) Str::uuid(),
        'purpose' => 'ordinary_order',
        'cart' => $cart,
        'quote_fingerprint' => $quote->json('quote_fingerprint'),
        'accepted_terms_version' => 'v1',
    ])->assertStatus(202);

    $attempt = PaymentCheckoutAttempt::query()->firstOrFail();
    $transaction = PaymentProviderTransaction::query()->firstOrFail();
    $attempt->update(['expires_at' => now('UTC')->subMinute()]);
    app(SkipCashRecoveryService::class)->recover();
    expect($attempt->fresh()->state)->toBe('expired')
        ->and($attempt->targets()->firstOrFail()->hold_state)->toBe('released');

    $provider = app(SkipCashProvider::class);
    expect($provider)->toBeInstanceOf(FakeSkipCashProvider::class);
    $provider->markPaid($transaction->provider_payment_id);
    $payload = [
        'PaymentId' => $transaction->provider_payment_id,
        'Amount' => '65.00',
        'StatusId' => '2',
        'TransactionId' => str_replace('-', '', $attempt->reference),
        'Custom1' => '',
        'VisaId' => 'fake-visa-after-expiry',
    ];
    $signature = base64_encode(hash_hmac(
        'sha256',
        'PaymentId='.$payload['PaymentId'].',Amount=65.00,StatusId=2,TransactionId='.$payload['TransactionId'].',VisaId=fake-visa-after-expiry',
        'webhook-secret',
        true,
    ));

    $this->postJson('/api/integrations/skipcash/webhook', $payload, ['Authorization' => $signature])
        ->assertOk();

    expect($attempt->fresh()->state)->toBe('completed')
        ->and($attempt->targets()->firstOrFail()->hold_state)->toBe('activated')
        ->and($attempt->targets()->firstOrFail()->released_at)->not->toBeNull()
        ->and(Payment::query()->where('payment_source_id', $this->source->id)->count())->toBe(1);
});

it('completes a future Qatar booking that crosses midnight from its saved checkout target', function (): void {
    Carbon::setTestNow('2026-09-02 20:58:00 UTC');

    try {
        createSkipCashTracerCustomer();
        [$date, $main] = createSkipCashTracerMenu($this->branch->id, 1);
        $cart = skipCashTracerCart($date, $main->id);
        $quote = $this->postJson('/api/customer/checkouts/quote', [
            'purpose' => 'ordinary_order',
            'cart' => $cart,
        ])->assertOk();
        $this->postJson('/api/customer/checkouts', [
            'client_uuid' => (string) Str::uuid(),
            'purpose' => 'ordinary_order',
            'cart' => $cart,
            'quote_fingerprint' => $quote->json('quote_fingerprint'),
            'accepted_terms_version' => 'v1',
        ])->assertStatus(202);

        $attempt = PaymentCheckoutAttempt::query()->firstOrFail();
        $transaction = PaymentProviderTransaction::query()->firstOrFail();
        expect($date)->toBe('2026-09-03')
            ->and($attempt->expires_at->utc()->toIso8601String())->toBe('2026-09-02T21:13:00+00:00');

        Carbon::setTestNow('2026-09-02 21:02:00 UTC');
        $provider = app(SkipCashProvider::class);
        expect($provider)->toBeInstanceOf(FakeSkipCashProvider::class);
        $provider->markPaid($transaction->provider_payment_id);
        $payload = [
            'PaymentId' => $transaction->provider_payment_id,
            'Amount' => '65.00',
            'StatusId' => '2',
            'TransactionId' => str_replace('-', '', $attempt->reference),
            'Custom1' => '',
            'VisaId' => 'fake-visa-midnight',
        ];
        $signature = base64_encode(hash_hmac(
            'sha256',
            'PaymentId='.$payload['PaymentId'].',Amount=65.00,StatusId=2,TransactionId='.$payload['TransactionId'].',VisaId=fake-visa-midnight',
            'webhook-secret',
            true,
        ));

        $this->postJson('/api/integrations/skipcash/webhook', $payload, ['Authorization' => $signature])
            ->assertOk();

        expect($attempt->fresh()->state)->toBe('completed')
            ->and($attempt->targets()->firstOrFail()->hold_state)->toBe('activated')
            ->and(DB::table('orders')->value('scheduled_date'))->toBe($date)
            ->and(Payment::query()->where('payment_source_id', $this->source->id)->count())->toBe(1);
    } finally {
        Carbon::setTestNow();
    }
});

it('does not spend an existing membership balance for an ordinary SkipCash order', function (): void {
    [, $customer] = createSkipCashTracerCustomer();
    $subscription = MealSubscription::factory()->create([
        'customer_id' => $customer->id,
        'branch_id' => $this->branch->id,
        'status' => 'active',
        'plan_meals_total' => 20,
        'meals_used' => 7,
    ]);
    [$date, $main] = createSkipCashTracerMenu($this->branch->id);
    $cart = skipCashTracerCart($date, $main->id);
    $quote = $this->postJson('/api/customer/checkouts/quote', [
        'purpose' => 'ordinary_order',
        'cart' => $cart,
    ])->assertOk();
    $this->postJson('/api/customer/checkouts', [
        'client_uuid' => (string) Str::uuid(),
        'purpose' => 'ordinary_order',
        'cart' => $cart,
        'quote_fingerprint' => $quote->json('quote_fingerprint'),
        'accepted_terms_version' => 'v1',
    ])->assertStatus(202);

    $attempt = PaymentCheckoutAttempt::query()->firstOrFail();
    $transaction = PaymentProviderTransaction::query()->firstOrFail();
    $provider = app(SkipCashProvider::class);
    expect($provider)->toBeInstanceOf(FakeSkipCashProvider::class);
    $provider->markPaid($transaction->provider_payment_id);
    $payload = [
        'PaymentId' => $transaction->provider_payment_id,
        'Amount' => '65.00',
        'StatusId' => '2',
        'TransactionId' => str_replace('-', '', $attempt->reference),
        'Custom1' => '',
        'VisaId' => 'fake-visa-membership-isolation',
    ];
    $signature = base64_encode(hash_hmac(
        'sha256',
        'PaymentId='.$payload['PaymentId'].',Amount=65.00,StatusId=2,TransactionId='.$payload['TransactionId'].',VisaId=fake-visa-membership-isolation',
        'webhook-secret',
        true,
    ));

    $this->postJson('/api/integrations/skipcash/webhook', $payload, ['Authorization' => $signature])
        ->assertOk();

    expect($subscription->fresh()->meals_used)->toBe(7)
        ->and(DB::table('meal_subscription_orders')->count())->toBe(0)
        ->and(Payment::query()->where('payment_source_id', $this->source->id)->count())->toBe(1);
});

it('uses retained prices and terms when current checkout configuration changes after start', function (): void {
    $originalBundlePrice = config('pricing.meal_plan.base_prices.main_plus_both');
    $originalTerms = config('payment_terms.published');

    try {
        createSkipCashTracerCustomer();
        [$date, $main] = createSkipCashTracerMenu($this->branch->id);
        $cart = skipCashTracerCart($date, $main->id);
        $quote = $this->postJson('/api/customer/checkouts/quote', [
            'purpose' => 'ordinary_order',
            'cart' => $cart,
        ])->assertOk()->assertJsonPath('payable_amount_cents', 6500);
        $this->postJson('/api/customer/checkouts', [
            'client_uuid' => (string) Str::uuid(),
            'purpose' => 'ordinary_order',
            'cart' => $cart,
            'quote_fingerprint' => $quote->json('quote_fingerprint'),
            'accepted_terms_version' => 'v1',
        ])->assertStatus(202);

        $attempt = PaymentCheckoutAttempt::query()->firstOrFail();
        $transaction = PaymentProviderTransaction::query()->firstOrFail();
        Config::set('pricing.meal_plan.base_prices.main_plus_both', 99.0);
        Config::set('payment_terms.published', []);

        $provider = app(SkipCashProvider::class);
        expect($provider)->toBeInstanceOf(FakeSkipCashProvider::class);
        $provider->markPaid($transaction->provider_payment_id);
        $payload = [
            'PaymentId' => $transaction->provider_payment_id,
            'Amount' => '65.00',
            'StatusId' => '2',
            'TransactionId' => str_replace('-', '', $attempt->reference),
            'Custom1' => '',
            'VisaId' => 'fake-visa-retained-checkout',
        ];
        $signature = base64_encode(hash_hmac(
            'sha256',
            'PaymentId='.$payload['PaymentId'].',Amount=65.00,StatusId=2,TransactionId='.$payload['TransactionId'].',VisaId=fake-visa-retained-checkout',
            'webhook-secret',
            true,
        ));

        $this->postJson('/api/integrations/skipcash/webhook', $payload, ['Authorization' => $signature])
            ->assertOk();

        $target = $attempt->fresh('targets.invoice')->targets->firstOrFail();
        expect($attempt->fresh()->state)->toBe('completed')
            ->and($attempt->fresh()->terms_snapshot['version'])->toBe('v1')
            ->and($attempt->fresh()->pricing_snapshot['day_totals'][0]['total_amount_cents'])->toBe(6500)
            ->and((int) $target->expected_amount_cents)->toBe(6500)
            ->and((int) $target->invoice->total_cents)->toBe(6500);
    } finally {
        Config::set('pricing.meal_plan.base_prices.main_plus_both', $originalBundlePrice);
        Config::set('payment_terms.published', $originalTerms);
    }
});

it('does not recreate a corrected invoice when the original provider callback is replayed', function (): void {
    createSkipCashTracerCustomer();
    [$date, $main] = createSkipCashTracerMenu($this->branch->id);
    $cart = skipCashTracerCart($date, $main->id);
    $quote = $this->postJson('/api/customer/checkouts/quote', [
        'purpose' => 'ordinary_order',
        'cart' => $cart,
    ])->assertOk();
    $this->postJson('/api/customer/checkouts', [
        'client_uuid' => (string) Str::uuid(),
        'purpose' => 'ordinary_order',
        'cart' => $cart,
        'quote_fingerprint' => $quote->json('quote_fingerprint'),
        'accepted_terms_version' => 'v1',
    ])->assertStatus(202);

    $attempt = PaymentCheckoutAttempt::query()->firstOrFail();
    $transaction = PaymentProviderTransaction::query()->firstOrFail();
    $provider = app(SkipCashProvider::class);
    expect($provider)->toBeInstanceOf(FakeSkipCashProvider::class);
    $provider->markPaid($transaction->provider_payment_id);
    $payload = [
        'PaymentId' => $transaction->provider_payment_id,
        'Amount' => '65.00',
        'StatusId' => '2',
        'TransactionId' => str_replace('-', '', $attempt->reference),
        'Custom1' => '',
        'VisaId' => 'fake-visa-correction-replay',
    ];
    $signature = base64_encode(hash_hmac(
        'sha256',
        'PaymentId='.$payload['PaymentId'].',Amount=65.00,StatusId=2,TransactionId='.$payload['TransactionId'].',VisaId=fake-visa-correction-replay',
        'webhook-secret',
        true,
    ));

    $this->postJson('/api/integrations/skipcash/webhook', $payload, ['Authorization' => $signature])
        ->assertOk();
    $target = $attempt->fresh('targets.invoice')->targets->firstOrFail();
    app(ArInvoiceService::class)->void($target->invoice, $this->systemActor->id, 'Customer cancelled');

    $this->postJson('/api/integrations/skipcash/webhook', $payload, ['Authorization' => $signature])
        ->assertOk()
        ->assertJsonPath('duplicate', true);

    expect($attempt->fresh()->state)->toBe('completed')
        ->and($target->fresh('invoice')->invoice->status)->toBe('voided')
        ->and(DB::table('orders')->count())->toBe(1)
        ->and(DB::table('ar_invoices')->count())->toBe(1)
        ->and(Payment::query()->where('payment_source_id', $this->source->id)->count())->toBe(1)
        ->and(Payment::query()->where('payment_source_id', $this->source->id)->firstOrFail()->unallocatedCents())->toBe(6500);
});

it('keeps verified payment in processing without partial records while finance is locked, then recovers it', function (): void {
    createSkipCashTracerCustomer();
    [$date, $main] = createSkipCashTracerMenu($this->branch->id);
    $cart = skipCashTracerCart($date, $main->id);
    $quote = $this->postJson('/api/customer/checkouts/quote', [
        'purpose' => 'ordinary_order',
        'cart' => $cart,
    ])->assertOk();
    $this->postJson('/api/customer/checkouts', [
        'client_uuid' => (string) Str::uuid(),
        'purpose' => 'ordinary_order',
        'cart' => $cart,
        'quote_fingerprint' => $quote->json('quote_fingerprint'),
        'accepted_terms_version' => 'v1',
    ])->assertStatus(202);

    $attempt = PaymentCheckoutAttempt::query()->firstOrFail();
    $transaction = PaymentProviderTransaction::query()->firstOrFail();
    $provider = app(SkipCashProvider::class);
    expect($provider)->toBeInstanceOf(FakeSkipCashProvider::class);
    $provider->markPaid($transaction->provider_payment_id);
    $payload = [
        'PaymentId' => $transaction->provider_payment_id,
        'Amount' => '65.00',
        'StatusId' => '2',
        'TransactionId' => str_replace('-', '', $attempt->reference),
        'Custom1' => '',
        'VisaId' => 'fake-visa-finance-lock',
    ];
    $signature = base64_encode(hash_hmac(
        'sha256',
        'PaymentId='.$payload['PaymentId'].',Amount=65.00,StatusId=2,TransactionId='.$payload['TransactionId'].',VisaId=fake-visa-finance-lock',
        'webhook-secret',
        true,
    ));

    $gate = $this->mock(AccountingPeriodGateService::class);
    $gate->shouldReceive('assertDateOpen')
        ->andThrow(ValidationException::withMessages(['issue_date' => 'Period is closed.']));

    try {
        $this->postJson('/api/integrations/skipcash/webhook', $payload, ['Authorization' => $signature])
            ->assertStatus(503)
            ->assertJsonPath('code', 'PAYMENT_PROCESSING_FAILED');

        expect($attempt->fresh()->state)->toBe('paid_processing')
            ->and(PaymentProviderTransaction::query()->firstOrFail()->verified_paid_at)->not->toBeNull()
            ->and(DB::table('orders')->count())->toBe(0)
            ->and(DB::table('ar_invoices')->count())->toBe(0)
            ->and(Payment::query()->count())->toBe(0);
    } finally {
        app()->forgetInstance(AccountingPeriodGateService::class);
    }

    PaymentProviderEvent::query()->firstOrFail()->update(['next_retry_at' => now('UTC')->subMinute()]);
    $recovery = app(SkipCashRecoveryService::class)->recover();

    expect($recovery['events_retried'])->toBe(1)
        ->and($attempt->fresh()->state)->toBe('completed')
        ->and(DB::table('orders')->count())->toBe(1)
        ->and(DB::table('ar_invoices')->count())->toBe(1)
        ->and(Payment::query()->where('payment_source_id', $this->source->id)->count())->toBe(1);
});

it('keeps published main dish IDs available to the customer website', function (): void {
    [, $main] = createSkipCashTracerMenu($this->branch->id);

    $this->getJson('/api/public/daily-dish/menus?branch_id='.$this->branch->id)
        ->assertOk()
        ->assertJsonPath('data.0.mains_items.0.id', $main->id)
        ->assertJsonPath('data.0.mains_items.0.name', $main->name);
});

it('uses the configured ordinary bundle, side, and portion prices on the server', function (): void {
    createSkipCashTracerCustomer();
    [$date, $main] = createSkipCashTracerMenu($this->branch->id);

    foreach ([
        ['portion' => 'plate', 'salad_qty' => 0, 'dessert_qty' => 0, 'amount' => 5000],
        ['portion' => 'plate', 'salad_qty' => 1, 'dessert_qty' => 0, 'amount' => 5500],
        ['portion' => 'plate', 'salad_qty' => 1, 'dessert_qty' => 1, 'amount' => 6500],
        ['portion' => 'plate', 'salad_qty' => 2, 'dessert_qty' => 1, 'amount' => 8000],
        ['portion' => 'half', 'salad_qty' => 1, 'dessert_qty' => 1, 'amount' => 16000],
        ['portion' => 'full', 'salad_qty' => 1, 'dessert_qty' => 1, 'amount' => 27000],
    ] as $case) {
        $cart = [
            'items' => [[
                'key' => $date,
                'mains' => [[
                    'menu_item_id' => $main->id,
                    'portion' => $case['portion'],
                    'qty' => 1,
                ]],
                'salad_qty' => $case['salad_qty'],
                'dessert_qty' => $case['dessert_qty'],
            ]],
        ];

        $this->postJson('/api/customer/checkouts/quote', [
            'purpose' => 'ordinary_order',
            'cart' => $cart,
        ])->assertOk()->assertJsonPath('payable_amount_cents', $case['amount']);
    }
});

it('requires a future only reviewed cart before creating a payment attempt', function (): void {
    createSkipCashTracerCustomer();
    $todayCart = skipCashTracerCart(now('Asia/Qatar')->toDateString(), 999999);

    $quote = $this->postJson('/api/customer/checkouts/quote', [
        'purpose' => 'ordinary_order',
        'cart' => $todayCart,
    ])->assertOk()
        ->assertJsonPath('can_checkout', false)
        ->assertJsonCount(1, 'excluded_today');

    expect(PaymentCheckoutAttempt::query()->count())->toBe(0);

    [$futureDate, $main] = createSkipCashTracerMenu($this->branch->id);
    $mixedCart = [
        'items' => array_merge($todayCart['items'], skipCashTracerCart($futureDate, $main->id)['items']),
    ];
    $mixedQuote = $this->postJson('/api/customer/checkouts/quote', [
        'purpose' => 'ordinary_order',
        'cart' => $mixedCart,
    ])->assertOk()
        ->assertJsonPath('can_checkout', true);

    $this->postJson('/api/customer/checkouts', [
        'client_uuid' => (string) Str::uuid(),
        'purpose' => 'ordinary_order',
        'cart' => $mixedCart,
        'quote_fingerprint' => $mixedQuote->json('quote_fingerprint'),
        'accepted_terms_version' => 'v1',
    ])->assertStatus(409)
        ->assertJsonPath('code', 'TODAY_REVIEW_REQUIRED');

    expect(PaymentCheckoutAttempt::query()->count())->toBe(0);
});

it('rejects an incomplete provider profile before creating an attempt', function (): void {
    [$user] = createSkipCashTracerCustomer();
    $user->update(['portal_name' => '', 'name' => '']);
    [$date, $main] = createSkipCashTracerMenu($this->branch->id);
    $cart = skipCashTracerCart($date, $main->id);
    $quote = $this->postJson('/api/customer/checkouts/quote', [
        'purpose' => 'ordinary_order',
        'cart' => $cart,
    ])->assertOk();

    $this->postJson('/api/customer/checkouts', [
        'client_uuid' => (string) Str::uuid(),
        'purpose' => 'ordinary_order',
        'cart' => $cart,
        'quote_fingerprint' => $quote->json('quote_fingerprint'),
        'accepted_terms_version' => 'v1',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['profile.name']);

    expect(PaymentCheckoutAttempt::query()->count())->toBe(0)
        ->and(PaymentProviderTransaction::query()->count())->toBe(0);
});

it('requires recovery before creating an equivalent checkout after mutable menu and quote changes', function (): void {
    createSkipCashTracerCustomer();
    [$date, $main] = createSkipCashTracerMenu($this->branch->id);
    $cart = skipCashTracerCart($date, $main->id);
    $quote = $this->postJson('/api/customer/checkouts/quote', [
        'purpose' => 'ordinary_order',
        'cart' => $cart,
    ])->assertOk();
    $this->postJson('/api/customer/checkouts', [
        'client_uuid' => (string) Str::uuid(),
        'purpose' => 'ordinary_order',
        'cart' => $cart,
        'quote_fingerprint' => $quote->json('quote_fingerprint'),
        'accepted_terms_version' => 'v1',
    ])->assertStatus(202);

    $attempt = PaymentCheckoutAttempt::query()->firstOrFail();
    DailyDishMenu::query()->where('branch_id', $this->branch->id)->where('service_date', $date)->update(['status' => 'draft']);

    $response = $this->postJson('/api/customer/checkouts', [
        'client_uuid' => (string) Str::uuid(),
        'purpose' => 'ordinary_order',
        'cart' => $cart,
        'quote_fingerprint' => str_repeat('a', 64),
        'accepted_terms_version' => 'not-current',
    ])->assertStatus(409)
        ->assertJsonPath('code', 'EXISTING_CHECKOUT')
        ->assertJsonPath('recovery_reference', $attempt->reference);

    expect($response->json('reference'))->toBeNull()
        ->and(PaymentCheckoutAttempt::query()->count())->toBe(1)
        ->and(PaymentProviderTransaction::query()->count())->toBe(1);
});

it('creates an equivalent checkout only after an owned explicit separate purchase acknowledgement', function (): void {
    createSkipCashTracerCustomer();
    [$date, $main] = createSkipCashTracerMenu($this->branch->id);
    $cart = skipCashTracerCart($date, $main->id);
    $quote = $this->postJson('/api/customer/checkouts/quote', [
        'purpose' => 'ordinary_order',
        'cart' => $cart,
    ])->assertOk();
    $this->postJson('/api/customer/checkouts', [
        'client_uuid' => (string) Str::uuid(),
        'purpose' => 'ordinary_order',
        'cart' => $cart,
        'quote_fingerprint' => $quote->json('quote_fingerprint'),
        'accepted_terms_version' => 'v1',
    ])->assertStatus(202);
    $original = PaymentCheckoutAttempt::query()->firstOrFail();

    $this->postJson('/api/customer/checkouts', [
        'client_uuid' => (string) Str::uuid(),
        'purpose' => 'ordinary_order',
        'cart' => $cart,
        'quote_fingerprint' => $quote->json('quote_fingerprint'),
        'accepted_terms_version' => 'v1',
    ])->assertStatus(409)
        ->assertJsonPath('code', 'EXISTING_CHECKOUT')
        ->assertJsonPath('recovery_reference', $original->reference);

    $this->postJson('/api/customer/checkouts', [
        'client_uuid' => (string) Str::uuid(),
        'purpose' => 'ordinary_order',
        'cart' => $cart,
        'quote_fingerprint' => $quote->json('quote_fingerprint'),
        'accepted_terms_version' => 'v1',
        'separate_purchase_from' => $original->reference,
    ])->assertStatus(202);

    $separate = PaymentCheckoutAttempt::query()->latest('id')->firstOrFail();
    expect(PaymentCheckoutAttempt::query()->count())->toBe(2)
        ->and($separate->reference)->not->toBe($original->reference)
        ->and($separate->request_snapshot['separate_purchase_from'])->toBe($original->reference)
        ->and(PaymentProviderTransaction::query()->count())->toBe(2);
});

it('keeps checkout recovery private to the owning customer and hides hosted URLs from the list', function (): void {
    createSkipCashTracerCustomer();
    [$date, $main] = createSkipCashTracerMenu($this->branch->id);
    $cart = skipCashTracerCart($date, $main->id);
    $quote = $this->postJson('/api/customer/checkouts/quote', [
        'purpose' => 'ordinary_order',
        'cart' => $cart,
    ])->assertOk();
    $this->postJson('/api/customer/checkouts', [
        'client_uuid' => (string) Str::uuid(),
        'purpose' => 'ordinary_order',
        'cart' => $cart,
        'quote_fingerprint' => $quote->json('quote_fingerprint'),
        'accepted_terms_version' => 'v1',
    ])->assertStatus(202);
    $attempt = PaymentCheckoutAttempt::query()->firstOrFail();

    $ownedList = $this->getJson('/api/customer/checkouts')->assertOk();
    expect(array_key_exists('pay_url', $ownedList->json('data.0')))->toBeFalse();

    $otherCustomer = Customer::factory()->create();
    $otherUser = User::factory()->create([
        'customer_id' => $otherCustomer->id,
        'portal_phone' => '+97455666666',
        'portal_phone_e164' => '+97455666666',
        'status' => 'active',
    ]);
    $otherUser->assignRole('customer');
    Sanctum::actingAs($otherUser, ['customer:*']);

    $this->getJson('/api/customer/checkouts/'.$attempt->reference)->assertForbidden();
    $this->getJson('/api/customer/checkouts')->assertOk()->assertJsonCount(0, 'data');
});

it('lets the destination login recover and complete a source checkout after an approved merge', function (): void {
    [$sourceUser, $sourceCustomer] = createSkipCashTracerCustomer();
    [$date, $main] = createSkipCashTracerMenu($this->branch->id);
    $cart = skipCashTracerCart($date, $main->id);
    $quote = $this->postJson('/api/customer/checkouts/quote', [
        'purpose' => 'ordinary_order',
        'cart' => $cart,
    ])->assertOk();
    $this->postJson('/api/customer/checkouts', [
        'client_uuid' => (string) Str::uuid(),
        'purpose' => 'ordinary_order',
        'cart' => $cart,
        'quote_fingerprint' => $quote->json('quote_fingerprint'),
        'accepted_terms_version' => 'v1',
    ])->assertStatus(202);

    $attempt = PaymentCheckoutAttempt::query()->firstOrFail();
    $transaction = PaymentProviderTransaction::query()->firstOrFail();
    $targetCustomer = Customer::factory()->create();
    $targetUser = User::factory()->create([
        'customer_id' => $targetCustomer->id,
        'portal_phone' => '+97455666666',
        'portal_phone_e164' => '+97455666666',
        'status' => 'active',
    ]);
    $targetUser->assignRole('customer');
    app(CustomerMergeService::class)->merge($sourceCustomer, $targetCustomer, $this->systemActor->id);

    Sanctum::actingAs($targetUser, ['customer:*']);
    $this->getJson('/api/customer/checkouts')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.reference', $attempt->reference);
    $this->getJson('/api/customer/checkouts/'.$attempt->reference)
        ->assertOk()
        ->assertJsonPath('reference', $attempt->reference);

    $destinationQuote = $this->postJson('/api/customer/checkouts/quote', [
        'purpose' => 'ordinary_order',
        'cart' => $cart,
    ])->assertOk();
    $this->postJson('/api/customer/checkouts', [
        'client_uuid' => (string) Str::uuid(),
        'purpose' => 'ordinary_order',
        'cart' => $cart,
        'quote_fingerprint' => $destinationQuote->json('quote_fingerprint'),
        'accepted_terms_version' => 'v1',
    ])->assertStatus(409)
        ->assertJsonPath('code', 'EXISTING_CHECKOUT')
        ->assertJsonPath('recovery_reference', $attempt->reference);

    $provider = app(SkipCashProvider::class);
    expect($provider)->toBeInstanceOf(FakeSkipCashProvider::class);
    $provider->markPaid($transaction->provider_payment_id);
    $payload = [
        'PaymentId' => $transaction->provider_payment_id,
        'Amount' => '65.00',
        'StatusId' => '2',
        'TransactionId' => str_replace('-', '', $attempt->reference),
        'Custom1' => '',
        'VisaId' => 'fake-visa-merged-owner',
    ];
    $signature = base64_encode(hash_hmac(
        'sha256',
        'PaymentId='.$payload['PaymentId'].',Amount=65.00,StatusId=2,TransactionId='.$payload['TransactionId'].',VisaId=fake-visa-merged-owner',
        'webhook-secret',
        true,
    ));

    $this->postJson('/api/integrations/skipcash/webhook', $payload, ['Authorization' => $signature])
        ->assertOk();

    $payment = Payment::query()->where('payment_source_id', $this->source->id)->firstOrFail();
    expect($sourceCustomer->fresh()->merged_into_customer_id)->toBe($targetCustomer->id)
        ->and($sourceUser->fresh()->status)->toBe('inactive')
        ->and($sourceUser->fresh()->customer_id)->toBeNull()
        ->and($attempt->fresh()->customer_id)->toBe($sourceCustomer->id)
        ->and($payment->customer_id)->toBe($targetCustomer->id)
        ->and(DB::table('orders')->value('customer_id'))->toBe($targetCustomer->id)
        ->and(DB::table('ar_invoices')->value('customer_id'))->toBe($targetCustomer->id);
});

it('retains retryable provider evidence when payment details are temporarily unavailable', function (): void {
    createSkipCashTracerCustomer();
    [$date, $main] = createSkipCashTracerMenu($this->branch->id);
    $cart = skipCashTracerCart($date, $main->id);
    $quote = $this->postJson('/api/customer/checkouts/quote', [
        'purpose' => 'ordinary_order',
        'cart' => $cart,
    ])->assertOk();
    $this->postJson('/api/customer/checkouts', [
        'client_uuid' => (string) Str::uuid(),
        'purpose' => 'ordinary_order',
        'cart' => $cart,
        'quote_fingerprint' => $quote->json('quote_fingerprint'),
        'accepted_terms_version' => 'v1',
    ])->assertStatus(202);

    $attempt = PaymentCheckoutAttempt::query()->firstOrFail();
    $providerTransaction = PaymentProviderTransaction::query()->firstOrFail();
    $fakeProvider = app(SkipCashProvider::class);
    expect($fakeProvider)->toBeInstanceOf(FakeSkipCashProvider::class);
    $fakeProvider->markPaid($providerTransaction->provider_payment_id);
    app()->instance(SkipCashProvider::class, new class implements SkipCashProvider
    {
        public function create(array $request): array
        {
            throw new RuntimeException('Create should not be called while processing a webhook.');
        }

        public function details(string $providerPaymentId): array
        {
            throw new RuntimeException('Provider details are temporarily unavailable.');
        }
    });
    $payload = [
        'PaymentId' => $providerTransaction->provider_payment_id,
        'Amount' => '65.00',
        'StatusId' => '2',
        'TransactionId' => str_replace('-', '', $attempt->reference),
        'Custom1' => '',
        'VisaId' => 'fake-visa-retry',
    ];
    $signature = base64_encode(hash_hmac(
        'sha256',
        'PaymentId='.$payload['PaymentId'].',Amount=65.00,StatusId=2,TransactionId='.$payload['TransactionId'].',VisaId=fake-visa-retry',
        'webhook-secret',
        true,
    ));

    $this->postJson('/api/integrations/skipcash/webhook', $payload, ['Authorization' => $signature])
        ->assertStatus(503)
        ->assertJsonPath('code', 'PAYMENT_PROCESSING_FAILED');

    $event = PaymentProviderEvent::query()->firstOrFail();
    expect($event->processing_state)->toBe('retryable')
        ->and($event->error_code)->toBe('PAYMENT_PROCESSING_FAILED')
        ->and($attempt->fresh()->state)->toBe('pending');

    app()->instance(SkipCashProvider::class, $fakeProvider);
    $event->update(['next_retry_at' => now('UTC')->subMinute()]);
    $result = app(SkipCashRecoveryService::class)->recover();

    expect($result['events_retried'])->toBe(1)
        ->and($attempt->fresh()->state)->toBe('completed')
        ->and($event->fresh()->processing_state)->toBe('processed');
});

it('recovers a verified known provider session when its callback never arrives', function (): void {
    createSkipCashTracerCustomer();
    [$date, $main] = createSkipCashTracerMenu($this->branch->id);
    $cart = skipCashTracerCart($date, $main->id);
    $quote = $this->postJson('/api/customer/checkouts/quote', [
        'purpose' => 'ordinary_order',
        'cart' => $cart,
    ])->assertOk();
    $this->postJson('/api/customer/checkouts', [
        'client_uuid' => (string) Str::uuid(),
        'purpose' => 'ordinary_order',
        'cart' => $cart,
        'quote_fingerprint' => $quote->json('quote_fingerprint'),
        'accepted_terms_version' => 'v1',
    ])->assertStatus(202);

    $attempt = PaymentCheckoutAttempt::query()->firstOrFail();
    $transaction = PaymentProviderTransaction::query()->firstOrFail();
    $provider = app(SkipCashProvider::class);
    expect($provider)->toBeInstanceOf(FakeSkipCashProvider::class);
    $provider->markPaid($transaction->provider_payment_id);
    $attempt->update(['next_recovery_at' => now('UTC')->subMinute()]);

    $result = app(SkipCashRecoveryService::class)->recover();

    expect($result['details_checked'])->toBe(1)
        ->and($attempt->fresh()->state)->toBe('completed')
        ->and(Payment::query()->where('payment_source_id', $this->source->id)->count())->toBe(1)
        ->and(PaymentProviderEvent::query()->where('signature_key_reference', 'details-authenticated')->count())->toBe(1)
        ->and(PaymentProviderEvent::query()->firstOrFail()->processing_state)->toBe('processed');
});

it('bounds unavailable provider detail recovery without creating another checkout', function (): void {
    createSkipCashTracerCustomer();
    [$date, $main] = createSkipCashTracerMenu($this->branch->id);
    $cart = skipCashTracerCart($date, $main->id);
    $quote = $this->postJson('/api/customer/checkouts/quote', [
        'purpose' => 'ordinary_order',
        'cart' => $cart,
    ])->assertOk();
    $this->postJson('/api/customer/checkouts', [
        'client_uuid' => (string) Str::uuid(),
        'purpose' => 'ordinary_order',
        'cart' => $cart,
        'quote_fingerprint' => $quote->json('quote_fingerprint'),
        'accepted_terms_version' => 'v1',
    ])->assertStatus(202);
    Config::set('payments.skipcash.recovery_max_attempts', 2);
    $attempt = PaymentCheckoutAttempt::query()->firstOrFail();
    $attempt->update(['next_recovery_at' => now('UTC')->subMinute()]);
    app()->instance(SkipCashProvider::class, new class implements SkipCashProvider
    {
        public function create(array $request): array
        {
            throw new RuntimeException('Create should not be called during details recovery.');
        }

        public function details(string $providerPaymentId): array
        {
            throw new RuntimeException('Provider details are unavailable.');
        }
    });

    app(SkipCashRecoveryService::class)->recover();
    $attempt->refresh();
    expect($attempt->provider_detail_recovery_attempts)->toBe(1)
        ->and($attempt->next_recovery_at)->not->toBeNull();

    $attempt->update(['next_recovery_at' => now('UTC')->subMinute()]);
    app(SkipCashRecoveryService::class)->recover();

    expect($attempt->fresh()->provider_detail_recovery_attempts)->toBe(2)
        ->and($attempt->fresh()->next_recovery_at)->toBeNull()
        ->and(PaymentCheckoutAttempt::query()->count())->toBe(1)
        ->and(Payment::query()->count())->toBe(0);
});

it('marks an ambiguous provider dispatch as unknown without creating another provider session', function (): void {
    createSkipCashTracerCustomer();
    [$date, $main] = createSkipCashTracerMenu($this->branch->id);
    $cart = skipCashTracerCart($date, $main->id);
    $quote = $this->postJson('/api/customer/checkouts/quote', [
        'purpose' => 'ordinary_order',
        'cart' => $cart,
    ])->assertOk();
    $this->postJson('/api/customer/checkouts', [
        'client_uuid' => (string) Str::uuid(),
        'purpose' => 'ordinary_order',
        'cart' => $cart,
        'quote_fingerprint' => $quote->json('quote_fingerprint'),
        'accepted_terms_version' => 'v1',
    ])->assertStatus(202);

    $attempt = PaymentCheckoutAttempt::query()->firstOrFail();
    PaymentProviderTransaction::query()->where('attempt_id', $attempt->id)->delete();
    $attempt->update([
        'provider_create_outcome' => 'in_flight',
        'provider_dispatched_at' => now('UTC')->subMinutes(2),
        'next_recovery_at' => now('UTC'),
    ]);

    $recovery = app(SkipCashRecoveryService::class)->recover();

    expect($recovery['marked_unknown'])->toBe(1)
        ->and($attempt->fresh()->provider_create_outcome)->toBe('unknown')
        ->and($attempt->fresh()->next_recovery_at)->toBeNull()
        ->and(PaymentProviderTransaction::query()->count())->toBe(0)
        ->and(DB::table('orders')->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0);
});

it('purges only raw provider bodies after the required retention period', function (): void {
    $event = PaymentProviderEvent::query()->create([
        'payment_source_id' => $this->source->id,
        'provider_payment_id' => 'retention-test',
        'payload_hash' => hash('sha256', 'retention-test'),
        'merchant_transaction_id' => 'retention-test',
        'amount_cents' => 6500,
        'raw_status' => '0',
        'normalized_status' => 'pending',
        'normalized_snapshot' => ['payment_id' => 'retention-test'],
        'signature_key_reference' => 'webhook-current',
        'processing_state' => 'processed',
        'received_at' => now('UTC')->subDays(91),
        'processed_at' => now('UTC')->subDays(91),
        'raw_body' => '{"PaymentId":"retention-test"}',
    ]);

    $purged = app(SkipCashRecoveryService::class)->purgeRawEventBodies();

    expect($purged)->toBe(1)
        ->and($event->fresh()->raw_body)->toBeNull()
        ->and($event->fresh()->raw_body_removed_at)->not->toBeNull()
        ->and($event->fresh()->normalized_snapshot)->toBe(['payment_id' => 'retention-test']);
});
