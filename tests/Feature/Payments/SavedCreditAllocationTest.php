<?php

use App\Models\AccountingAuditLog;
use App\Models\AccountingCompany;
use App\Models\ArInvoice;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\MealSubscription;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentConsistencyFinding;
use App\Models\User;
use App\Services\AR\ArAllocationService;
use App\Services\AR\ArInvoiceService;
use App\Services\AR\ArPaymentDeleteService;
use App\Services\Finance\FinanceSettingsService;
use App\Services\Payments\PaymentConsistencyService;
use App\Services\Payments\PaymentCreditProjectionService;
use App\Services\Payments\SavedCreditAllocationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach (['payments.credit.allocate', 'finance.write'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('manager', 'web');
    $this->company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $this->branch = Branch::query()->findOrFail(1);
    $this->branch->update(['company_id' => $this->company->id, 'is_active' => true]);
    $this->customer = Customer::factory()->corporate()->create();
    $this->admin = User::factory()->create(['status' => 'active']);
    $this->admin->assignRole('admin');
    $this->admin->givePermissionTo(['payments.credit.allocate', 'finance.write']);

    $this->issueInvoice = function (int $amountCents, array $meta = []): ArInvoice {
        $service = app(ArInvoiceService::class);
        $invoice = $service->createDraft(
            branchId: $this->branch->id,
            customerId: $this->customer->id,
            items: [[
                'description' => 'Customer order',
                'qty' => '1.000',
                'unit_price_cents' => $amountCents,
                'discount_cents' => 0,
                'tax_cents' => 0,
                'line_total_cents' => $amountCents,
                'meta' => $meta,
            ]],
            actorId: $this->admin->id,
            paymentType: 'credit',
        );

        return $service->issue($invoice, $this->admin->id);
    };

    $this->makePayment = function (int $amountCents = 10000): Payment {
        return Payment::factory()->create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'source' => 'ar',
            'method' => 'bank_transfer',
            'amount_cents' => $amountCents,
            'currency' => 'QAR',
            'created_by' => $this->admin->id,
        ]);
    };
});

it('allocates discretionary saved credit once and exact replay does not reapply a later removed allocation', function (): void {
    $invoice = ($this->issueInvoice)(6000);
    $payment = ($this->makePayment)(10000);
    $operationUuid = (string) Str::uuid();
    $service = app(SavedCreditAllocationService::class);

    $first = $service->allocate($payment->id, [[
        'invoice_id' => $invoice->id,
        'amount_cents' => 6000,
    ]], $this->admin, $operationUuid);
    $replay = $service->allocate($payment->id, [[
        'invoice_id' => $invoice->id,
        'amount_cents' => 6000,
    ]], $this->admin, $operationUuid);

    expect($first['state'])->toBe('completed')
        ->and($replay['allocation_ids'])->toBe($first['allocation_ids'])
        ->and(PaymentAllocation::query()->where('payment_id', $payment->id)->whereNull('voided_at')->count())->toBe(1)
        ->and($payment->fresh()->unallocatedCents())->toBe(4000)
        ->and($invoice->fresh()->status)->toBe('paid')
        ->and(AccountingAuditLog::query()->where('action', 'payment.saved_credit_allocation.accepted')->count())->toBe(1)
        ->and(AccountingAuditLog::query()->where('action', 'payment.saved_credit_allocation.completed')->count())->toBe(1);
    expect(app(PaymentConsistencyService::class)->check(
        'saved_credit_v1',
        'payment',
        $payment->id,
        triggerKey: 'test:saved-credit:admin-allocation',
    )->open_count)->toBe(0);

    $allocation = PaymentAllocation::query()->whereKey($first['allocation_ids'][0])->firstOrFail();
    app(ArPaymentDeleteService::class)->removeAllocation($allocation, $this->admin->id);
    $afterCorrectionReplay = $service->allocate($payment->id, [[
        'invoice_id' => $invoice->id,
        'amount_cents' => 6000,
    ]], $this->admin, $operationUuid);

    expect($afterCorrectionReplay['state'])->toBe('completed')
        ->and(PaymentAllocation::query()->where('payment_id', $payment->id)->whereNull('voided_at')->count())->toBe(0)
        ->and(PaymentAllocation::query()->where('payment_id', $payment->id)->count())->toBe(1)
        ->and($invoice->fresh()->status)->toBe('issued');
});

