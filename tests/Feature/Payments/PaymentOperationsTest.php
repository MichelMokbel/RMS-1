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
use App\Models\PaymentProviderTransaction;
use App\Models\PaymentSource;
use App\Models\User;
use App\Services\Payments\PaymentOperationsRecoveryService;
use App\Services\Payments\PaymentOperationsTrackingService;
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
    Queue::assertPushed(SendPaymentOperationsAlert::class, 1);
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
        Queue::assertPushed(SendPaymentOperationsAlert::class, 1);
        expect(data_get($attempt->fresh()->operations_tracking, 'issues.processing.alert.state'))->toBe('pending');
    } finally {
        Carbon::setTestNow();
    }
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
});
