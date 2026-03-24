<?php

use App\Models\AccountingCompany;
use App\Models\ApInvoice;
use App\Models\ApInvoiceItem;
use App\Models\FinanceSetting;
use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderInvoiceMatch;
use App\Models\PurchaseOrderItem;
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

it('blocks po-linked invoice posting on mismatch unless a finance override is provided', function () {
    $company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $supplier = Supplier::factory()->create(['company_id' => $company->id]);
    $item = InventoryItem::factory()->create(['supplier_id' => $supplier->id, 'cost_per_unit' => 10]);

    FinanceSetting::query()->updateOrCreate(['id' => 1], [
        'po_quantity_tolerance_percent' => 0,
        'po_price_tolerance_percent' => 0,
    ]);

    $po = PurchaseOrder::factory()->approved()->create([
        'company_id' => $company->id,
        'supplier_id' => $supplier->id,
        'status' => PurchaseOrder::STATUS_RECEIVED,
        'matching_policy' => '3_way',
        'received_date' => '2026-03-20',
    ]);

    $poItem = PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $po->id,
        'item_id' => $item->id,
        'quantity' => 5,
        'received_quantity' => 5,
        'unit_price' => 10,
        'total_price' => 50,
    ]);

    $invoice = ApInvoice::factory()->create([
        'company_id' => $company->id,
        'supplier_id' => $supplier->id,
        'purchase_order_id' => $po->id,
        'document_type' => 'vendor_bill',
        'invoice_number' => 'INV-PO-001',
        'invoice_date' => '2026-03-24',
        'due_date' => '2026-04-08',
        'subtotal' => 30,
        'tax_amount' => 0,
        'total_amount' => 30,
        'status' => 'draft',
    ]);

    ApInvoiceItem::factory()->create([
        'invoice_id' => $invoice->id,
        'purchase_order_item_id' => $poItem->id,
        'description' => 'Stock item',
        'quantity' => 3,
        'unit_price' => 10,
        'line_total' => 30,
    ]);

    $this->actingAs($this->user)
        ->postJson(route('api.ap.invoices.post', $invoice))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('purchase_order_id');

    $this->actingAs($this->user)
        ->postJson(route('api.ap.invoices.post', $invoice), [
            'matching_override' => true,
            'matching_override_reason' => 'Approved partial invoice for staged delivery.',
        ])
        ->assertOk()
        ->assertJsonPath('status', 'posted');

    $match = PurchaseOrderInvoiceMatch::query()->where('ap_invoice_id', $invoice->id)->firstOrFail();

    expect($match->status)->toBe('partial')
        ->and((bool) $match->override_applied)->toBeTrue();

    $this->actingAs($this->user)
        ->get(route('reports.accounting-purchase-accruals'))
        ->assertOk()
        ->assertSee($po->po_number)
        ->assertSee($supplier->name);
});

it('renders the new accounting workspace pages and reports', function () {
    $this->actingAs($this->user)
        ->get(route('accounting.budgets'))
        ->assertOk()
        ->assertSee('Budget Worksheet')
        ->assertSee('Versions');

    $this->actingAs($this->user)
        ->get(route('accounting.jobs'))
        ->assertOk()
        ->assertSee('Cost Codes')
        ->assertSee('Transactions');

    $this->actingAs($this->user)
        ->get(route('reports.accounting-inventory-valuation'))
        ->assertOk()
        ->assertSee('Inventory Valuation');

    $this->actingAs($this->user)
        ->get(route('reports.accounting-multi-company-summary'))
        ->assertOk()
        ->assertSee('Multi-Company Summary');
});
