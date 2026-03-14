<?php

use App\Models\ApInvoice;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin');
    Role::findOrCreate('staff');
});

it('renders the unified accounts payable workspace for staff', function () {
    $user = User::factory()->create();
    $user->assignRole('staff');

    $this->actingAs($user)
        ->get('/payables')
        ->assertOk()
        ->assertSee('Accounts Payable')
        ->assertSee('Reimbursements')
        ->assertDontSee('Spend');
});

it('redirects legacy spend route into approvals tab', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');

    $this->actingAs($user)
        ->get('/spend')
        ->assertRedirect('/payables?tab=approvals');
});

it('renders the type-first create page', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');

    $this->actingAs($user)
        ->get('/payables/create')
        ->assertOk()
        ->assertSee('Vendor Bill')
        ->assertSee('Employee Reimbursement')
        ->assertSee('Recurring Bill');
});

it('removes the is_expense checkbox from create and edit forms', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');
    $supplier = Supplier::factory()->create();
    $invoice = ApInvoice::factory()->create([
        'supplier_id' => $supplier->id,
        'status' => 'draft',
        'document_type' => 'vendor_bill',
        'is_expense' => false,
    ]);

    $this->actingAs($user)
        ->get('/payables/invoices/create?document_type=vendor_bill')
        ->assertOk()
        ->assertDontSee('is_expense', false);

    $this->actingAs($user)
        ->get("/payables/invoices/{$invoice->id}/edit")
        ->assertOk()
        ->assertDontSee('is_expense', false);
});
