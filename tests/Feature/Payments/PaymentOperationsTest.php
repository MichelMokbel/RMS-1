<?php

use App\Jobs\InitiateSkipCashCheckout;
use App\Jobs\RetrySkipCashPaymentProcessing;
use App\Jobs\SendPaymentOperationsAlert;
use App\Jobs\SendSkipCashOrderConfirmation;
use App\Models\AccountingAuditLog;
use App\Models\AccountingCompany;
use App\Models\ArInvoice;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\EmailLog;
use App\Models\LedgerAccount;
use App\Models\MailSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentCheckoutAttempt;
use App\Models\PaymentCheckoutTarget;
use App\Models\PaymentProviderEvent;
use App\Models\PaymentProviderTransaction;
use App\Models\PaymentSetting;
use App\Models\PaymentSource;
use App\Models\User;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\Mail\EmailLogService;
use App\Services\Mail\MailSettingsService;
use App\Services\Payments\PaymentOperationsConsistencyService;
use App\Services\Payments\PaymentOperationsEvidenceService;
use App\Services\Payments\PaymentOperationsHealthService;
use App\Services\Payments\PaymentOperationsQueryService;
use App\Services\Payments\PaymentOperationsRecoveryService;
use App\Services\Payments\PaymentOperationsResendService;
use App\Services\Payments\PaymentOperationsTrackingService;
use App\Services\Payments\PaymentSettingsService;
use App\Services\Payments\SkipCashRecoveryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach ([
        'payments.support.view',
        'payments.support.recover',
        'payments.support.resend',
        'payments.settings.manage',
        'payments.credit.allocate',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('admin', 'web');
    $this->company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $this->branch = Branch::query()->findOrFail(1);
    $this->branch->update(['company_id' => $this->company->id, 'is_active' => true]);
    $this->customer = Customer::factory()->create();
    $this->portalUser = User::factory()->create([
        'customer_id' => $this->customer->id,
        'status' => 'active',
    ]);
    $clearing = LedgerAccount::factory()->create([
        'company_id' => $this->company->id,
        'is_active' => true,
    ]);
    $this->source = PaymentSource::query()->create([
        'company_id' => $this->company->id,
        'code' => 'skipcash',
        'name' => 'SkipCash',
        'method' => 'skipcash',
        'clearing_account_id' => $clearing->id,
        'is_active' => true,
    ]);
    config(['mail.daily_dish_admin_emails' => ['ops@example.test']]);

    $this->makeAttempt = function (array $overrides = []): PaymentCheckoutAttempt {
        $uuid = (string) Str::uuid();

        return PaymentCheckoutAttempt::query()->create(array_merge([
            'reference' => $uuid,
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'portal_user_id' => $this->portalUser->id,
            'payment_source_id' => $this->source->id,
            'client_uuid' => (string) Str::uuid(),
            'purpose' => 'ordinary_order',
            'currency' => 'QAR',
            'gross_amount_cents' => 6500,
            'discount_amount_cents' => 0,
            'payable_amount_cents' => 6500,
            'cart_fingerprint' => hash('sha256', 'cart-'.$uuid),
            'quote_fingerprint' => hash('sha256', 'quote-'.$uuid),
            'request_fingerprint' => hash('sha256', 'request-'.$uuid),
            'recovery_fingerprint' => hash('sha256', 'recovery-'.$uuid),
            'state' => 'paid_processing',
            'started_at' => now('UTC'),
            'expires_at' => now('UTC')->addMinutes(15),
            'cart_snapshot' => ['items' => []],
            'customer_snapshot' => ['email' => $this->customer->email],
            'pricing_snapshot' => ['amount_cents' => 6500],
            'terms_snapshot' => ['version' => 'v1'],
            'request_snapshot' => ['purpose' => 'ordinary_order'],
            'source_account_snapshot' => [
                'payment_source_id' => $this->source->id,
                'clearing_account_id' => $this->source->clearing_account_id,
            ],
            'provider_request_uuid' => (string) Str::uuid(),
            'provider_create_outcome' => 'created',
            'last_error_code' => 'FINANCIAL_PERIOD_BLOCKED',
        ], $overrides));
    };
});

it('adds compact operations tracking and seeds the dedicated administrator permissions', function (): void {
    $migration = require database_path('migrations/2026_09_05_000002_seed_payment_operations_permissions.php');
    $migration->up();
    $admin = Role::findByName('admin', 'web');

    expect(Schema::hasColumns('payment_checkout_attempts', ['operations_tracking', 'operations_next_action_at']))->toBeTrue()
        ->and($admin->hasAllPermissions([
            'payments.support.view',
            'payments.support.recover',
            'payments.support.resend',
            'payments.settings.manage',
            'payments.credit.allocate',
        ]))->toBeTrue();
});

