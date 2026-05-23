<?php

use App\Models\ArInvoice;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\User;
use Carbon\Carbon;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::findOrCreate('manager', 'web');
});

it('caps customer statement aging at today when the default report end date is in the future', function () {
    Carbon::setTestNow('2026-03-15 12:00:00');

    $customer = Customer::factory()->corporate()->create();

    ArInvoice::factory()->create([
        'customer_id' => $customer->id,
        'type' => 'invoice',
        'status' => 'issued',
        'invoice_number' => '100476',
        'payment_type' => 'credit',
        'issue_date' => '2026-01-31',
        'due_date' => '2026-01-31',
        'total_cents' => 1449000,
        'paid_total_cents' => 0,
        'balance_cents' => 1449000,
    ]);

    ArInvoice::factory()->create([
        'customer_id' => $customer->id,
        'type' => 'invoice',
        'status' => 'issued',
        'invoice_number' => '100751',
        'payment_type' => 'credit',
        'issue_date' => '2026-02-28',
        'due_date' => '2026-02-28',
        'total_cents' => 1069500,
        'paid_total_cents' => 0,
        'balance_cents' => 1069500,
    ]);

    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('manager');

    $response = $this->actingAs($user)->get(route('reports.customer-statement.print', [
        'customer_id' => $customer->id,
        'date_from' => '2026-01-01',
    ]));

    $response->assertOk();
    $response->assertSee('43 Days');
    $response->assertSee('15 Days');
    $response->assertDontSee('59 Days');
    $response->assertDontSee('31 Days');

    Carbon::setTestNow();
});

it('filters fully paid invoices from the customer statement when only unpaid is enabled', function () {
    $customer = Customer::factory()->corporate()->create();

    ArInvoice::factory()->create([
        'customer_id' => $customer->id,
        'type' => 'invoice',
        'status' => 'paid',
        'invoice_number' => 'INV-PAID',
        'payment_type' => 'credit',
        'issue_date' => '2026-03-01',
        'due_date' => '2026-03-01',
        'total_cents' => 50000,
        'paid_total_cents' => 50000,
        'balance_cents' => 0,
    ]);

    ArInvoice::factory()->create([
        'customer_id' => $customer->id,
        'type' => 'invoice',
        'status' => 'partially_paid',
        'invoice_number' => 'INV-OPEN',
        'payment_type' => 'credit',
        'issue_date' => '2026-03-02',
        'due_date' => '2026-03-02',
        'total_cents' => 80000,
        'paid_total_cents' => 20000,
        'balance_cents' => 60000,
    ]);

    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('manager');

    $response = $this->actingAs($user)->get(route('reports.customer-statement.print', [
        'customer_id' => $customer->id,
        'date_from' => '2026-03-01',
        'date_to' => '2026-03-31',
        'only_unpaid' => 1,
    ]));

    $response->assertOk();
    $response->assertSee('INV-OPEN');
    $response->assertDontSee('INV-PAID');
});

it('hides payment rows from the customer statement when only unpaid is enabled', function () {
    $customer = Customer::factory()->corporate()->create();

    ArInvoice::factory()->create([
        'customer_id' => $customer->id,
        'type' => 'invoice',
        'status' => 'partially_paid',
        'invoice_number' => 'INV-WITH-PAYMENT',
        'payment_type' => 'credit',
        'issue_date' => '2026-03-02',
        'due_date' => '2026-03-02',
        'total_cents' => 80000,
        'paid_total_cents' => 20000,
        'balance_cents' => 60000,
    ]);

    Payment::factory()->create([
        'customer_id' => $customer->id,
        'source' => 'ar',
        'method' => 'bank_transfer',
        'amount_cents' => 20000,
        'reference' => 'PMT-ONLY-UNPAID',
        'received_at' => '2026-03-05 09:00:00',
    ]);

    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('manager');

    $response = $this->actingAs($user)->get(route('reports.customer-statement.print', [
        'customer_id' => $customer->id,
        'date_from' => '2026-03-01',
        'date_to' => '2026-03-31',
        'only_unpaid' => 1,
    ]));

    $response->assertOk();
    $response->assertSee('INV-WITH-PAYMENT');
    $response->assertDontSee('PMT-ONLY-UNPAID');
    $response->assertDontSee('Payment Receipt');
});

it('excludes voided payments from the customer statement', function () {
    $customer = Customer::factory()->corporate()->create();

    ArInvoice::factory()->create([
        'customer_id' => $customer->id,
        'type' => 'invoice',
        'status' => 'issued',
        'invoice_number' => 'INV-VOID-PMT',
        'payment_type' => 'credit',
        'issue_date' => '2026-03-01',
        'due_date' => '2026-03-10',
        'total_cents' => 50000,
        'paid_total_cents' => 0,
        'balance_cents' => 50000,
    ]);

    Payment::factory()->create([
        'customer_id' => $customer->id,
        'source' => 'ar',
        'method' => 'bank_transfer',
        'amount_cents' => 50000,
        'reference' => 'VOIDED-PMT',
        'received_at' => '2026-03-05 09:00:00',
        'voided_at' => now(),
        'voided_by' => 1,
        'void_reason' => 'Voided',
    ]);

    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('manager');

    $response = $this->actingAs($user)->get(route('reports.customer-statement.print', [
        'customer_id' => $customer->id,
        'date_from' => '2026-03-01',
        'date_to' => '2026-03-31',
    ]));

    $response->assertOk();
    $response->assertSee('INV-VOID-PMT');
    $response->assertDontSee('VOIDED-PMT');
});

