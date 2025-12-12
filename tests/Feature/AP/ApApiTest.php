<?php

use App\Models\ApInvoice;
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
        'is_expense' => false,
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
