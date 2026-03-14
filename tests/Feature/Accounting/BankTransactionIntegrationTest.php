<?php

use App\Models\ApInvoice;
use App\Models\ApInvoiceItem;
use App\Models\ApPayment;
use App\Models\BankTransaction;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin');
    $this->user = User::factory()->create();
    $this->user->assignRole('admin');
});

it('records a bank transaction when an ap payment is created', function () {
    $supplier = Supplier::factory()->create();
    $invoice = ApInvoice::factory()->create([
        'supplier_id' => $supplier->id,
        'total_amount' => 100,
        'status' => 'posted',
    ]);
    ApInvoiceItem::factory()->create([
        'invoice_id' => $invoice->id,
        'line_total' => 100,
        'unit_price' => 100,
        'quantity' => 1,
    ]);

    $this->actingAs($this->user)->postJson('/api/ap/payments', [
        'supplier_id' => $supplier->id,
        'payment_date' => now()->toDateString(),
        'amount' => 100,
        'allocations' => [
            ['invoice_id' => $invoice->id, 'allocated_amount' => 100],
        ],
    ])->assertCreated();

    $payment = ApPayment::query()->latest('id')->first();

    expect($payment)->not->toBeNull();
    expect(BankTransaction::query()
        ->where('source_type', ApPayment::class)
        ->where('source_id', $payment->id)
        ->where('transaction_type', 'ap_payment')
        ->exists())->toBeTrue();
});
