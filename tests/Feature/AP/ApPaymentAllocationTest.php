<?php

use App\Models\ApInvoice;
use App\Models\ApInvoiceItem;
use App\Models\ApPayment;
use App\Models\BankTransaction;
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

it('replays ap payment creation idempotently by client uuid', function () {
    $supplier = Supplier::factory()->create();
    $invoice = ApInvoice::factory()->create(['supplier_id' => $supplier->id, 'total_amount' => 100, 'status' => 'posted']);
    ApInvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'line_total' => 100, 'unit_price' => 100, 'quantity' => 1]);

    $payload = [
        'supplier_id' => $supplier->id,
        'payment_date' => now()->toDateString(),
        'amount' => 100,
        'client_uuid' => '11111111-1111-4111-8111-111111111111',
        'allocations' => [
            ['invoice_id' => $invoice->id, 'allocated_amount' => 100],
        ],
    ];

    $this->actingAs($this->user)->postJson('/api/ap/payments', $payload)->assertCreated();
    $this->actingAs($this->user)->postJson('/api/ap/payments', $payload)->assertCreated();

    expect(ApPayment::query()->where('client_uuid', $payload['client_uuid'])->count())->toBe(1);
    expect($invoice->allocations()->count())->toBe(1);
    $payment = ApPayment::query()->where('client_uuid', $payload['client_uuid'])->firstOrFail();
    expect(BankTransaction::query()
        ->where('source_type', ApPayment::class)
        ->where('source_id', $payment->id)
        ->where('transaction_type', 'ap_payment')
        ->count())->toBe(1);
});

it('rejects conflicting ap payment replays for the same client uuid', function () {
    $supplier = Supplier::factory()->create();
    $invoice = ApInvoice::factory()->create(['supplier_id' => $supplier->id, 'total_amount' => 100, 'status' => 'posted']);
    ApInvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'line_total' => 100, 'unit_price' => 100, 'quantity' => 1]);

    $payload = [
        'supplier_id' => $supplier->id,
        'payment_date' => now()->toDateString(),
        'amount' => 100,
        'client_uuid' => '22222222-2222-4222-8222-222222222222',
        'allocations' => [
            ['invoice_id' => $invoice->id, 'allocated_amount' => 100],
        ],
    ];

    $this->actingAs($this->user)->postJson('/api/ap/payments', $payload)->assertCreated();

    $conflicting = $payload;
    $conflicting['amount'] = 90;

    $this->actingAs($this->user)->postJson('/api/ap/payments', $conflicting)
        ->assertStatus(422);
});