it('records one immediate alert intent for a finance blocked verified payment', function (): void {
    Queue::fake([SendPaymentOperationsAlert::class]);
    config(['mail.daily_dish_admin_emails' => ['deployment@example.test']]);
    MailSetting::query()->create([
        'id' => MailSetting::SINGLETON_ID,
        'smtp_host' => 'smtp.saved.example',
        'smtp_port' => 587,
        'security_mode' => 'starttls',
        'smtp_username' => null,
        'smtp_password' => null,
        'from_address' => 'orders@example.test',
        'from_name' => 'Layla Kitchen',
        'daily_dish_admin_emails' => Crypt::encryptString(json_encode(['saved-ops@example.test'], JSON_THROW_ON_ERROR)),
        'revision' => 1,
    ]);
    $attempt = ($this->makeAttempt)();
    $service = app(PaymentOperationsTrackingService::class);

    $service->recordProcessingFailure($attempt->id, 'FINANCIAL_PERIOD_BLOCKED');
    $service->recordProcessingFailure($attempt->id, 'FINANCIAL_PERIOD_BLOCKED');

    $attempt->refresh();
    $issue = $attempt->operations_tracking['issues']['processing'];
    expect($issue['reason_code'])->toBe('FINANCIAL_PERIOD_BLOCKED')
        ->and($issue['resolved_at'])->toBeNull()
        ->and($issue['alert']['state'])->toBe('pending')
        ->and($attempt->notification_snapshots['operations_alerts'][$issue['episode_uuid']]['admin_emails'])->toBe(['saved-ops@example.test'])
        ->and(AccountingAuditLog::query()->where('action', 'payment.operations.issue_opened')->count())->toBe(1)
        ->and(AccountingAuditLog::query()->where('action', 'payment.operations.alert_intended')->count())->toBe(1);
    Queue::assertPushed(SendPaymentOperationsAlert::class, fn ($job): bool => $job->slot === 'processing');
});

it('sends one administrator alert for an unresolved immediate issue', function (): void {
    config(['mail.default' => 'array']);
    $attempt = ($this->makeAttempt)();
    $service = app(PaymentOperationsTrackingService::class);

    $service->recordProcessingFailure($attempt->id, 'FINANCIAL_PERIOD_BLOCKED');
    $service->recordProcessingFailure($attempt->id, 'FINANCIAL_PERIOD_BLOCKED');

    $issue = $attempt->fresh()->operations_tracking['issues']['processing'];
    expect($issue['alert']['state'])->toBe('sent')
        ->and(EmailLog::query()->where('category', 'payment_operations_alert')->count())->toBe(1)
        ->and(AccountingAuditLog::query()->where('action', 'payment.operations.alert_sent')->count())->toBe(1);
});

