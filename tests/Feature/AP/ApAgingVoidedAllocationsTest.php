<?php

use App\Models\ApInvoice;
use App\Models\ApInvoiceItem;
use App\Models\ApPayment;
use App\Models\ApPaymentAllocation;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AP\ApReportsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin');
    $this->user = User::factory()->create();
    $this->user->assignRole('admin');
});

it('excludes voided allocations from AP aging outstanding balance', function () {
    $supplier = Supplier::factory()->create();

    $invoice = ApInvoice::factory()->create([
        'supplier_id' => $supplier->id,
        'total_amount' => 100,
        'subtotal' => 100,
        'tax_amount' => 0,
        'status' => 'partially_paid',
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(5)->toDateString(),
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

    // allocation of 60 — active
    ApPaymentAllocation::factory()->create([
        'payment_id' => $payment->id,
        'invoice_id' => $invoice->id,
        'allocated_amount' => 60,
        'voided_at' => null,
    ]);

    // allocation of 40 — voided; should be excluded from outstanding calculation
    ApPaymentAllocation::factory()->create([
        'payment_id' => $payment->id,
        'invoice_id' => $invoice->id,
        'allocated_amount' => 40,
        'voided_at' => now(),
    ]);

    /** @var ApReportsService $svc */
    $svc = app(ApReportsService::class);
    $aging = $svc->agingSummary($supplier->id);

    $outstanding = array_sum($aging);

    // Only the active 60 allocation counts, so outstanding = 100 - 60 = 40.
    expect($outstanding)->toBe(40.0);
});
