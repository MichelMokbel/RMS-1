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

it('posts draft invoice', function () {
    $supplier = Supplier::factory()->create();

    $response = $this->actingAs($this->user)->postJson('/api/ap/invoices', [
        'supplier_id' => $supplier->id,
        'is_expense' => false,
        'invoice_number' => 'INV-100',
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(10)->toDateString(),
        'tax_amount' => 0,
        'items' => [
            ['description' => 'Line', 'quantity' => 1, 'unit_price' => 10],
        ],
    ]);

    $response->assertCreated();
    $invoiceId = $response->json('id');
    $invoice = ApInvoice::find($invoiceId);
    expect($invoice->status)->toBe('draft');

    $post = $this->actingAs($this->user)->postJson("/api/ap/invoices/{$invoiceId}/post");
    $post->assertOk();
    $invoice->refresh();
    expect($invoice->status)->toBe('posted');
});

it('voids invoice without allocations', function () {
    $supplier = Supplier::factory()->create();
    $inv = ApInvoice::factory()->create([
        'supplier_id' => $supplier->id,
        'status' => 'posted',
        'total_amount' => 0,
        'tax_amount' => 0,
        'subtotal' => 0,
    ]);

    $resp = $this->actingAs($this->user)->postJson("/api/ap/invoices/{$inv->id}/void");
    $resp->assertOk();
    $inv->refresh();
    expect($inv->status)->toBe('void');
});