it('repairs an empty administrator confirmation snapshot after mail recipients are configured', function (): void {
    Queue::fake([SendSkipCashOrderConfirmation::class]);
    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');
    $order = Order::factory()->create([
        'branch_id' => $this->branch->id,
        'customer_id' => $this->customer->id,
    ]);
    $attempt = ($this->makeAttempt)([
        'state' => 'completed',
        'completed_at' => now('UTC'),
        'last_error_code' => null,
        'notification_snapshots' => [
            'customer_email' => $this->customer->email,
            'admin_emails' => [],
            'order_ids' => [$order->id],
            'amount_cents' => 6500,
            'reference' => 'missing-admin-recipient',
        ],
        'notification_dispatch' => [
            'customer_confirmation' => ['state' => 'sent'],
            'admin_confirmation' => [
                'state' => 'failed',
                'error_code' => 'ADMIN_RECIPIENT_MISSING',
                'attempts' => 1,
            ],
        ],
        'operations_tracking' => [
            'issues' => [
                'admin_confirmation' => [
                    'episode_uuid' => (string) Str::uuid(),
                    'reason_code' => 'ADMIN_RECIPIENT_MISSING',
                    'first_seen_at' => now('UTC')->toIso8601String(),
                    'last_seen_at' => now('UTC')->toIso8601String(),
                    'attention_at' => now('UTC')->toIso8601String(),
                    'resolved_at' => null,
                    'alert' => ['state' => 'failed'],
                ],
            ],
        ],
    ]);
    MailSetting::query()->create([
        'id' => MailSetting::SINGLETON_ID,
        'smtp_host' => 'smtp.saved.example',
        'smtp_port' => 587,
        'security_mode' => 'starttls',
        'smtp_username' => null,
        'smtp_password' => null,
        'from_address' => 'orders@example.test',
        'from_name' => 'Layla Kitchen',
        'daily_dish_admin_emails' => Crypt::encryptString(json_encode(['admin@example.test'], JSON_THROW_ON_ERROR)),
        'revision' => 1,
        'updated_by' => $admin->id,
    ]);
    $operationUuid = (string) Str::uuid();

    $this->actingAs($admin)
        ->get(route('receivables.payments.skipcash.show', $attempt))
        ->assertOk()
        ->assertSee('Retry administrator confirmation');

    $service = app(PaymentOperationsResendService::class);
    $result = $service->retryAdminConfirmation(
        $attempt->id,
        $operationUuid,
        $admin,
    );
    $replay = $service->retryAdminConfirmation($attempt->id, $operationUuid, $admin);

    $attempt->refresh();
    expect($result)->toBe(['operation_uuid' => $operationUuid, 'state' => 'queued'])
        ->and($replay)->toBe(['operation_uuid' => $operationUuid, 'state' => 'pending'])
        ->and($attempt->notification_snapshots['admin_emails'])->toBe(['admin@example.test'])
        ->and($attempt->notification_dispatch['admin_confirmation']['state'])->toBe('pending')
        ->and(AccountingAuditLog::query()->where('action', 'payment.operations.admin_confirmation_requeued')->count())->toBe(1)
        ->and(json_encode(AccountingAuditLog::query()->where('action', 'payment.operations.admin_confirmation_requeued')->value('payload')))->not->toContain('admin@example.test');
    Queue::assertPushed(SendSkipCashOrderConfirmation::class, 1);
    Queue::assertPushed(SendSkipCashOrderConfirmation::class, fn ($job): bool => $job->attemptId === $attempt->id && $job->audience === 'admin');

    $staff = User::factory()->create(['status' => 'active']);
    $staff->givePermissionTo('payments.support.view');
    DB::table('user_branch_access')->insert([
        'user_id' => $staff->id,
        'branch_id' => $this->branch->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    expect(fn () => $service->retryAdminConfirmation($attempt->id, (string) Str::uuid(), $staff))
        ->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);

    Mail::fake();
    (new SendSkipCashOrderConfirmation($attempt->id, 'admin'))->handle(
        app(EmailLogService::class),
        app(PaymentOperationsTrackingService::class),
        app(MailSettingsService::class),
    );
    $attempt->refresh();
    expect($attempt->notification_dispatch['admin_confirmation']['state'])->toBe('sent')
        ->and($attempt->operations_tracking['issues']['admin_confirmation']['resolved_at'])->not->toBeNull();
    Mail::assertSent(\App\Mail\DailyDishOrderAdminMail::class, fn ($mail): bool => $mail->hasTo('admin@example.test'));

    $this->actingAs($admin)
        ->get(route('receivables.payments.skipcash.show', $attempt))
        ->assertOk()
        ->assertDontSee('Retry administrator confirmation');
});

it('waits fifteen minutes before alerting on a temporary processing issue', function (): void {
    Queue::fake([SendPaymentOperationsAlert::class]);
    Carbon::setTestNow('2026-09-04 10:00:00 UTC');
    try {
        $attempt = ($this->makeAttempt)(['last_error_code' => 'PAYMENT_PROCESSING_FAILED']);
        $service = app(PaymentOperationsTrackingService::class);
        $service->recordProcessingFailure($attempt->id, 'PAYMENT_PROCESSING_FAILED');

        expect(data_get($attempt->fresh()->operations_tracking, 'issues.processing.alert'))->toBeNull();
        Carbon::setTestNow('2026-09-04 10:14:00 UTC');
        $service->observeOutstanding();
        Queue::assertNotPushed(SendPaymentOperationsAlert::class);

        Carbon::setTestNow('2026-09-04 10:15:00 UTC');
        $service->observeOutstanding();
        Queue::assertPushed(SendPaymentOperationsAlert::class, fn ($job): bool => $job->slot === 'processing');
        expect(data_get($attempt->fresh()->operations_tracking, 'issues.processing.alert.state'))->toBe('pending');
    } finally {
        Carbon::setTestNow();
    }
});

it('keeps simultaneous payment issues independent', function (): void {
    Queue::fake([SendPaymentOperationsAlert::class]);
    $attempt = ($this->makeAttempt)();
    $service = app(PaymentOperationsTrackingService::class);

    $service->recordProcessingFailure($attempt->id, 'FINANCIAL_PERIOD_BLOCKED');
    $service->recordProviderEvidenceIssue($attempt->id, 'PROVIDER_REVERSAL_REVIEW');
    $service->recordConfirmationFailure($attempt->id, 'customer', 'CUSTOMER_RECIPIENT_MISSING');
    $service->resolveProcessingIssue($attempt->id);

    $issues = $attempt->fresh()->operations_tracking['issues'];
    expect($issues['processing']['resolved_at'])->not->toBeNull()
        ->and($issues['provider_evidence']['resolved_at'])->toBeNull()
        ->and($issues['customer_confirmation']['resolved_at'])->toBeNull()
        ->and($issues['provider_evidence']['episode_uuid'])->not->toBe($issues['customer_confirmation']['episode_uuid']);
    Queue::assertPushed(SendPaymentOperationsAlert::class, 3);
});

it('surfaces an unknown provider create outcome without creating another checkout', function (): void {
    Queue::fake([SendPaymentOperationsAlert::class]);
    $attempt = ($this->makeAttempt)([
        'state' => 'pending',
        'provider_create_outcome' => 'in_flight',
        'provider_dispatched_at' => now('UTC')->subMinutes(2),
        'last_error_code' => null,
        'financial_intent' => null,
    ]);

    $result = app(SkipCashRecoveryService::class)->recover();

    expect($result['marked_unknown'])->toBe(1)
        ->and($attempt->fresh()->provider_create_outcome)->toBe('unknown')
        ->and(data_get($attempt->fresh()->operations_tracking, 'issues.provider_evidence.reason_code'))->toBe('PROVIDER_CREATE_UNKNOWN')
        ->and(PaymentCheckoutAttempt::query()->count())->toBe(1)
        ->and(PaymentProviderTransaction::query()->count())->toBe(0);
    Queue::assertPushed(SendPaymentOperationsAlert::class, 1);
});

it('returns only scoped normalized provider evidence and audits the inspection', function (): void {
    $staff = User::factory()->create(['status' => 'active']);
    $staff->givePermissionTo('payments.support.view');
    DB::table('user_branch_access')->insert([
        'user_id' => $staff->id,
        'branch_id' => $this->branch->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $attempt = ($this->makeAttempt)();
    $event = PaymentProviderEvent::query()->create([
        'payment_source_id' => $this->source->id,
        'provider_payment_id' => 'provider-evidence',
        'payload_hash' => hash('sha256', 'provider-evidence'),
        'merchant_transaction_id' => str_replace('-', '', $attempt->reference),
        'amount_cents' => 6500,
        'raw_status' => '2',
        'normalized_status' => 'paid',
        'normalized_snapshot' => [
            'payment_id' => 'provider-evidence',
            'amount_cents' => 6500,
            'currency' => 'QAR',
            'status_id' => '2',
            'provider_secret' => 'must-not-be-returned',
        ],
        'signature_key_reference' => 'test-key',
        'processing_state' => 'retryable',
        'error_code' => 'FINANCIAL_PERIOD_BLOCKED',
        'received_at' => now('UTC'),
        'raw_body' => '{"token":"must-not-be-returned"}',
    ]);

    $result = app(PaymentOperationsEvidenceService::class)->inspect($attempt->id, $event->id, $staff);

    expect($result['raw_evidence'])->toBe('retained_private')
        ->and($result['normalized'])->toMatchArray(['amount_cents' => 6500, 'currency' => 'QAR'])
        ->and($result['normalized'])->not->toHaveKey('provider_secret')
        ->and(json_encode($result))->not->toContain('must-not-be-returned')
        ->and(AccountingAuditLog::query()->where('action', 'payment.operations.evidence_inspected')->count())->toBe(1);

    $unrelated = PaymentProviderEvent::query()->create([
        'payment_source_id' => $this->source->id,
        'provider_payment_id' => 'unrelated',
        'payload_hash' => hash('sha256', 'unrelated'),
        'merchant_transaction_id' => 'another-checkout',
        'normalized_status' => 'unknown',
        'normalized_snapshot' => [],
        'signature_key_reference' => 'test-key',
        'processing_state' => 'pending',
        'received_at' => now('UTC'),
    ]);
    expect(fn () => app(PaymentOperationsEvidenceService::class)->inspect($attempt->id, $unrelated->id, $staff))
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

it('shows only company and branch scoped SkipCash operations to support staff', function (): void {
    Queue::fake([SendPaymentOperationsAlert::class]);
    $staff = User::factory()->create(['status' => 'active']);
    $staff->givePermissionTo('payments.support.view');
    DB::table('user_branch_access')->insert([
        'user_id' => $staff->id,
        'branch_id' => $this->branch->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $visible = ($this->makeAttempt)();
    app(PaymentOperationsTrackingService::class)->recordProcessingFailure($visible->id, 'FINANCIAL_PERIOD_BLOCKED');

    $otherBranch = Branch::query()->create([
        'company_id' => $this->company->id,
        'name' => 'Other Branch',
        'code' => 'OTHER-OPS',
        'is_active' => true,
    ]);
    $hidden = ($this->makeAttempt)(['branch_id' => $otherBranch->id, 'client_uuid' => (string) Str::uuid(), 'reference' => (string) Str::uuid()]);
    app(PaymentOperationsTrackingService::class)->recordProcessingFailure($hidden->id, 'FINANCIAL_PERIOD_BLOCKED');

    $this->actingAs($staff)
        ->get(route('receivables.payments.skipcash.index'))
        ->assertOk()
        ->assertSee($visible->reference)
        ->assertDontSee($hidden->reference);
    $this->actingAs($staff)
        ->get(route('receivables.payments.skipcash.show', $visible))
        ->assertOk()
        ->assertSee('Provider payment')
        ->assertSee('RMS completion')
        ->assertSee('Confirmation email')
        ->assertSee('Settlement');
    $this->actingAs($staff)
        ->get(route('receivables.payments.skipcash.show', $hidden))
        ->assertForbidden();

    $unauthorized = User::factory()->create(['status' => 'active']);
    $this->actingAs($unauthorized)
        ->get(route('receivables.payments.skipcash.index'))
        ->assertForbidden();
});

it('searches and displays the current customer after an existing customer merge', function (): void {
    $staff = User::factory()->create(['status' => 'active']);
    $staff->givePermissionTo('payments.support.view');
    DB::table('user_branch_access')->insert([
        'user_id' => $staff->id,
        'branch_id' => $this->branch->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $attempt = ($this->makeAttempt)(['state' => 'completed', 'last_error_code' => null]);
    $destination = Customer::factory()->create(['name' => 'Canonical Operations Customer']);
    $this->customer->forceFill([
        'is_active' => false,
        'merged_into_customer_id' => $destination->id,
    ])->save();

    $results = app(PaymentOperationsQueryService::class)->query($staff, [
        'view' => 'all',
        'search' => 'Canonical Operations',
    ])->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->id)->toBe($attempt->id);
    $this->actingAs($staff)
        ->get(route('receivables.payments.skipcash.show', $attempt))
        ->assertOk()
        ->assertSee('Canonical Operations Customer');
});

it('accepts an exact processing retry once without creating another payment', function (): void {
    Queue::fake([SendPaymentOperationsAlert::class, RetrySkipCashPaymentProcessing::class]);
    $staff = User::factory()->create(['status' => 'active']);
    $staff->givePermissionTo(['payments.support.view', 'payments.support.recover']);
    DB::table('user_branch_access')->insert([
        'user_id' => $staff->id,
        'branch_id' => $this->branch->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $attempt = ($this->makeAttempt)();
    $transaction = PaymentProviderTransaction::query()->create([
        'attempt_id' => $attempt->id,
        'payment_source_id' => $this->source->id,
        'provider_payment_id' => 'provider-'.$attempt->id,
        'merchant_transaction_id' => str_replace('-', '', $attempt->reference),
        'amount_cents' => 6500,
        'currency' => 'QAR',
        'raw_status' => '2',
        'normalized_status' => 'paid',
        'classification' => 'pending',
        'verified_paid_at' => now('UTC'),
        'verified_amount_cents' => 6500,
        'verified_currency' => 'QAR',
        'verified_finished_at' => now('UTC'),
    ]);
    $attempt->update(['financial_intent' => [
        'provider_transaction_id' => $transaction->id,
        'allocation_date' => now('Asia/Qatar')->toDateString(),
        'invoice_issue_date' => now('Asia/Qatar')->toDateString(),
    ]]);
    app(PaymentOperationsTrackingService::class)->recordProcessingFailure($attempt->id, 'FINANCIAL_PERIOD_BLOCKED');
    $operationUuid = (string) Str::uuid();
    RateLimiter::clear('payment-operations-retry:'.$staff->id.':'.$attempt->id);
    $service = app(PaymentOperationsRecoveryService::class);

    $first = $service->retry($attempt->id, $operationUuid, $staff);
    $replay = $service->retry($attempt->id, $operationUuid, $staff);

    expect($first)->toBe(['operation_uuid' => $operationUuid, 'state' => 'queued'])
        ->and($replay)->toBe($first)
        ->and(Payment::query()->count())->toBe(0)
        ->and(PaymentCheckoutAttempt::query()->count())->toBe(1)
        ->and(AccountingAuditLog::query()->where('action', 'payment.operations.recovery_accepted')->count())->toBe(1);
    Queue::assertPushed(RetrySkipCashPaymentProcessing::class, 1);

    $tracking = $attempt->fresh()->operations_tracking;
    $tracking['recovery']['state'] = 'blocked';
    $attempt->update(['operations_tracking' => $tracking]);
    app(AccountingAuditLogService::class)->log('payment.operations.recovery_blocked', $staff->id, $attempt, [
        'operation_uuid' => $operationUuid,
        'reason_code' => 'FINANCIAL_PERIOD_BLOCKED',
    ], $attempt->company_id);
    $nextOperationUuid = (string) Str::uuid();
    expect($service->retry($attempt->id, $nextOperationUuid, $staff)['state'])->toBe('queued')
        ->and($service->retry($attempt->id, $operationUuid, $staff)['state'])->toBe('blocked');
    Queue::assertPushed(RetrySkipCashPaymentProcessing::class, 2);

    $tracking = $attempt->fresh()->operations_tracking;
    $tracking['recovery']['queued_at'] = now('UTC')->subMinutes(3)->toIso8601String();
    $attempt->update([
        'operations_tracking' => $tracking,
        'operations_next_action_at' => now('UTC')->subMinute(),
    ]);
    app(PaymentOperationsTrackingService::class)->observeOutstanding();
    Queue::assertPushed(RetrySkipCashPaymentProcessing::class, 3);
});

it('returns and updates versioned payment settings without changing an active checkout snapshot', function (): void {
    $staff = User::factory()->create(['status' => 'active']);
    $staff->givePermissionTo('payments.settings.manage');
    $settings = PaymentSetting::query()->create([
        'company_id' => $this->company->id,
        'checkout_duration_minutes' => 15,
        'booking_cutoff_time' => '23:00:00',
        'timezone' => PaymentSetting::TIMEZONE,
        'order_support_phone' => '+974 5555 0000',
        'created_by' => $staff->id,
        'updated_by' => $staff->id,
    ]);
    $attempt = ($this->makeAttempt)([
        'state' => 'pending',
        'last_error_code' => null,
        'expires_at' => now('UTC')->addMinutes(15),
        'terms_snapshot' => [
            'checkout_duration_minutes' => 15,
            'booking_cutoff_time' => '23:00',
            'timezone' => PaymentSetting::TIMEZONE,
        ],
    ]);
    $originalExpiry = $attempt->expires_at->toISOString();
    $originalSnapshot = $attempt->terms_snapshot;

    $response = $this->actingAs($staff)
        ->getJson(route('api.accounting.payment-settings.show'))
        ->assertOk()
        ->assertJsonPath('settings.checkout_duration_minutes', 15)
        ->assertJsonPath('settings.timezone', PaymentSetting::TIMEZONE);
    $version = (string) $response->json('settings.version');

    $updated = $this->actingAs($staff)
        ->putJson(route('api.accounting.payment-settings.update'), [
            'checkout_duration_minutes' => 25,
            'booking_cutoff_time' => '22:15',
            'order_support_phone' => '+974 4444 0000',
            'expected_version' => $version,
        ])
        ->assertOk()
        ->assertJsonPath('settings.checkout_duration_minutes', 25)
        ->assertJsonPath('settings.booking_cutoff_time', '22:15')
        ->assertJsonPath('settings.order_support_phone', '+974 4444 0000');

    expect($updated->json('settings.version'))->not->toBe($version)
        ->and($updated->json('audit_id'))->toBeInt()
        ->and((int) $updated->json('audit_id'))->toBeGreaterThan(0)
        ->and($settings->fresh()->checkout_duration_minutes)->toBe(25)
        ->and($attempt->fresh()->expires_at->toISOString())->toBe($originalExpiry)
        ->and($attempt->fresh()->terms_snapshot)->toBe($originalSnapshot)
        ->and(AccountingAuditLog::query()->where('action', 'payment.settings.updated')->count())->toBe(1);
});

it('rejects stale payment settings edits and returns the current version', function (): void {
    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');
    $settings = PaymentSetting::query()->create([
        'company_id' => $this->company->id,
        'checkout_duration_minutes' => 15,
        'booking_cutoff_time' => '23:00:00',
        'timezone' => PaymentSetting::TIMEZONE,
        'order_support_phone' => '+974 5555 0000',
        'created_by' => $admin->id,
        'updated_by' => $admin->id,
    ]);
    $service = app(PaymentSettingsService::class);
    $staleVersion = $service->version($settings);
    $service->saveVersioned($this->company->id, [
        'checkout_duration_minutes' => 20,
        'booking_cutoff_time' => '22:30',
        'timezone' => PaymentSetting::TIMEZONE,
        'order_support_phone' => '+974 5555 0000',
    ], $admin, $staleVersion);

    $this->actingAs($admin)
        ->putJson(route('api.accounting.payment-settings.update'), [
            'checkout_duration_minutes' => 30,
            'booking_cutoff_time' => '21:30',
            'order_support_phone' => '+974 3333 0000',
            'expected_version' => $staleVersion,
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'PAYMENT_SETTINGS_STALE')
        ->assertJsonPath('settings.checkout_duration_minutes', 20);

    expect($settings->fresh()->checkout_duration_minutes)->toBe(20)
        ->and(AccountingAuditLog::query()->where('action', 'payment.settings.updated')->count())->toBe(1);
});

it('keeps payment settings unavailable to unauthorized staff and customer accounts', function (): void {
    $settings = PaymentSetting::query()->create([
        'company_id' => $this->company->id,
        'checkout_duration_minutes' => 15,
        'booking_cutoff_time' => '23:00:00',
        'timezone' => PaymentSetting::TIMEZONE,
        'order_support_phone' => '+974 5555 0000',
    ]);
    $staff = User::factory()->create(['status' => 'active']);
    $customerUser = User::factory()->create(['status' => 'active']);
    Role::findOrCreate('customer', 'web');
    $customerUser->assignRole('customer');
    $customerUser->givePermissionTo('payments.settings.manage');

    $this->actingAs($staff)
        ->getJson(route('api.accounting.payment-settings.show'))
        ->assertForbidden();
    $this->actingAs($staff)
        ->get(route('settings.payments'))
        ->assertForbidden();
    $this->actingAs($customerUser)
        ->getJson(route('api.accounting.payment-settings.show'))
        ->assertForbidden();

    expect($settings->fresh()->checkout_duration_minutes)->toBe(15)
        ->and(AccountingAuditLog::query()->where('action', 'payment.settings.updated')->count())->toBe(0);
});

it('renders the payment settings form for an authorized operator', function (): void {
    $staff = User::factory()->create(['status' => 'active']);
    $staff->givePermissionTo('payments.settings.manage');
    PaymentSetting::query()->create([
        'company_id' => $this->company->id,
        'checkout_duration_minutes' => 15,
        'booking_cutoff_time' => '23:00:00',
        'timezone' => PaymentSetting::TIMEZONE,
        'order_support_phone' => '+974 5555 0000',
        'created_by' => $staff->id,
        'updated_by' => $staff->id,
    ]);

    $this->actingAs($staff)
        ->get(route('settings.payments'))
        ->assertOk()
        ->assertSee('Checkout duration')
        ->assertSee('Membership booking change cutoff')
        ->assertSee('SkipCash credentials and signing secrets');

    Volt::test('settings.payments')
        ->set('checkout_duration_minutes', 35)
        ->set('booking_cutoff_time', '21:45')
        ->set('order_support_phone', '+974 2222 0000')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('checkout_duration_minutes', 35)
        ->assertSet('booking_cutoff_time', '21:45')
        ->assertSet('order_support_phone', '+974 2222 0000');

    expect(PaymentSetting::query()->where('company_id', $this->company->id)->value('checkout_duration_minutes'))->toBe(35);
});

it('reports basic completed payment inconsistencies without changing financial records', function (): void {
    Queue::fake([SendPaymentOperationsAlert::class]);
    $attempt = ($this->makeAttempt)([
        'state' => 'completed',
        'completed_at' => now('UTC'),
        'last_error_code' => null,
    ]);
    $order = Order::factory()->create([
        'branch_id' => $this->branch->id,
        'customer_id' => $this->customer->id,
    ]);
    $invoice = ArInvoice::factory()->create([
        'company_id' => $this->company->id,
        'branch_id' => $this->branch->id,
        'customer_id' => $this->customer->id,
        'source_order_id' => $order->id,
        'type' => 'invoice',
        'status' => 'paid',
        'currency' => 'QAR',
        'total_cents' => 6500,
        'paid_total_cents' => 6500,
        'balance_cents' => 0,
    ]);
    PaymentCheckoutTarget::query()->create([
        'attempt_id' => $attempt->id,
        'sequence' => 1,
        'target_type' => 'order',
        'service_date' => now('Asia/Qatar')->addDay()->toDateString(),
        'expected_amount_cents' => 6500,
        'item_snapshot' => ['items' => []],
        'hold_state' => 'activated',
        'held_at' => now('UTC')->subMinute(),
        'activated_at' => now('UTC'),
        'intended_invoice_issue_date' => now('Asia/Qatar')->toDateString(),
        'order_id' => $order->id,
        'invoice_id' => $invoice->id,
    ]);
    $payment = Payment::factory()->create([
        'company_id' => $this->company->id,
        'branch_id' => $this->branch->id,
        'customer_id' => $this->customer->id,
        'payment_source_id' => $this->source->id,
        'source' => 'ar',
        'method' => 'skipcash',
        'amount_cents' => 6400,
        'currency' => 'QAR',
    ]);
    PaymentAllocation::query()->create([
        'payment_id' => $payment->id,
        'allocatable_type' => ArInvoice::class,
        'allocatable_id' => $invoice->id,
        'amount_cents' => 6400,
    ]);
    PaymentProviderTransaction::query()->create([
        'attempt_id' => $attempt->id,
        'payment_source_id' => $this->source->id,
        'provider_payment_id' => 'health-provider-'.$attempt->id,
        'merchant_transaction_id' => str_replace('-', '', $attempt->reference),
        'amount_cents' => 6500,
        'currency' => 'QAR',
        'raw_status' => '2',
        'normalized_status' => 'paid',
        'classification' => 'purchase',
        'verified_paid_at' => now('UTC'),
        'verified_amount_cents' => 6500,
        'verified_currency' => 'QAR',
        'verified_finished_at' => now('UTC'),
        'payment_id' => $payment->id,
    ]);

    $service = app(PaymentOperationsConsistencyService::class);
    expect($service->check($attempt->id))->toBe('CONSISTENCY_RECEIPT_AMOUNT_MISMATCH')
        ->and($service->check($attempt->id))->toBe('CONSISTENCY_RECEIPT_AMOUNT_MISMATCH')
        ->and($payment->fresh()->amount_cents)->toBe(6400)
        ->and(PaymentAllocation::query()->where('payment_id', $payment->id)->value('amount_cents'))->toBe(6400)
        ->and(AccountingAuditLog::query()->where('action', 'payment.operations.issue_opened')->count())->toBe(1);

    $issue = data_get($attempt->fresh()->operations_tracking, 'issues.processing');
    expect($issue['reason_code'])->toBe('CONSISTENCY_RECEIPT_AMOUNT_MISMATCH')
        ->and($issue['resolved_at'])->toBeNull();
    Queue::assertPushed(SendPaymentOperationsAlert::class, 1);
});

it('records recovery and purge health while disabled collection leaves existing operations available', function (): void {
    config([
        'cache.default' => 'array',
        'payments.skipcash.enabled' => false,
    ]);
    Cache::flush();
    Queue::fake();
    $pending = ($this->makeAttempt)([
        'state' => 'pending',
        'provider_create_outcome' => 'not_sent',
        'last_error_code' => null,
        'financial_intent' => null,
        'expires_at' => now('UTC')->addMinutes(15),
    ]);

    $result = app(SkipCashRecoveryService::class)->recover();
    expect($result['initiation_dispatched'])->toBe(0)
        ->and($pending->fresh()->provider_create_outcome)->toBe('not_sent');
    Queue::assertNotPushed(InitiateSkipCashCheckout::class);

    $recoverable = ($this->makeAttempt)();
    $provider = PaymentProviderTransaction::query()->create([
        'attempt_id' => $recoverable->id,
        'payment_source_id' => $this->source->id,
        'provider_payment_id' => 'disabled-recovery-'.$recoverable->id,
        'merchant_transaction_id' => str_replace('-', '', $recoverable->reference),
        'amount_cents' => 6500,
        'currency' => 'QAR',
        'raw_status' => '2',
        'normalized_status' => 'paid',
        'classification' => 'pending',
        'verified_paid_at' => now('UTC'),
        'verified_amount_cents' => 6500,
        'verified_currency' => 'QAR',
        'verified_finished_at' => now('UTC'),
    ]);
    $recoverable->update(['financial_intent' => [
        'provider_transaction_id' => $provider->id,
        'allocation_date' => now('Asia/Qatar')->toDateString(),
        'invoice_issue_date' => now('Asia/Qatar')->toDateString(),
    ]]);
    app(PaymentOperationsTrackingService::class)->recordProcessingFailure($recoverable->id, 'FINANCIAL_PERIOD_BLOCKED');

    $staff = User::factory()->create(['status' => 'active']);
    $staff->givePermissionTo(['payments.support.view', 'payments.support.recover']);
    DB::table('user_branch_access')->insert([
        'user_id' => $staff->id,
        'branch_id' => $this->branch->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    expect(app(PaymentOperationsRecoveryService::class)
        ->retry($recoverable->id, (string) Str::uuid(), $staff)['state'])->toBe('queued');
    Queue::assertPushed(RetrySkipCashPaymentProcessing::class, 1);

    $this->artisan('payments:recover-skipcash')->assertSuccessful();
    $this->artisan('payments:purge-skipcash-provider-bodies')->assertSuccessful();
    $health = app(PaymentOperationsHealthService::class)->summary($staff);

    expect($health['collection_enabled'])->toBeFalse()
        ->and($health['recovery']['freshness'])->toBe('healthy')
        ->and($health['purge']['freshness'])->toBe('healthy')
        ->and($health['recovery']['last_result'])->toBe('succeeded');

    $this->actingAs($staff)
        ->get(route('receivables.payments.skipcash.index'))
        ->assertOk()
        ->assertSee('Operations health')
        ->assertSee('New collection disabled')
        ->assertSee($recoverable->reference);

    Carbon::setTestNow(now('UTC')->addHours(27));
    try {
        $stale = app(PaymentOperationsHealthService::class)->summary($staff);
        expect($stale['recovery']['freshness'])->toBe('stale')
            ->and($stale['purge']['freshness'])->toBe('stale');
    } finally {
        Carbon::setTestNow();
    }
});
