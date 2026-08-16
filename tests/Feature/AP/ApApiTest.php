<?php

use App\Models\ApInvoice;
use App\Models\ExpenseCategory;
use App\Models\PettyCashWallet;
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

it('lists invoices via api', function () {
    $supplier = Supplier::factory()->create();
    ApInvoice::factory()->create(['supplier_id' => $supplier->id, 'status' => 'draft']);

    $resp = $this->actingAs($this->user)->getJson('/api/ap/invoices');
    $resp->assertOk()->assertJsonStructure(['data']);
});

it('prevents duplicate invoice number for supplier', function () {
    $supplier = Supplier::factory()->create();
    $payload = [
        'supplier_id' => $supplier->id,
        'document_type' => 'vendor_bill',
        'invoice_number' => 'INV-XYZ',
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(10)->toDateString(),
        'tax_amount' => 0,
        'items' => [
            ['description' => 'Line', 'quantity' => 1, 'unit_price' => 10],
        ],
    ];

    $this->actingAs($this->user)->postJson('/api/ap/invoices', $payload)->assertCreated();
    $this->actingAs($this->user)->postJson('/api/ap/invoices', $payload)->assertStatus(422);
});

it('stores updates and filters AP invoice reference numbers', function () {
    $supplier = Supplier::factory()->create();
    $payload = [
        'supplier_id' => $supplier->id,
        'document_type' => 'vendor_bill',
        'invoice_number' => 'INV-REF-100',
        'reference_number' => 'PO-EXT-100',
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(10)->toDateString(),
        'tax_amount' => 0,
        'items' => [
            ['description' => 'Reference line', 'quantity' => 1, 'unit_price' => 10],
        ],
    ];

    $invoiceId = $this->actingAs($this->user)
        ->postJson('/api/ap/invoices', $payload)
        ->assertCreated()
        ->assertJsonPath('reference_number', 'PO-EXT-100')
        ->json('id');

    $this->actingAs($this->user)
        ->getJson('/api/ap/invoices?reference_number=EXT-100')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $invoiceId);

    $payload['reference_number'] = 'PO-EXT-101';

    $this->actingAs($this->user)
        ->putJson("/api/ap/invoices/{$invoiceId}", $payload)
        ->assertOk()
        ->assertJsonPath('reference_number', 'PO-EXT-101');

    $this->assertDatabaseHas('ap_invoices', [
        'id' => $invoiceId,
        'reference_number' => 'PO-EXT-101',
    ]);
});

it('auto settles admin petty cash AP documents by default', function () {
    $supplier = Supplier::factory()->create();
    $category = ExpenseCategory::factory()->create();
    $wallet = PettyCashWallet::factory()->create([
        'active' => true,
        'balance' => 150,
    ]);

    $payload = [
        'supplier_id' => $supplier->id,
        'category_id' => $category->id,
        'document_type' => 'expense',
        'expense_channel' => 'petty_cash',
        'wallet_id' => $wallet->id,
        'invoice_number' => 'PC-AP-001',
        'reference_number' => 'PC-RECEIPT-001',
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(10)->toDateString(),
        'tax_amount' => 0,
        'items' => [
            ['description' => 'Cash purchase', 'quantity' => 1, 'unit_price' => 25],
        ],
    ];

    $response = $this->actingAs($this->user)->postJson('/api/ap/invoices', $payload)->assertCreated();

    expect($response->json('status'))->toBe('paid');
    $this->assertDatabaseHas('expense_profiles', [
        'invoice_id' => $response->json('id'),
        'approval_status' => 'approved',
        'settlement_mode' => 'petty_cash_wallet',
    ]);
    $this->assertDatabaseHas('ap_payments', [
        'supplier_id' => $supplier->id,
        'reference' => 'PC-RECEIPT-001',
    ]);
    expect((float) $wallet->fresh()->balance)->toBe(125.0);
});

it('lets admin create petty cash AP documents as pending settlement', function () {
    $supplier = Supplier::factory()->create();
    $category = ExpenseCategory::factory()->create();
    $wallet = PettyCashWallet::factory()->create([
        'active' => true,
        'balance' => 150,
    ]);

    $payload = [
        'supplier_id' => $supplier->id,
        'category_id' => $category->id,
        'document_type' => 'expense',
        'expense_channel' => 'petty_cash',
        'wallet_id' => $wallet->id,
        'not_settled' => true,
        'invoice_number' => 'PC-AP-002',
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(10)->toDateString(),
        'tax_amount' => 0,
        'items' => [
            ['description' => 'Cash purchase', 'quantity' => 1, 'unit_price' => 25],
        ],
    ];

    $response = $this->actingAs($this->user)->postJson('/api/ap/invoices', $payload)->assertCreated();

    expect($response->json('status'))->toBe('posted');
    $this->assertDatabaseHas('expense_profiles', [
        'invoice_id' => $response->json('id'),
        'approval_status' => 'approved',
        'settled_at' => null,
    ]);
    expect((float) $wallet->fresh()->balance)->toBe(150.0);
});