it('shows unallocated ar payments in the customer statement summary', function () {
    $customer = Customer::factory()->corporate()->create();

    ArInvoice::factory()->create([
        'customer_id' => $customer->id,
        'type' => 'invoice',
        'status' => 'issued',
        'invoice_number' => 'INV-UNALLOCATED',
        'payment_type' => 'credit',
        'issue_date' => '2026-03-01',
        'due_date' => '2026-03-10',
        'total_cents' => 90100,
        'paid_total_cents' => 0,
        'balance_cents' => 90100,
    ]);

    Payment::factory()->create([
        'customer_id' => $customer->id,
        'source' => 'ar',
        'method' => 'bank_transfer',
        'amount_cents' => 12300,
        'reference' => 'PMT-UNALLOCATED',
        'received_at' => '2026-03-05 09:00:00',
    ]);

    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('manager');

    $response = $this->actingAs($user)->get(route('reports.customer-statement.print', [
        'customer_id' => $customer->id,
        'date_from' => '2026-03-01',
        'date_to' => '2026-03-31',
    ]));

    $response->assertOk();
    $response->assertSee('PMT-UNALLOCATED');
    $response->assertSee('901.00');
    $response->assertSee('123.00');
    $response->assertSee('778.00');
});

it('shows a negative balance total when period receipts exceed invoices', function () {
    $customer = Customer::factory()->corporate()->create();

    ArInvoice::factory()->create([
        'customer_id' => $customer->id,
        'type' => 'invoice',
        'status' => 'issued',
        'invoice_number' => 'INV-CREDIT-BAL',
        'payment_type' => 'credit',
        'issue_date' => '2026-05-12',
        'due_date' => '2026-06-11',
        'total_cents' => 42800,
        'paid_total_cents' => 0,
        'balance_cents' => 42800,
    ]);

    Payment::factory()->create([
        'customer_id' => $customer->id,
        'source' => 'ar',
        'method' => 'bank_transfer',
        'amount_cents' => 109900,
        'reference' => 'PMT-CREDIT-BAL',
        'received_at' => '2026-05-15 09:00:00',
    ]);

    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('manager');

    $response = $this->actingAs($user)->get(route('reports.customer-statement.print', [
        'customer_id' => $customer->id,
        'date_from' => '2026-05-01',
        'date_to' => '2026-05-31',
    ]));

    $response->assertOk();
    $response->assertSee('428.00');
    $response->assertSee('1099.00');
    $response->assertSee('-671.00');
});

it('shows a running balance for each statement row', function () {
    $customer = Customer::factory()->corporate()->create();

    ArInvoice::factory()->create([
        'customer_id' => $customer->id,
        'type' => 'invoice',
        'status' => 'issued',
        'invoice_number' => 'INV-RUN-1',
        'payment_type' => 'credit',
        'issue_date' => '2026-03-01',
        'due_date' => '2026-03-10',
        'total_cents' => 10111,
        'paid_total_cents' => 0,
        'balance_cents' => 10111,
    ]);

    Payment::factory()->create([
        'customer_id' => $customer->id,
        'source' => 'ar',
        'method' => 'bank_transfer',
        'amount_cents' => 3333,
        'reference' => 'PMT-RUN',
        'received_at' => '2026-03-02 09:00:00',
    ]);

    ArInvoice::factory()->create([
        'customer_id' => $customer->id,
        'type' => 'invoice',
        'status' => 'issued',
        'invoice_number' => 'INV-RUN-2',
        'payment_type' => 'credit',
        'issue_date' => '2026-03-03',
        'due_date' => '2026-03-12',
        'total_cents' => 2222,
        'paid_total_cents' => 0,
        'balance_cents' => 2222,
    ]);

    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('manager');

    $response = $this->actingAs($user)->get(route('reports.customer-statement.print', [
        'customer_id' => $customer->id,
        'date_from' => '2026-03-01',
        'date_to' => '2026-03-31',
    ]));

    $response->assertOk();
    $response->assertSeeInOrder([
        'INV-RUN-1',
        '101.11',
        'PMT-RUN',
        '67.78',
        'INV-RUN-2',
        '90.00',
    ]);
});

it('uses invoice amount for running balance even when invoice row shows paid amount', function () {
    $customer = Customer::factory()->corporate()->create();

    ArInvoice::factory()->create([
        'customer_id' => $customer->id,
        'type' => 'invoice',
        'status' => 'partially_paid',
        'invoice_number' => 'INV-PARTIAL-RUN',
        'payment_type' => 'credit',
        'issue_date' => '2026-04-01',
        'due_date' => '2026-04-10',
        'total_cents' => 10000,
        'paid_total_cents' => 4000,
        'balance_cents' => 6000,
    ]);

    Payment::factory()->create([
        'customer_id' => $customer->id,
        'source' => 'ar',
        'method' => 'bank_transfer',
        'amount_cents' => 2500,
        'reference' => 'PMT-PARTIAL-RUN',
        'received_at' => '2026-04-02 09:00:00',
    ]);

    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('manager');

    $response = $this->actingAs($user)->get(route('reports.customer-statement.print', [
        'customer_id' => $customer->id,
        'date_from' => '2026-04-01',
        'date_to' => '2026-04-30',
    ]));

    $response->assertOk();
    $response->assertSeeInOrder([
        'INV-PARTIAL-RUN',
        '100.00',
        '40.00',
        '100.00',
        'PMT-PARTIAL-RUN',
        '75.00',
    ]);
});
