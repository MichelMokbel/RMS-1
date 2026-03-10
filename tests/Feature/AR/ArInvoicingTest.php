<?php

use App\Models\ArInvoice;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\User;
use App\Services\AR\ArAllocationService;
use App\Services\AR\ArInvoiceService;
use App\Services\AR\ArPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin');
    Role::findOrCreate('manager');
    Role::findOrCreate('cashier');
});

it('issues invoices with one global INV sequence across branches', function () {
    Carbon::setTestNow(Carbon::create(2026, 1, 29, 12, 0, 0));

    $user = User::factory()->create();
    $user->assignRole('manager');

    $customer = Customer::factory()->corporate()->create(['credit_terms_days' => 30]);

    ArInvoice::factory()->create([
        'branch_id' => 1,
        'customer_id' => $customer->id,
        'type' => 'invoice',
        'status' => 'issued',
        'invoice_number' => 'INV7819',
        'issue_date' => now()->toDateString(),
        'due_date' => now()->toDateString(),
    ]);
    ArInvoice::factory()->create([
        'branch_id' => 2,
        'customer_id' => $customer->id,
        'type' => 'invoice',
        'status' => 'issued',
        'invoice_number' => 'INV7892',
        'issue_date' => now()->toDateString(),
        'due_date' => now()->toDateString(),
    ]);

    /** @var ArInvoiceService $svc */
    $svc = app(ArInvoiceService::class);

    $a = $svc->createDraft(
        branchId: 1,
        customerId: $customer->id,
        items: [
            ['description' => 'Item A', 'qty' => '1.000', 'unit_price_cents' => 10000, 'discount_cents' => 0, 'tax_cents' => 0, 'line_total_cents' => 10000],
        ],
        actorId: $user->id,
    );
    $a = $svc->issue($a, $user->id);

    $b = $svc->createDraft(
        branchId: 2,
        customerId: $customer->id,
        items: [
            ['description' => 'Item B', 'qty' => '1.000', 'unit_price_cents' => 5000, 'discount_cents' => 0, 'tax_cents' => 0, 'line_total_cents' => 5000],
        ],
        actorId: $user->id,
    );
    $b = $svc->issue($b, $user->id);

    expect($a->invoice_number)->toBe('INV7893');
    expect($b->invoice_number)->toBe('INV7894');
});

it('applies partial and full payments and updates invoice balance/status', function () {
    $user = User::factory()->create();
    $user->assignRole('manager');

    $customer = Customer::factory()->corporate()->create();

    /** @var ArInvoiceService $invoices */
    $invoices = app(ArInvoiceService::class);
    $inv = $invoices->createDraft(
        branchId: 1,
        customerId: $customer->id,
        items: [
            ['description' => 'Service', 'qty' => '1.000', 'unit_price_cents' => 10000, 'discount_cents' => 0, 'tax_cents' => 0, 'line_total_cents' => 10000],
        ],
        actorId: $user->id,
    );
    $inv = $invoices->issue($inv, $user->id);

    /** @var ArAllocationService $alloc */
    $alloc = app(ArAllocationService::class);

    $alloc->createPaymentAndAllocate([
        'invoice_id' => $inv->id,
        'amount_cents' => 4000,
        'method' => 'bank',
    ], $user->id);

    $inv = ArInvoice::findOrFail($inv->id);
    expect($inv->status)->toBe('partially_paid');
    expect($inv->balance_cents)->toBe(6000);

    $alloc->createPaymentAndAllocate([
        'invoice_id' => $inv->id,
        'amount_cents' => 6000,
        'method' => 'bank',
    ], $user->id);

    $inv = ArInvoice::findOrFail($inv->id);
    expect($inv->status)->toBe('paid');
    expect($inv->balance_cents)->toBe(0);
});

