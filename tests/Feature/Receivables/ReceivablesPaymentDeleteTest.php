<?php

use App\Models\ArInvoice;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Services\Ledger\SubledgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function makeReceivablesAdmin(): User
{
    $role = Role::firstOrCreate(['name' => 'admin'], ['guard_name' => 'web']);

    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole($role);

    return $user;
}

function makeReceivablesManagerUser(): User
{
    $role = Role::firstOrCreate(['name' => 'manager'], ['guard_name' => 'web']);

    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole($role);

    return $user;
}

it('shows the delete action only to admins', function () {
    $customer = Customer::factory()->create();
    $payment = Payment::factory()->create([
        'customer_id' => $customer->id,
        'source' => 'ar',
        'amount_cents' => 15000,
    ]);

    $admin = makeReceivablesAdmin();
    $manager = makeReceivablesManagerUser();

    $this->actingAs($admin)
        ->get(route('receivables.payments.show', $payment))
        ->assertOk()
        ->assertSeeText('Delete Payment');

    $this->actingAs($manager)
        ->get(route('receivables.payments.show', $payment))
        ->assertOk()
        ->assertDontSeeText('Delete Payment');
});

it('shows invoice dates in the payment allocations table', function () {
    $manager = makeReceivablesManagerUser();
    $customer = Customer::factory()->create();

    $invoice = ArInvoice::factory()->create([
        'customer_id' => $customer->id,
        'type' => 'invoice',
        'status' => 'issued',
        'invoice_number' => 'INV-DATE-001',
        'issue_date' => '2026-03-22',
        'due_date' => '2026-03-29',
        'total_cents' => 15000,
        'balance_cents' => 5000,
    ]);

    $payment = Payment::factory()->create([
        'customer_id' => $customer->id,
        'source' => 'ar',
        'amount_cents' => 10000,
    ]);

    PaymentAllocation::factory()->create([
        'payment_id' => $payment->id,
        'allocatable_type' => ArInvoice::class,
        'allocatable_id' => $invoice->id,
        'amount_cents' => 10000,
    ]);

    $this->actingAs($manager)
        ->get(route('receivables.payments.show', $payment))
        ->assertOk()
        ->assertSeeText('Invoice Date')
        ->assertSeeText('2026-03-22');
});

it('allows admins to delete customer payments and restore invoice balances', function () {
    $admin = makeReceivablesAdmin();
    $customer = Customer::factory()->create();

    $invoice = ArInvoice::factory()->create([
        'customer_id' => $customer->id,
        'branch_id' => 1,
        'type' => 'invoice',
        'status' => 'partially_paid',
        'invoice_number' => 'INV-DEL-001',
        'issue_date' => now()->toDateString(),
        'due_date' => now()->addDays(7)->toDateString(),
        'total_cents' => 20000,
        'paid_total_cents' => 5000,
        'balance_cents' => 15000,
    ]);
    $invoice->items()->create([
        'description' => 'Invoice line',
        'qty' => '1.000',
        'unit' => null,
        'unit_price_cents' => 20000,
        'discount_cents' => 0,
        'tax_cents' => 0,
        'line_total_cents' => 20000,
    ]);

    $payment = Payment::factory()->create([
        'branch_id' => 1,
        'customer_id' => $customer->id,
        'source' => 'ar',
        'amount_cents' => 5000,
        'received_at' => now(),
        'created_by' => $admin->id,
    ]);

    $allocation = PaymentAllocation::factory()->create([
        'payment_id' => $payment->id,
        'allocatable_type' => ArInvoice::class,
        'allocatable_id' => $invoice->id,
        'amount_cents' => 5000,
    ]);

    app(SubledgerService::class)->recordArPaymentReceived($payment, 5000, 0, $admin->id);
    $this->actingAs($admin)
        ->delete(route('receivables.payments.destroy', $payment))
        ->assertRedirect(route('receivables.payments.index'));

    $this->assertDatabaseHas('payments', [
        'id' => $payment->id,
        'voided_by' => $admin->id,
        'void_reason' => 'Payment voided',
    ]);
    $this->assertDatabaseHas('payment_allocations', [
        'id' => $allocation->id,
        'voided_by' => $admin->id,
        'void_reason' => 'Payment voided',
    ]);

    $invoice->refresh();
    expect($invoice->status)->toBe('issued');
    expect((int) $invoice->paid_total_cents)->toBe(0);
    expect((int) $invoice->balance_cents)->toBe(20000);

    // Payment void reversal entry must exist.
    $this->assertDatabaseHas('subledger_entries', [
        'source_type' => 'ar_payment',
        'source_id' => $payment->id,
        'event' => 'delete',
    ]);
    // For a directly-allocated payment (no advance-apply step), the void reversal
    // covers the full entry. The per-allocation fallback entry must NOT be created
    // or ar_invoice_ar would be double-restored.
    $this->assertDatabaseMissing('subledger_entries', [
        'source_type' => 'ar_payment_allocation',
        'source_id' => $allocation->id,
        'event' => 'delete',
    ]);
});

