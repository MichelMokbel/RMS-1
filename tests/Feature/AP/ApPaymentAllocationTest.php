<?php

use App\Models\ApInvoice;
use App\Models\ApInvoiceItem;
use App\Models\ApPayment;
use App\Models\Supplier;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin');
    $this->user = User::factory()->create();
    $this->user->assignRole('admin');
});

it('blocks allocation beyond outstanding', function () {
    $supplier = Supplier::factory()->create();
    $invoice = ApInvoice::factory()->create(['supplier_id' => $supplier->id, 'total_amount' => 100, 'status' => 'posted']);
    ApInvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'line_total' => 100, 'unit_price' => 100, 'quantity' => 1]);

    $resp = $this->actingAs($this->user)->postJson('/api/ap/payments', [
        'supplier_id' => $supplier->id,
        'payment_date' => now()->toDateString(),
        'amount' => 50,
        'allocations' => [
            ['invoice_id' => $invoice->id, 'allocated_amount' => 120],
        ],
    ]);

    $resp->assertStatus(422);
});

it('allocates and updates status to partially paid', function () {
    $supplier = Supplier::factory()->create();
    $invoice = ApInvoice::factory()->create(['supplier_id' => $supplier->id, 'total_amount' => 100, 'status' => 'posted']);
    ApInvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'line_total' => 100, 'unit_price' => 100, 'quantity' => 1]);

    $resp = $this->actingAs($this->user)->postJson('/api/ap/payments', [
        'supplier_id' => $supplier->id,
        'payment_date' => now()->toDateString(),
        'amount' => 50,
        'allocations' => [
            ['invoice_id' => $invoice->id, 'allocated_amount' => 50],
        ],
    ]);
    $resp->assertCreated();

    $invoice->refresh();
    expect($invoice->status)->toBe('partially_paid');
});