it('reports a discretionary allocation that has no completed administrator audit', function (): void {
    $invoice = ($this->issueInvoice)(5000);
    $payment = ($this->makePayment)(10000);
    $allocation = PaymentAllocation::query()->create([
        'payment_id' => $payment->id,
        'allocatable_type' => ArInvoice::class,
        'allocatable_id' => $invoice->id,
        'amount_cents' => 5000,
    ]);

    $run = app(PaymentConsistencyService::class)->check(
        'saved_credit_v1',
        'payment',
        $payment->id,
        triggerKey: 'test:saved-credit:missing-admin-audit',
    );
    $finding = PaymentConsistencyFinding::query()
        ->where('rule_code', 'saved_credit_v1')
        ->where('subject_type', 'payment')
        ->where('subject_id', $payment->id)
        ->firstOrFail();

    expect($run->open_count)->toBe(1)
        ->and(collect($finding->observed['issues'])->pluck('code')->all())
        ->toContain('CREDIT_ALLOCATION_ADMIN_AUDIT_MISSING')
        ->and($finding->observed['unaudited_allocation_ids'])->toBe([$allocation->id]);
});

it('accepts an allocation created as part of the original AR receipt workflow', function (): void {
    $invoice = ($this->issueInvoice)(5000);
    $result = app(ArAllocationService::class)->createPaymentAndAllocate([
        'invoice_id' => $invoice->id,
        'amount_cents' => 5000,
        'method' => 'cash',
        'currency' => 'QAR',
    ], $this->admin->id);

    $payment = $result['payment'];
    $run = app(PaymentConsistencyService::class)->check(
        'saved_credit_v1',
        'payment',
        $payment->id,
        triggerKey: 'test:saved-credit:initial-receipt',
    );

    $audit = AccountingAuditLog::query()
        ->where('action', 'ar_payment.created')
        ->where('subject_id', $payment->id)
        ->firstOrFail();
    expect($run->open_count)->toBe(0)
        ->and($audit->payload['allocation_ids'])->toHaveCount(1);
});

it('treats zero value vouchers and voided receipts as outside saved credit availability', function (): void {
    $voucher = ($this->makePayment)(0);
    DB::table('payments')->where('id', $voucher->id)->update([
        'company_id' => null,
        'method' => 'voucher',
    ]);
    $voided = ($this->makePayment)(5000);
    DB::table('payments')->where('id', $voided->id)->update([
        'voided_at' => now(),
        'voided_by' => $this->admin->id,
    ]);
    $consistency = app(PaymentConsistencyService::class);

    expect($consistency->check(
        'saved_credit_v1',
        'payment',
        $voucher->id,
        triggerKey: 'test:saved-credit:voucher-not-applicable',
    )->open_count)->toBe(0)
        ->and($consistency->check(
            'saved_credit_v1',
            'payment',
            $voided->id,
            triggerKey: 'test:saved-credit:voided-not-applicable',
        )->open_count)->toBe(0);
});

it('requires the administrator role and both saved credit and finance permissions', function (): void {
    $invoice = ($this->issueInvoice)(5000);
    $payment = ($this->makePayment)(5000);
    $manager = User::factory()->create(['status' => 'active']);
    $manager->assignRole('manager');
    $manager->givePermissionTo(['payments.credit.allocate', 'finance.write']);
    Role::findByName('admin', 'web')->revokePermissionTo('payments.credit.allocate');
    $adminWithoutCredit = User::factory()->create(['status' => 'active']);
    $adminWithoutCredit->assignRole('admin');
    $adminWithoutCredit->givePermissionTo('finance.write');
    $service = app(SavedCreditAllocationService::class);
    $rows = [['invoice_id' => $invoice->id, 'amount_cents' => 5000]];

    expect(fn () => $service->allocate($payment->id, $rows, $manager, (string) Str::uuid()))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => $service->allocate($payment->id, $rows, $adminWithoutCredit, (string) Str::uuid()))
        ->toThrow(AuthorizationException::class)
        ->and(PaymentAllocation::query()->where('payment_id', $payment->id)->exists())->toBeFalse();
});

