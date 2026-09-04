<?php

use App\Jobs\RetrySkipCashPaymentProcessing;
use App\Jobs\SendPaymentOperationsAlert;
use App\Models\AccountingAuditLog;
use App\Models\AccountingCompany;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\EmailLog;
use App\Models\LedgerAccount;
use App\Models\Payment;
use App\Models\PaymentCheckoutAttempt;
use App\Models\PaymentProviderEvent;
use App\Models\PaymentProviderTransaction;
use App\Models\PaymentSource;
use App\Models\User;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\Payments\PaymentOperationsEvidenceService;
use App\Services\Payments\PaymentOperationsQueryService;
use App\Services\Payments\PaymentOperationsRecoveryService;
use App\Services\Payments\PaymentOperationsTrackingService;
use App\Services\Payments\SkipCashRecoveryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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
    $attempt = ($this->makeAttempt)();
    $service = app(PaymentOperationsTrackingService::class);

    $service->recordProcessingFailure($attempt->id, 'FINANCIAL_PERIOD_BLOCKED');
    $service->recordProcessingFailure($attempt->id, 'FINANCIAL_PERIOD_BLOCKED');

    $attempt->refresh();
    $issue = $attempt->operations_tracking['issues']['processing'];
    expect($issue['reason_code'])->toBe('FINANCIAL_PERIOD_BLOCKED')
        ->and($issue['resolved_at'])->toBeNull()
        ->and($issue['alert']['state'])->toBe('pending')
        ->and($attempt->notification_snapshots['operations_alerts'][$issue['episode_uuid']]['admin_emails'])->toBe(['ops@example.test'])
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
