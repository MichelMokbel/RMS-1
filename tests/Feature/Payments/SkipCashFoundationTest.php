<?php

use App\Models\AccountingCompany;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\LedgerAccount;
use App\Models\Payment;
use App\Models\PaymentCheckoutAttempt;
use App\Models\PaymentCheckoutTarget;
use App\Models\PaymentProviderEvent;
use App\Models\PaymentProviderTransaction;
use App\Models\PaymentSetting;
use App\Models\PaymentSource;
use App\Models\User;
use App\Services\Payments\PaymentSettingsService;
use App\Services\Payments\PaymentSetupService;
use App\Services\Payments\PaymentTermsService;
use Database\Seeders\SkipCashPaymentSourceSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
    $this->clearingAccount = LedgerAccount::factory()->create([
        'company_id' => $this->company->id,
        'is_active' => true,
    ]);

    $this->newSource = function (array $overrides = []): PaymentSource {
        return PaymentSource::query()->create(array_merge([
            'company_id' => $this->company->id,
            'code' => PaymentSource::CODE_SKIPCASH,
            'name' => 'SkipCash',
            'method' => PaymentSource::METHOD_SKIPCASH,
            'clearing_account_id' => $this->clearingAccount->id,
            'is_active' => true,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ], $overrides));
    };

    $this->newAttempt = function (PaymentSource $source, array $overrides = []): PaymentCheckoutAttempt {
        $startedAt = now('UTC');

        return PaymentCheckoutAttempt::query()->create(array_merge([
            'reference' => (string) Str::uuid(),
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'portal_user_id' => $this->portalUser->id,
            'payment_source_id' => $source->id,
            'client_uuid' => (string) Str::uuid(),
            'purpose' => 'ordinary_order',
            'currency' => 'QAR',
            'gross_amount_cents' => 5000,
            'discount_amount_cents' => 0,
            'payable_amount_cents' => 5000,
            'cart_fingerprint' => str_repeat('a', 64),
            'quote_fingerprint' => str_repeat('b', 64),
            'request_fingerprint' => str_repeat('c', 64),
            'recovery_fingerprint' => str_repeat('d', 64),
            'state' => 'initiating',
            'started_at' => $startedAt,
            'expires_at' => $startedAt->copy()->addMinutes(15),
            'cart_snapshot' => ['dates' => ['2026-09-03']],
            'customer_snapshot' => ['phone' => '+97455555555', 'email' => 'private@example.com'],
            'pricing_snapshot' => ['total_cents' => 5000],
            'terms_snapshot' => ['version' => 'v1'],
            'request_snapshot' => ['purpose' => 'ordinary_order'],
            'source_account_snapshot' => ['clearing_account_id' => $source->clearing_account_id],
            'notification_dispatch' => ['customer_confirmation' => ['state' => 'pending']],
            'provider_request_uuid' => (string) Str::uuid(),
            'provider_create_outcome' => 'not_sent',
            'next_recovery_at' => $startedAt,
        ], $overrides));
    };
});

it('creates the disabled payment foundation without changing legacy payments', function (): void {
    expect(Schema::hasTable('payment_sources'))->toBeTrue()
        ->and(Schema::hasTable('payment_settings'))->toBeTrue()
        ->and(Schema::hasTable('payment_checkout_attempts'))->toBeTrue()
        ->and(Schema::hasTable('payment_checkout_targets'))->toBeTrue()
        ->and(Schema::hasTable('payment_provider_transactions'))->toBeTrue()
        ->and(Schema::hasTable('payment_provider_events'))->toBeTrue()
        ->and(Schema::hasColumn('payments', 'payment_source_id'))->toBeTrue()
        ->and(Schema::hasColumn('customers', 'phone_e164'))->toBeTrue()
        ->and(Schema::hasColumn('customers', 'phone_verified_at'))->toBeTrue()
        ->and(config('payments.skipcash.enabled'))->toBeFalse()
        ->and(config('payments.customer_direct_order_enabled'))->toBeTrue();

    $payment = Payment::factory()->create();

    expect($payment->payment_source_id)->toBeNull()
        ->and($payment->paymentSource)->toBeNull();
});