it('keeps active membership funding committed and automatically uses it only for that membership invoice', function (): void {
    $payment = ($this->makePayment)(90000);
    $subscription = MealSubscription::factory()->create([
        'customer_id' => $this->customer->id,
        'branch_id' => $this->branch->id,
        'status' => 'active',
        'plan_meals_total' => 20,
        'meals_used' => 0,
        'source_payment_id' => $payment->id,
        'uses_invoice_tracking' => true,
    ]);
    $unrelated = ($this->issueInvoice)(5000);

    $projection = app(PaymentCreditProjectionService::class)->project($payment->fresh());
    expect($projection['state'])->toBe('committed')
        ->and($projection['committed_cents'])->toBe(90000)
        ->and($projection['available_cents'])->toBe(0)
        ->and($unrelated->fresh()->status)->toBe('issued')
        ->and(PaymentAllocation::query()->where('payment_id', $payment->id)->count())->toBe(0)
        ->and(fn () => app(SavedCreditAllocationService::class)->allocate($payment->id, [[
            'invoice_id' => $unrelated->id,
            'amount_cents' => 5000,
        ]], $this->admin, (string) Str::uuid()))->toThrow(ValidationException::class);

    $membershipInvoice = ($this->issueInvoice)(4500, [
        'is_subscription' => true,
        'subscription_id' => $subscription->id,
    ]);

    expect($membershipInvoice->fresh()->status)->toBe('paid')
        ->and(PaymentAllocation::query()
            ->where('payment_id', $payment->id)
            ->where('allocatable_id', $membershipInvoice->id)
            ->whereNull('voided_at')
            ->value('amount_cents'))->toBe(4500)
        ->and($payment->fresh()->unallocatedCents())->toBe(85500);
});

it('releases cancelled membership funding as saved credit without creating a refund', function (): void {
    $invoice = ($this->issueInvoice)(10000);
    $payment = ($this->makePayment)(90000);
    $subscription = MealSubscription::factory()->create([
        'customer_id' => $this->customer->id,
        'branch_id' => $this->branch->id,
        'status' => 'active',
        'plan_meals_total' => 20,
        'meals_used' => 4,
        'source_payment_id' => $payment->id,
        'uses_invoice_tracking' => true,
    ]);

    expect(app(PaymentCreditProjectionService::class)->project($payment)['available_cents'])->toBe(0);
    $subscription->update(['status' => 'cancelled']);
    expect(app(PaymentCreditProjectionService::class)->project($payment->fresh())['available_cents'])->toBe(90000);

    app(SavedCreditAllocationService::class)->allocate($payment->id, [[
        'invoice_id' => $invoice->id,
        'amount_cents' => 10000,
    ]], $this->admin, (string) Str::uuid());

    expect($payment->fresh()->unallocatedCents())->toBe(80000)
        ->and($payment->fresh()->voided_at)->toBeNull()
        ->and(Payment::query()->count())->toBe(1);
});

it('blocks saved credit allocation in a locked accounting date before any financial mutation', function (): void {
    $invoice = ($this->issueInvoice)(5000);
    $payment = ($this->makePayment)(5000);
    app(FinanceSettingsService::class)->setLockDate(now('Asia/Qatar')->toDateString(), $this->admin->id);

    expect(fn () => app(SavedCreditAllocationService::class)->allocate($payment->id, [[
        'invoice_id' => $invoice->id,
        'amount_cents' => 5000,
    ]], $this->admin, (string) Str::uuid()))->toThrow(ValidationException::class)
        ->and(PaymentAllocation::query()->where('payment_id', $payment->id)->exists())->toBeFalse()
        ->and(AccountingAuditLog::query()->where('action', 'payment.saved_credit_allocation.accepted')->exists())->toBeFalse();
});