it('creates and applies a credit note to reduce invoice balance', function () {
    $user = User::factory()->create();
    $user->assignRole('manager');

    $customer = Customer::factory()->corporate()->create();

    /** @var ArInvoiceService $invoices */
    $invoices = app(ArInvoiceService::class);

    $inv = $invoices->createDraft(
        branchId: 1,
        customerId: $customer->id,
        items: [
            ['description' => 'Service', 'qty' => '1.000', 'unit_price_cents' => 10000, 'discount_cents' => 0, 'tax_cents' => 0, 'line_total_cents' => 10000],
        ],
        actorId: $user->id,
    );
    $inv = $invoices->issue($inv, $user->id);

    $credit = $invoices->createDraft(
        branchId: 1,
        customerId: $customer->id,
        items: [
            ['description' => 'Return', 'qty' => '1.000', 'unit_price_cents' => -3000, 'discount_cents' => 0, 'tax_cents' => 0, 'line_total_cents' => -3000],
        ],
        actorId: $user->id,
        type: 'credit_note'
    );
    $credit = $invoices->issue($credit, $user->id);

    /** @var ArAllocationService $alloc */
    $alloc = app(ArAllocationService::class);
    $alloc->applyCreditNote($credit, $inv, $user->id);

    $inv = ArInvoice::findOrFail($inv->id);
    $credit = ArInvoice::findOrFail($credit->id);

    expect($inv->balance_cents)->toBe(7000);
    expect($credit->balance_cents)->toBe(0);
});

it('allows overpayment and stores remainder as advance', function () {
    $user = User::factory()->create();
    $user->assignRole('manager');

    $customer = Customer::factory()->corporate()->create();

    /** @var ArInvoiceService $invoices */
    $invoices = app(ArInvoiceService::class);
    $inv = $invoices->createDraft(
        branchId: 1,
        customerId: $customer->id,
        items: [
            ['description' => 'Service', 'qty' => '1.000', 'unit_price_cents' => 10000, 'discount_cents' => 0, 'tax_cents' => 0, 'line_total_cents' => 10000],
        ],
        actorId: $user->id,
    );
    $inv = $invoices->issue($inv, $user->id);

    /** @var ArAllocationService $alloc */
    $alloc = app(ArAllocationService::class);

    $result = $alloc->createPaymentAndAllocate([
        'invoice_id' => $inv->id,
        'amount_cents' => 12000,
        'method' => 'bank',
    ], $user->id);

    expect($result['allocated_cents'])->toBe(10000);
    expect($result['remainder_cents'])->toBe(2000);

    $inv = ArInvoice::findOrFail($inv->id);
    expect($inv->status)->toBe('paid');
    expect($inv->balance_cents)->toBe(0);

    /** @var Payment $payment */
    $payment = $result['payment'];
    expect($payment->allocatedCents())->toBe(10000);
    expect($payment->unallocatedCents())->toBe(2000);
});

it('creates advance payment and applies it later', function () {
    $user = User::factory()->create();
    $user->assignRole('manager');

    $customer = Customer::factory()->corporate()->create();

    /** @var ArInvoiceService $invoices */
    $invoices = app(ArInvoiceService::class);
    $inv = $invoices->createDraft(
        branchId: 1,
        customerId: $customer->id,
        items: [
            ['description' => 'Service', 'qty' => '1.000', 'unit_price_cents' => 8000, 'discount_cents' => 0, 'tax_cents' => 0, 'line_total_cents' => 8000],
        ],
        actorId: $user->id,
    );
    $inv = $invoices->issue($inv, $user->id);

    /** @var ArPaymentService $payments */
    $payments = app(ArPaymentService::class);
    $payment = $payments->createAdvancePayment(
        customerId: $customer->id,
        branchId: 1,
        amountCents: 5000,
        method: 'bank',
        receivedAt: now()->toDateTimeString(),
        reference: null,
        notes: null,
        actorId: $user->id
    );

    expect($payment->allocations()->count())->toBe(0);
    expect($payment->unallocatedCents())->toBe(5000);

    $payments->applyExistingPaymentToInvoice($payment->id, $inv->id, 3000, $user->id);

    $inv = ArInvoice::findOrFail($inv->id);
    expect($inv->balance_cents)->toBe(5000);

    $payment = Payment::findOrFail($payment->id);
    expect($payment->unallocatedCents())->toBe(2000);
});

