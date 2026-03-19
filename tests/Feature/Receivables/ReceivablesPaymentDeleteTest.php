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

    $this->assertDatabaseMissing('payments', ['id' => $payment->id]);
    $this->assertDatabaseMissing('payment_allocations', ['id' => $allocation->id]);

    $invoice->refresh();
    expect($invoice->status)->toBe('issued');
    expect((int) $invoice->paid_total_cents)->toBe(0);
    expect((int) $invoice->balance_cents)->toBe(20000);

    $this->assertDatabaseHas('subledger_entries', [
        'source_type' => 'ar_payment',
        'source_id' => $payment->id,
        'event' => 'delete',
    ]);
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