it('assigns payment permissions only to the administrator role', function (): void {
    $permissionIds = DB::table('permissions')
        ->where('guard_name', 'web')
        ->whereIn('name', ['payments.settings.manage', 'payments.support.view'])
        ->pluck('id');
    $adminRoleId = DB::table('roles')->where('name', 'admin')->where('guard_name', 'web')->value('id');

    expect($permissionIds)->toHaveCount(2)
        ->and(DB::table('role_has_permissions')
            ->where('role_id', $adminRoleId)
            ->whereIn('permission_id', $permissionIds)
            ->count())->toBe(2)
        ->and(DB::table('role_has_permissions')
            ->where('role_id', '!=', $adminRoleId)
            ->whereIn('permission_id', $permissionIds)
            ->count())->toBe(0);
});

it('enforces company ownership and unique source codes', function (): void {
    $source = ($this->newSource)();

    expect(fn () => ($this->newSource)())
        ->toThrow(QueryException::class);

    $otherCompany = AccountingCompany::query()->create([
        'name' => 'Other Company',
        'code' => 'OTHER-'.Str::lower(Str::random(6)),
        'base_currency' => 'QAR',
        'is_active' => true,
        'is_default' => false,
    ]);
    $otherAccount = LedgerAccount::factory()->create(['company_id' => $otherCompany->id]);

    expect(fn () => ($this->newSource)(['clearing_account_id' => $otherAccount->id]))
        ->toThrow(ValidationException::class);

    $payment = Payment::factory()->create([
        'company_id' => $this->company->id,
        'payment_source_id' => $source->id,
    ]);

    expect($payment->paymentSource->is($source))->toBeTrue()
        ->and(fn () => $source->delete())->toThrow(QueryException::class)
        ->and(fn () => $this->clearingAccount->delete())->toThrow(QueryException::class);
});

it('keeps encrypted checkout and provider evidence out of plain database fields', function (): void {
    $source = ($this->newSource)();
    $attempt = ($this->newAttempt)($source);
    $transaction = PaymentProviderTransaction::query()->create([
        'attempt_id' => $attempt->id,
        'payment_source_id' => $source->id,
        'provider_payment_id' => 'provider-payment-1',
        'merchant_transaction_id' => 'merchant-reference-1',
        'amount_cents' => 5000,
        'currency' => 'QAR',
        'normalized_status' => 'pending',
        'pay_url' => 'https://secure.example.test/private-token',
        'classification' => 'pending',
    ]);
    $event = PaymentProviderEvent::query()->create([
        'payment_source_id' => $source->id,
        'provider_transaction_id' => $transaction->id,
        'provider_payment_id' => 'provider-payment-1',
        'payload_hash' => hash('sha256', 'event-1'),
        'merchant_transaction_id' => 'merchant-reference-1',
        'amount_cents' => 5000,
        'raw_status' => '0',
        'normalized_status' => 'pending',
        'normalized_snapshot' => ['VisaId' => 'private-visa-reference'],
        'signature_key_reference' => 'webhook-current',
        'processing_state' => 'pending',
        'received_at' => now('UTC'),
        'raw_body' => '{"Phone":"+97455555555"}',
    ]);

    $rawAttempt = DB::table('payment_checkout_attempts')->find($attempt->id);
    $rawTransaction = DB::table('payment_provider_transactions')->find($transaction->id);
    $rawEvent = DB::table('payment_provider_events')->find($event->id);

    expect($attempt->fresh()->customer_snapshot['phone'])->toBe('+97455555555')
        ->and($transaction->fresh()->pay_url)->toBe('https://secure.example.test/private-token')
        ->and($event->fresh()->raw_body)->toBe('{"Phone":"+97455555555"}')
        ->and($rawAttempt->customer_snapshot)->not->toContain('+97455555555')
        ->and($rawTransaction->pay_url)->not->toContain('private-token')
        ->and($rawEvent->raw_body)->not->toContain('+97455555555')
        ->and($rawEvent->normalized_snapshot)->not->toContain('private-visa-reference');

    $event->update(['raw_body' => null, 'raw_body_removed_at' => now('UTC')]);

    expect($event->fresh()->raw_body)->toBeNull()
        ->and($event->fresh()->raw_body_removed_at)->not->toBeNull();
});