it('auto-allocates available customer advance when issuing a credit invoice', function () {
    $user = User::factory()->create();
    $user->assignRole('manager');

    $customer = Customer::factory()->corporate()->create();

    /** @var ArPaymentService $payments */
    $payments = app(ArPaymentService::class);
    $advance = $payments->createAdvancePayment(
        customerId: $customer->id,
        branchId: 1,
        amountCents: 5000,
        method: 'bank',
        receivedAt: now()->toDateTimeString(),
        reference: null,
        notes: null,
        actorId: $user->id
    );

    /** @var ArInvoiceService $invoices */
    $invoices = app(ArInvoiceService::class);
    $invoice = $invoices->createDraft(
        branchId: 1,
        customerId: $customer->id,
        items: [
            ['description' => 'Service', 'qty' => '1.000', 'unit_price_cents' => 8000, 'discount_cents' => 0, 'tax_cents' => 0, 'line_total_cents' => 8000],
        ],
        actorId: $user->id,
        paymentType: 'credit',
    );
    $invoice = $invoices->issue($invoice, $user->id);

    expect($invoice->status)->toBe('partially_paid');
    expect($invoice->paid_total_cents)->toBe(5000);
    expect($invoice->balance_cents)->toBe(3000);

    $advance = Payment::findOrFail($advance->id);
    expect($advance->unallocatedCents())->toBe(0);
});

it('does not auto-allocate advance when invoice payment type is not credit', function () {
    $user = User::factory()->create();
    $user->assignRole('manager');

    $customer = Customer::factory()->corporate()->create();

    /** @var ArPaymentService $payments */
    $payments = app(ArPaymentService::class);
    $advance = $payments->createAdvancePayment(
        customerId: $customer->id,
        branchId: 1,
        amountCents: 5000,
        method: 'bank',
        receivedAt: now()->toDateTimeString(),
        reference: null,
        notes: null,
        actorId: $user->id
    );

    /** @var ArInvoiceService $invoices */
    $invoices = app(ArInvoiceService::class);
    $invoice = $invoices->createDraft(
        branchId: 1,
        customerId: $customer->id,
        items: [
            ['description' => 'Service', 'qty' => '1.000', 'unit_price_cents' => 8000, 'discount_cents' => 0, 'tax_cents' => 0, 'line_total_cents' => 8000],
        ],
        actorId: $user->id,
        paymentType: 'cash',
    );
    $invoice = $invoices->issue($invoice, $user->id);

    expect($invoice->status)->toBe('issued');
    expect($invoice->paid_total_cents)->toBe(0);
    expect($invoice->balance_cents)->toBe(8000);

    $advance = Payment::findOrFail($advance->id);
    expect($advance->unallocatedCents())->toBe(5000);
});

it('uses QAR as default currency for payments and invoices', function () {
    $invoice = ArInvoice::factory()->create();
    $payment = Payment::factory()->create();

    expect($invoice->currency)->toBe(config('pos.currency'));
    expect($payment->currency)->toBe(config('pos.currency'));
});

it('voids an issued invoice when it has no allocations', function () {
    $user = User::factory()->create();
    $user->assignRole('manager');
    $customer = Customer::factory()->corporate()->create();

    /** @var ArInvoiceService $invoices */
    $invoices = app(ArInvoiceService::class);
    $invoice = $invoices->createDraft(
        branchId: 1,
        customerId: $customer->id,
        items: [
            ['description' => 'Service', 'qty' => '1.000', 'unit_price_cents' => 2500, 'discount_cents' => 0, 'tax_cents' => 0, 'line_total_cents' => 2500],
        ],
        actorId: $user->id,
    );
    $invoice = $invoices->issue($invoice, $user->id);

    $voided = $invoices->void($invoice, $user->id, 'Customer cancelled');

    expect($voided->status)->toBe('voided');
    expect($voided->voided_by)->toBe($user->id);
    expect($voided->void_reason)->toBe('Customer cancelled');
    expect($voided->voided_at)->not->toBeNull();
});