it('voiding a directly-allocated payment does not double-post ar_invoice_ar in subledger', function () {
    $admin = makeReceivablesAdmin();
    $customer = Customer::factory()->create();

    $invoice = ArInvoice::factory()->create([
        'customer_id' => $customer->id,
        'branch_id' => 1,
        'type' => 'invoice',
        'status' => 'issued',
        'invoice_number' => 'INV-C4-001',
        'issue_date' => now()->toDateString(),
        'due_date' => now()->addDays(7)->toDateString(),
        'total_cents' => 10000,
        'paid_total_cents' => 10000,
        'balance_cents' => 0,
    ]);

    $payment = Payment::factory()->create([
        'branch_id' => 1,
        'customer_id' => $customer->id,
        'source' => 'ar',
        'amount_cents' => 10000,
        'received_at' => now(),
        'created_by' => $admin->id,
    ]);

    PaymentAllocation::factory()->create([
        'payment_id' => $payment->id,
        'allocatable_type' => ArInvoice::class,
        'allocatable_id' => $invoice->id,
        'amount_cents' => 10000,
    ]);

    // Record the subledger entry for the payment (applied=10000, unapplied=0).
    app(SubledgerService::class)->recordArPaymentReceived($payment, 10000, 0, $admin->id);

    // Void the payment — this triggers both recordArAllocationReleased (should skip
    // fallback for direct allocations) and recordArPaymentVoided (full reversal).
    $this->actingAs($admin)
        ->delete(route('receivables.payments.destroy', $payment))
        ->assertRedirect(route('receivables.payments.index'));

    // The per-allocation fallback entry must be absent (C4 fix).
    $allocation = PaymentAllocation::query()->where('payment_id', $payment->id)->first();
    $this->assertDatabaseMissing('subledger_entries', [
        'source_type' => 'ar_payment_allocation',
        'source_id' => $allocation->id,
        'event' => 'delete',
    ]);

    // The payment void reversal entry must exist.
    $this->assertDatabaseHas('subledger_entries', [
        'source_type' => 'ar_payment',
        'source_id' => $payment->id,
        'event' => 'delete',
    ]);

    // Net subledger_entries count for ar_payment source must be exactly 2:
    // one 'payment' event + one 'delete' event.
    $count = \Illuminate\Support\Facades\DB::table('subledger_entries')
        ->where('source_type', 'ar_payment')
        ->where('source_id', $payment->id)
        ->count();
    expect($count)->toBe(2);
});

it('prevents non admins from deleting customer payments', function () {
    $manager = makeReceivablesManagerUser();
    $payment = Payment::factory()->create([
        'source' => 'ar',
    ]);

    $this->actingAs($manager)
        ->delete(route('receivables.payments.destroy', $payment))
        ->assertForbidden();
});