it('keeps provider and request replay identities unique while allowing event first evidence', function (): void {
    $source = ($this->newSource)();
    $attempt = ($this->newAttempt)($source);

    PaymentProviderEvent::query()->create([
        'payment_source_id' => $source->id,
        'provider_transaction_id' => null,
        'provider_payment_id' => 'provider-before-create-response',
        'payload_hash' => hash('sha256', 'event-before-transaction'),
        'normalized_status' => 'pending',
        'normalized_snapshot' => ['StatusId' => 1],
        'signature_key_reference' => 'webhook-current',
        'processing_state' => 'pending',
        'received_at' => now('UTC'),
        'raw_body' => '{}',
    ]);

    $first = PaymentProviderTransaction::query()->create([
        'attempt_id' => $attempt->id,
        'payment_source_id' => $source->id,
        'provider_payment_id' => 'provider-payment-1',
        'merchant_transaction_id' => 'same-merchant-reference',
        'amount_cents' => 5000,
        'currency' => 'QAR',
        'normalized_status' => 'pending',
        'classification' => 'pending',
    ]);
    $second = PaymentProviderTransaction::query()->create([
        'attempt_id' => $attempt->id,
        'payment_source_id' => $source->id,
        'provider_payment_id' => 'provider-payment-2',
        'merchant_transaction_id' => 'same-merchant-reference',
        'amount_cents' => 5000,
        'currency' => 'QAR',
        'normalized_status' => 'pending',
        'classification' => 'pending',
    ]);

    expect($first->merchant_transaction_id)->toBe($second->merchant_transaction_id)
        ->and(PaymentProviderEvent::query()->whereNull('provider_transaction_id')->count())->toBe(1)
        ->and(fn () => PaymentProviderTransaction::query()->create([
            'attempt_id' => $attempt->id,
            'payment_source_id' => $source->id,
            'provider_payment_id' => 'provider-payment-1',
            'merchant_transaction_id' => 'different-merchant-reference',
            'amount_cents' => 5000,
            'currency' => 'QAR',
            'normalized_status' => 'pending',
            'classification' => 'pending',
        ]))->toThrow(QueryException::class)
        ->and(fn () => ($this->newAttempt)($source, [
            'reference' => (string) Str::uuid(),
            'client_uuid' => $attempt->client_uuid,
        ]))->toThrow(QueryException::class);
});

it('enforces checkout settings and monetary constraints in MySQL', function (): void {
    $otherCompany = AccountingCompany::query()->create([
        'name' => 'Constraint Company',
        'code' => 'CHECK-'.Str::lower(Str::random(6)),
        'base_currency' => 'QAR',
        'is_active' => true,
        'is_default' => false,
    ]);

    expect(fn () => DB::table('payment_settings')->insert([
        'company_id' => $otherCompany->id,
        'checkout_duration_minutes' => 4,
        'booking_cutoff_time' => '23:00:00',
        'timezone' => 'Asia/Qatar',
    ]))->toThrow(QueryException::class);

    $source = ($this->newSource)();
    $attempt = ($this->newAttempt)($source);

    expect(fn () => PaymentCheckoutTarget::query()->create([
        'attempt_id' => $attempt->id,
        'sequence' => 1,
        'target_type' => 'order',
        'service_date' => now('Asia/Qatar')->addDay()->toDateString(),
        'expected_amount_cents' => -1,
        'item_snapshot' => ['main' => 1],
        'hold_state' => 'held',
        'held_at' => now('UTC'),
    ]))->toThrow(QueryException::class);
});