it('allows voiding a paid invoice by voiding its allocations', function () {
    $user = User::factory()->create();
    $user->assignRole('manager');
    $customer = Customer::factory()->corporate()->create();

    /** @var ArInvoiceService $invoices */
    $invoices = app(ArInvoiceService::class);
    $invoice = $invoices->createDraft(
        branchId: 1,
        customerId: $customer->id,
        items: [
            ['description' => 'Service', 'qty' => '1.000', 'unit_price_cents' => 5000, 'discount_cents' => 0, 'tax_cents' => 0, 'line_total_cents' => 5000],
        ],
        actorId: $user->id,
    );
    $invoice = $invoices->issue($invoice, $user->id);

    /** @var ArAllocationService $alloc */
    $alloc = app(ArAllocationService::class);
    $alloc->createPaymentAndAllocate([
        'invoice_id' => $invoice->id,
        'amount_cents' => 5000,
        'method' => 'bank',
    ], $user->id);

    $invoice = ArInvoice::query()->findOrFail($invoice->id);
    expect($invoice->status)->toBe('paid');

    $voided = $invoices->void($invoice, $user->id, 'Wrong items');
    expect($voided->status)->toBe('voided');
    expect((int) $voided->paymentAllocations()->count())->toBe(0);
    expect((int) $voided->paid_total_cents)->toBe(0);
    expect((int) $voided->balance_cents)->toBe((int) $voided->total_cents);

    $payment = Payment::query()->latest('id')->firstOrFail();
    expect((int) $payment->unallocatedCents())->toBe((int) $payment->amount_cents);
});

it('voids and duplicates an invoice with incrementing V suffix on issue', function () {
    $user = User::factory()->create();
    $user->assignRole('manager');
    $customer = Customer::factory()->corporate()->create();

    /** @var ArInvoiceService $invoices */
    $invoices = app(ArInvoiceService::class);
    $original = $invoices->createDraft(
        branchId: 1,
        customerId: $customer->id,
        items: [
            ['description' => 'Service', 'qty' => '1.000', 'unit_price_cents' => 10000, 'discount_cents' => 0, 'tax_cents' => 0, 'line_total_cents' => 10000],
        ],
        actorId: $user->id,
    );
    $original = $invoices->issue($original, $user->id);

    $baseNumber = (string) $original->invoice_number;

    /** @var ArAllocationService $alloc */
    $alloc = app(ArAllocationService::class);
    $alloc->createPaymentAndAllocate([
        'invoice_id' => $original->id,
        'amount_cents' => 10000,
        'method' => 'bank',
    ], $user->id);

    $duplicate1 = $invoices->voidAndDuplicate($original, $user->id, 'Correction');

    $original = ArInvoice::query()->findOrFail($original->id);
    expect($original->status)->toBe('voided');
    expect((int) $original->paymentAllocations()->count())->toBe(0);
    expect($duplicate1->status)->toBe('draft');
    expect((int) data_get($duplicate1->meta, 'duplicate_revision'))->toBe(1);

    $duplicate1 = $invoices->issue($duplicate1, $user->id);
    expect($duplicate1->invoice_number)->toBe($baseNumber.'V1');

    $duplicate2 = $invoices->voidAndDuplicate($duplicate1, $user->id, 'Second correction');
    expect($duplicate2->status)->toBe('draft');
    expect((int) data_get($duplicate2->meta, 'duplicate_revision'))->toBe(2);

    $duplicate2 = $invoices->issue($duplicate2, $user->id);
    expect($duplicate2->invoice_number)->toBe($baseNumber.'V2');
});

it('updates an existing draft invoice lines and header fields', function () {
    $user = User::factory()->create();
    $user->assignRole('manager');
    $customer = Customer::factory()->corporate()->create();

    /** @var ArInvoiceService $invoices */
    $invoices = app(ArInvoiceService::class);
    $draft = $invoices->createDraft(
        branchId: 1,
        customerId: $customer->id,
        items: [
            ['description' => 'Old line', 'qty' => '1.000', 'unit_price_cents' => 1000, 'discount_cents' => 0, 'tax_cents' => 0, 'line_total_cents' => 1000],
        ],
        actorId: $user->id,
    );

    $updated = $invoices->updateDraft($draft, [
        'branch_id' => 1,
        'customer_id' => $customer->id,
        'payment_type' => 'cash',
        'issue_date' => now()->toDateString(),
        'notes' => 'Edited draft',
        'invoice_discount_type' => 'fixed',
        'invoice_discount_value' => 0,
        'items' => [
            ['description' => 'New line', 'qty' => '2.000', 'unit_price_cents' => 1500, 'discount_cents' => 0, 'tax_cents' => 0, 'line_total_cents' => 3000],
        ],
    ], $user->id);

    expect($updated->status)->toBe('draft');
    expect($updated->notes)->toBe('Edited draft');
    expect($updated->items()->count())->toBe(1);
    expect((int) $updated->total_cents)->toBe(3000);
    expect((string) $updated->items()->first()->description)->toBe('New line');
});
