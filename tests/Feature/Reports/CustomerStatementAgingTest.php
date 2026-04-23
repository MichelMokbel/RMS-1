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