it('seeds one authoritative source and settings row without reactivating or remapping it', function (): void {
    config([
        'payments.system_user_id' => $this->actor->id,
        'payments.skipcash.clearing_account_id' => $this->clearingAccount->id,
        'payments.defaults.order_support_phone' => '+974 5555 0000',
    ]);

    $seeder = app(SkipCashPaymentSourceSeeder::class);
    $seeder->run();
    $seeder->run();

    $source = PaymentSource::query()->where('company_id', $this->company->id)->firstOrFail();

    expect(PaymentSource::query()->where('company_id', $this->company->id)->count())->toBe(1)
        ->and(PaymentSetting::query()->where('company_id', $this->company->id)->count())->toBe(1)
        ->and((int) $source->clearing_account_id)->toBe((int) $this->clearingAccount->id);

    $source->update(['is_active' => false, 'updated_by' => $this->actor->id]);
    $otherAccount = LedgerAccount::factory()->create(['company_id' => $this->company->id]);
    config(['payments.skipcash.clearing_account_id' => $otherAccount->id]);

    expect(fn () => $seeder->run())->toThrow(RuntimeException::class)
        ->and((int) $source->fresh()->clearing_account_id)->toBe((int) $this->clearingAccount->id)
        ->and($source->fresh()->is_active)->toBeFalse();
});

it('audits valid settings changes and rejects unsafe values', function (): void {
    $service = app(PaymentSettingsService::class);
    $settings = $service->save($this->company->id, [
        'checkout_duration_minutes' => 20,
        'booking_cutoff_time' => '22:30',
        'timezone' => 'Asia/Qatar',
        'order_support_phone' => '+974 5555 0000',
    ], $this->actor->id);

    expect($settings->checkout_duration_minutes)->toBe(20)
        ->and($settings->booking_cutoff_time)->toBe('22:30:00')
        ->and(DB::table('accounting_audit_logs')
            ->where('action', 'payment.settings.updated')
            ->where('subject_id', $settings->id)
            ->exists())->toBeTrue()
        ->and(fn () => $service->save($this->company->id, [
            'checkout_duration_minutes' => 61,
            'booking_cutoff_time' => '22:30',
            'timezone' => 'Asia/Qatar',
            'order_support_phone' => '+974 5555 0000',
        ], $this->actor->id))->toThrow(ValidationException::class)
        ->and(fn () => $service->save($this->company->id, [
            'checkout_duration_minutes' => 20,
            'booking_cutoff_time' => '22:30',
            'timezone' => 'UTC',
            'order_support_phone' => '+974 5555 0000',
        ], $this->actor->id))->toThrow(ValidationException::class);
});

it('fails closed until every live setup value has a valid source', function (): void {
    config([
        'payments.system_user_id' => $this->actor->id,
        'payments.skipcash.clearing_account_id' => $this->clearingAccount->id,
        'payments.defaults.order_support_phone' => '+974 5555 0000',
    ]);
    app(SkipCashPaymentSourceSeeder::class)->run();

    $termsPath = base_path('tests/Fixtures/payment-terms-v1.md');
    config([
        'payments.skipcash.enabled' => true,
        'payments.customer_direct_order_enabled' => false,
        'payments.skipcash.environment' => 'sandbox',
        'payments.skipcash.base_url' => 'https://api.sandbox.skipcash.test',
        'payments.skipcash.client_id' => 'client-id',
        'payments.skipcash.key_id' => 'key-id',
        'payments.skipcash.secret_key' => 'secret-key',
        'payments.skipcash.webhook_secret' => 'webhook-secret',
        'payments.skipcash.return_url' => 'https://orders.example.test/orders/payment',
        'payments.skipcash.webhook_url' => 'https://rms.example.test/api/integrations/skipcash/webhook',
        'payments.skipcash.pay_url_hosts' => ['pay.skipcash.test'],
        'payment_terms.published' => [[
            'version' => 'v1',
            'effective_at' => '2026-01-01T00:00:00+00:00',
            'url' => 'https://orders.example.test/terms/v1',
            'content_path' => $termsPath,
            'content_hash' => hash_file('sha256', $termsPath),
        ]],
    ]);

    $setup = app(PaymentSetupService::class);
    $ready = $setup->inspectForNewCheckout($this->branch->id);

    expect($ready['ready'])->toBeTrue()
        ->and($ready['company_id'])->toBe((int) $this->company->id)
        ->and($ready['payment_source_id'])->not->toBeNull();

    PaymentSource::query()->findOrFail($ready['payment_source_id'])
        ->update(['is_active' => false, 'updated_by' => $this->actor->id]);
    $blocked = $setup->inspectForNewCheckout($this->branch->id);

    expect($blocked['ready'])->toBeFalse()
        ->and(array_column($blocked['errors'], 'code'))->toContain('SKIPCASH_SOURCE_INVALID')
        ->and(PaymentCheckoutAttempt::query()->count())->toBe(0)
        ->and(PaymentProviderTransaction::query()->count())->toBe(0);
});

