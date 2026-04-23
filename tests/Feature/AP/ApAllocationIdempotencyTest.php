<?php

use App\Models\ApInvoice;
use App\Models\ApInvoiceItem;
use App\Models\ApPayment;
use App\Models\ApPaymentAllocation;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AP\ApAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin');
    $this->user = User::factory()->create();
    $this->user->assignRole('admin');
});

it('allocateExistingPayment is idempotent when called twice with the same allocations', function () {
    $supplier = Supplier::factory()->create();

    $invoice = ApInvoice::factory()->create([
        'supplier_id' => $supplier->id,
        'total_amount' => 100,
        'subtotal' => 100,
        'status' => 'posted',
    ]);
    ApInvoiceItem::factory()->create([
        'invoice_id' => $invoice->id,
        'line_total' => 100,
        'unit_price' => 100,
        'quantity' => 1,
    ]);

    $payment = ApPayment::factory()->create([
        'supplier_id' => $supplier->id,
        'amount' => 100,
    ]);

    $allocations = [
        ['invoice_id' => $invoice->id, 'allocated_amount' => 100],
    ];

    /** @var ApAllocationService $svc */
    $svc = app(ApAllocationService::class);

    $svc->allocateExistingPayment($payment, $allocations, $this->user->id);
    $svc->allocateExistingPayment($payment, $allocations, $this->user->id);

    expect(ApPaymentAllocation::query()
        ->where('payment_id', $payment->id)
        ->where('invoice_id', $invoice->id)
        ->whereNull('voided_at')
        ->count()
    )->toBe(1);
});

it('allocateExistingPayment is idempotent across multiple invoices', function () {
    $supplier = Supplier::factory()->create();

    $inv1 = ApInvoice::factory()->create([
        'supplier_id' => $supplier->id,
        'total_amount' => 60,
        'subtotal' => 60,
        'status' => 'posted',
    ]);
    ApInvoiceItem::factory()->create(['invoice_id' => $inv1->id, 'line_total' => 60, 'unit_price' => 60, 'quantity' => 1]);

    $inv2 = ApInvoice::factory()->create([
        'supplier_id' => $supplier->id,
        'total_amount' => 40,
        'subtotal' => 40,
        'status' => 'posted',
    ]);
    ApInvoiceItem::factory()->create(['invoice_id' => $inv2->id, 'line_total' => 40, 'unit_price' => 40, 'quantity' => 1]);

    $payment = ApPayment::factory()->create([
        'supplier_id' => $supplier->id,
        'amount' => 100,
    ]);

    $allocations = [
        ['invoice_id' => $inv1->id, 'allocated_amount' => 60],
        ['invoice_id' => $inv2->id, 'allocated_amount' => 40],
    ];

    /** @var ApAllocationService $svc */
    $svc = app(ApAllocationService::class);

    $svc->allocateExistingPayment($payment, $allocations, $this->user->id);
    $svc->allocateExistingPayment($payment, $allocations, $this->user->id);

    expect(ApPaymentAllocation::query()
        ->where('payment_id', $payment->id)
        ->whereNull('voided_at')
        ->count()
    )->toBe(2);
});
