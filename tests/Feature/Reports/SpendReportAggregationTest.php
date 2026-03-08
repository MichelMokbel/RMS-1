<?php

use App\Models\ApInvoice;
use App\Models\ApInvoiceItem;
use App\Models\ExpenseCategory;
use App\Models\ExpenseProfile;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('manager', 'web');
});

it('aggregates only canonical AP expense rows by channel in spend report print', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('manager');

    $category = ExpenseCategory::factory()->create(['name' => 'Ops']);
    $supplier = Supplier::factory()->create(['name' => 'Vendor A']);

    $vendorInvoice = ApInvoice::factory()->create([
        'supplier_id' => $supplier->id,
        'category_id' => $category->id,
        'is_expense' => true,
        'invoice_number' => 'AP-EXP-001',
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->toDateString(),
        'subtotal' => 50,
        'tax_amount' => 5,
        'total_amount' => 55,
        'status' => 'posted',
    ]);

    ApInvoiceItem::create([
        'invoice_id' => $vendorInvoice->id,
        'description' => 'Vendor expense line',
        'quantity' => 1,
        'unit_price' => 50,
        'line_total' => 50,
    ]);

    ExpenseProfile::create([
        'invoice_id' => $vendorInvoice->id,
        'channel' => 'vendor',
        'approval_status' => 'approved',
    ]);

    $pettyInvoice = ApInvoice::factory()->create([
        'supplier_id' => $supplier->id,
        'category_id' => $category->id,
        'is_expense' => true,
        'invoice_number' => 'AP-EXP-002',
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->toDateString(),
        'subtotal' => 20,
        'tax_amount' => 0,
        'total_amount' => 20,
        'status' => 'posted',
    ]);

    ApInvoiceItem::create([
        'invoice_id' => $pettyInvoice->id,
        'description' => 'Petty cash expense line',
        'quantity' => 1,
        'unit_price' => 20,
        'line_total' => 20,
    ]);

    ExpenseProfile::create([
        'invoice_id' => $pettyInvoice->id,
        'channel' => 'petty_cash',
        'approval_status' => 'approved',
    ]);

    $response = $this->actingAs($user)->get(route('reports.expenses.print'));

    $response->assertOk()
        ->assertSee('AP-EXP-001')
        ->assertSee('AP-EXP-002')
        ->assertSee('vendor')
        ->assertSee('petty_cash')
        ->assertSee('Spend Report')
        ->assertDontSee('LEG-001');
});
