<?php

use App\Models\ApInvoice;
use App\Models\ApPayment;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('manager', 'web');
});

it('excludes voided supplier payments from the supplier statement', function () {
    $supplier = Supplier::factory()->create();

    ApInvoice::factory()->create([
        'supplier_id' => $supplier->id,
        'invoice_number' => 'BILL-001',
        'invoice_date' => '2026-03-01',
        'due_date' => '2026-03-10',
        'status' => 'posted',
        'total_amount' => 100,
        'subtotal' => 100,
        'tax_amount' => 0,
    ]);

    ApPayment::factory()->create([
        'supplier_id' => $supplier->id,
        'payment_date' => '2026-03-05',
        'amount' => 100,
        'reference' => 'VOID-AP-PAY',
        'voided_at' => now(),
        'voided_by' => 1,
    ]);

    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('manager');

    $response = $this->actingAs($user)->get(route('reports.supplier-statement.print', [
        'supplier_id' => $supplier->id,
        'date_from' => '2026-03-01',
        'date_to' => '2026-03-31',
    ]));

    $response->assertOk();
    $response->assertSee('BILL-001');
    $response->assertDontSee('VOID-AP-PAY');
});
