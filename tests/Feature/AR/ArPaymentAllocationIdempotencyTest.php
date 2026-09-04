<?php

use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Services\AR\ArInvoiceService;
use App\Services\AR\ArPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin');
    Role::findOrCreate('manager');
    Permission::findOrCreate('payments.credit.allocate', 'web');
    Permission::findOrCreate('finance.write', 'web');
    $this->user = User::factory()->create();
    $this->user->assignRole('manager');
});

it('blocks the legacy direct saved credit writer', function () {
    $customer = Customer::factory()->corporate()->create();

    /** @var ArInvoiceService $invoiceSvc */
    $invoiceSvc = app(ArInvoiceService::class);
    $invoice = $invoiceSvc->createDraft(
        branchId: 1,
        customerId: $customer->id,
        items: [
            ['description' => 'Service', 'qty' => '1.000', 'unit_price_cents' => 10000, 'discount_cents' => 0, 'tax_cents' => 0, 'line_total_cents' => 10000],
        ],
        actorId: $this->user->id,
    );
    $invoice = $invoiceSvc->issue($invoice, $this->user->id);

    $payment = Payment::factory()->create([
        'customer_id' => $customer->id,
        'branch_id' => 1,
        'source' => 'ar',
        'amount_cents' => 10000,
        'currency' => $invoice->currency,
    ]);

    /** @var ArPaymentService $svc */
    $svc = app(ArPaymentService::class);

    expect(fn () => $svc->applyExistingPaymentToInvoice($payment->id, $invoice->id, 10000, $this->user->id))
        ->toThrow(ValidationException::class)
        ->and(PaymentAllocation::query()
            ->where('payment_id', $payment->id)
            ->where('allocatable_id', $invoice->id)
            ->whereNull('voided_at')
            ->count()
        )->toBe(0);
});

it('applyExistingPaymentAllocations is idempotent when called twice with the same rows', function () {
    $customer = Customer::factory()->corporate()->create();

    /** @var ArInvoiceService $invoiceSvc */
    $invoiceSvc = app(ArInvoiceService::class);

    $inv1 = $invoiceSvc->createDraft(
        branchId: 1,
        customerId: $customer->id,
        items: [
            ['description' => 'Item 1', 'qty' => '1.000', 'unit_price_cents' => 5000, 'discount_cents' => 0, 'tax_cents' => 0, 'line_total_cents' => 5000],
        ],
        actorId: $this->user->id,
    );
    $inv1 = $invoiceSvc->issue($inv1, $this->user->id);

    $inv2 = $invoiceSvc->createDraft(
        branchId: 1,
        customerId: $customer->id,
        items: [
            ['description' => 'Item 2', 'qty' => '1.000', 'unit_price_cents' => 3000, 'discount_cents' => 0, 'tax_cents' => 0, 'line_total_cents' => 3000],
        ],
        actorId: $this->user->id,
    );
    $inv2 = $invoiceSvc->issue($inv2, $this->user->id);

    $payment = Payment::factory()->create([
        'customer_id' => $customer->id,
        'branch_id' => 1,
        'source' => 'ar',
        'amount_cents' => 10000,
        'currency' => $inv1->currency,
    ]);

    $rows = [
        ['invoice_id' => $inv1->id, 'amount_cents' => 5000],
        ['invoice_id' => $inv2->id, 'amount_cents' => 3000],
    ];

    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');
    $admin->givePermissionTo(['payments.credit.allocate', 'finance.write']);

    /** @var ArPaymentService $svc */
    $svc = app(ArPaymentService::class);
    $operationUuid = (string) Str::uuid();

    $svc->applyExistingPaymentAllocations($payment->id, $rows, $admin->id, operationUuid: $operationUuid);
    $svc->applyExistingPaymentAllocations($payment->id, $rows, $admin->id, operationUuid: $operationUuid);

    expect(PaymentAllocation::query()
        ->where('payment_id', $payment->id)
        ->whereNull('voided_at')
        ->count()
    )->toBe(2);
});
