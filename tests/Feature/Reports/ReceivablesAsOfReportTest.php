<?php

use App\Models\ArInvoice;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('manager', 'web');
});

function makeReceivablesAsOfManager(): User
{
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('manager');

    return $user;
}

function createReceivablesAsOfInvoice(array $attributes = []): ArInvoice
{
    return ArInvoice::factory()->create(array_merge([
        'branch_id' => 1,
        'type' => 'invoice',
        'status' => 'issued',
        'payment_type' => 'credit',
        'issue_date' => '2026-04-01',
        'due_date' => '2026-04-30',
        'total_cents' => 100000,
        'paid_total_cents' => 0,
        'balance_cents' => 100000,
    ], $attributes));
}

function allocateReceivablesAsOfPayment(ArInvoice $invoice, int $amountCents, string $receivedAt): Payment
{
    $payment = Payment::factory()->create([
        'branch_id' => $invoice->branch_id,
        'customer_id' => $invoice->customer_id,
        'source' => 'ar',
        'method' => 'bank',
        'amount_cents' => $amountCents,
        'received_at' => $receivedAt,
    ]);

    PaymentAllocation::query()->create([
        'payment_id' => $payment->id,
        'allocatable_type' => ArInvoice::class,
        'allocatable_id' => $invoice->id,
        'amount_cents' => $amountCents,
        'voided_at' => null,
        'voided_by' => null,
        'void_reason' => null,
    ]);

    return $payment;
}

it('shows receivables as of in the accounts reports list', function () {
    $user = makeReceivablesAsOfManager();

    $this->actingAs($user)
        ->get(route('reports.index', ['category' => 'accounts']))
        ->assertOk()
        ->assertSee('Receivables As Of');
});

it('shows invoices paid after the as-of date as still unpaid at month end', function () {
    $user = makeReceivablesAsOfManager();
    $invoice = createReceivablesAsOfInvoice([
        'invoice_number' => 'ASOF-PAID-MAY',
        'status' => 'paid',
        'paid_total_cents' => 100000,
        'balance_cents' => 0,
    ]);

    allocateReceivablesAsOfPayment($invoice, 100000, '2026-05-05 09:00:00');

    $this->actingAs($user)
        ->get(route('reports.receivables-as-of', ['as_of_date' => '2026-04-30']))
        ->assertOk()
        ->assertSee('Receivables As Of')
        ->assertSee('ASOF-PAID-MAY')
        ->assertSee('1000.00');
});

it('only counts payments received on or before the as-of date', function () {
    $user = makeReceivablesAsOfManager();
    $invoice = createReceivablesAsOfInvoice([
        'invoice_number' => 'ASOF-PARTIAL',
        'status' => 'paid',
        'total_cents' => 100000,
        'paid_total_cents' => 100000,
        'balance_cents' => 0,
    ]);

    allocateReceivablesAsOfPayment($invoice, 30000, '2026-04-20 10:00:00');
    allocateReceivablesAsOfPayment($invoice, 70000, '2026-05-03 10:00:00');

    $response = $this->actingAs($user)
        ->get(route('reports.receivables-as-of.print', ['as_of_date' => '2026-04-30']))
        ->assertOk();

    $response
        ->assertSee('ASOF-PARTIAL')
        ->assertSee('300.00')
        ->assertSee('700.00');
});

it('excludes invoices fully paid before the as-of date', function () {
    $user = makeReceivablesAsOfManager();
    $openAtCutoff = createReceivablesAsOfInvoice([
        'invoice_number' => 'ASOF-OPEN',
        'total_cents' => 100000,
        'balance_cents' => 100000,
    ]);
    $paidBeforeCutoff = createReceivablesAsOfInvoice([
        'invoice_number' => 'ASOF-PAID-BEFORE',
        'status' => 'paid',
        'total_cents' => 50000,
        'paid_total_cents' => 50000,
        'balance_cents' => 0,
    ]);

    allocateReceivablesAsOfPayment($paidBeforeCutoff, 50000, '2026-04-15 10:00:00');

    $this->actingAs($user)
        ->get(route('reports.receivables-as-of', ['as_of_date' => '2026-04-30']))
        ->assertOk()
        ->assertSee('ASOF-OPEN')
        ->assertDontSee('ASOF-PAID-BEFORE');
});

it('filters receivables as of by branch and customer', function () {
    $user = makeReceivablesAsOfManager();
    $user->assignRole(Role::findOrCreate('admin', 'web'));
    $visibleCustomer = Customer::factory()->create(['name' => 'As Of Visible Customer']);
    $hiddenCustomer = Customer::factory()->create(['name' => 'As Of Hidden Customer']);

    $visible = createReceivablesAsOfInvoice([
        'customer_id' => $visibleCustomer->id,
        'branch_id' => 2,
        'invoice_number' => 'ASOF-FILTER-VISIBLE',
    ]);
    $hiddenByCustomer = createReceivablesAsOfInvoice([
        'customer_id' => $hiddenCustomer->id,
        'branch_id' => 2,
        'invoice_number' => 'ASOF-FILTER-CUSTOMER',
    ]);
    $hiddenByBranch = createReceivablesAsOfInvoice([
        'customer_id' => $visibleCustomer->id,
        'branch_id' => 1,
        'invoice_number' => 'ASOF-FILTER-BRANCH',
    ]);

    $this->actingAs($user)
        ->get(route('reports.receivables-as-of', [
            'as_of_date' => '2026-04-30',
            'branch_id' => 2,
            'customer_id' => $visibleCustomer->id,
        ]))
        ->assertOk()
        ->assertSee($visible->invoice_number)
        ->assertDontSee($hiddenByCustomer->invoice_number)
        ->assertDontSee($hiddenByBranch->invoice_number);
});

it('exports receivables as of data through csv and pdf routes', function () {
    $user = makeReceivablesAsOfManager();
    $invoice = createReceivablesAsOfInvoice([
        'invoice_number' => 'ASOF-EXPORT',
    ]);

    $csv = $this->actingAs($user)
        ->get(route('reports.receivables-as-of.csv', ['as_of_date' => '2026-04-30']));

    $csv->assertOk();
    expect($csv->streamedContent())->toContain('ASOF-EXPORT');

    $this->actingAs($user)
        ->get(route('reports.receivables-as-of.pdf', ['as_of_date' => '2026-04-30']))
        ->assertOk();
});