it('publishes the current payment terms from retained hash checked content', function (): void {
    $terms = app(PaymentTermsService::class)->inspect(now('UTC'));

    expect($terms['valid'])->toBeTrue()
        ->and($terms['errors'])->toBe([])
        ->and($terms['current']['version'])->toBe('2026-09-12-v4')
        ->and($terms['current']['url'])->toBe('https://layla-kitchen.com/terms-and-conditions')
        ->and($terms['current']['content_hash'])->toBe(
            hash_file('sha256', base_path('resources/legal/payment-terms/2026-09-12-v4.md'))
        );
});

it('allows loopback http callbacks only for a local sandbox', function (): void {
    config([
        'payments.system_user_id' => $this->actor->id,
        'payments.skipcash.clearing_account_id' => $this->clearingAccount->id,
        'payments.defaults.order_support_phone' => '+974 5555 0000',
    ]);
    app(SkipCashPaymentSourceSeeder::class)->run();

    config([
        'payments.skipcash.enabled' => true,
        'payments.customer_direct_order_enabled' => false,
        'payments.skipcash.environment' => 'sandbox',
        'payments.skipcash.base_url' => 'https://api.sandbox.skipcash.test',
        'payments.skipcash.client_id' => 'client-id',
        'payments.skipcash.key_id' => 'key-id',
        'payments.skipcash.secret_key' => 'secret-key',
        'payments.skipcash.webhook_secret' => 'webhook-secret',
        'payments.skipcash.return_url' => 'http://127.0.0.1:8098/orders/payment',
        'payments.skipcash.webhook_url' => 'http://localhost:8099/api/integrations/skipcash/webhook',
        'payments.skipcash.pay_url_hosts' => ['pay.skipcash.test'],
    ]);
    $this->app['env'] = 'local';

    $setup = app(PaymentSetupService::class);

    expect($setup->inspectForNewCheckout($this->branch->id)['ready'])->toBeTrue();

    config(['payments.skipcash.return_url' => 'http://192.168.1.10:8098/orders/payment']);
    expect(array_column($setup->inspectForNewCheckout($this->branch->id)['errors'], 'code'))
        ->toContain('SKIPCASH_URLS_INVALID');

    config([
        'payments.skipcash.environment' => 'production',
        'payments.skipcash.return_url' => 'http://127.0.0.1:8098/orders/payment',
    ]);
    expect(array_column($setup->inspectForNewCheckout($this->branch->id)['errors'], 'code'))
        ->toContain('SKIPCASH_URLS_INVALID');

    config([
        'payments.skipcash.environment' => 'sandbox',
        'queue.default' => 'database',
    ]);
    $this->app['env'] = 'production';
    expect(array_column($setup->inspectForNewCheckout($this->branch->id)['errors'], 'code'))
        ->toContain('SKIPCASH_URLS_INVALID');
});
