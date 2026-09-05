<?php

use App\Models\AccountingCompany;
use App\Models\ArInvoice;
use App\Models\ArInvoiceItem;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\MenuItem;
use App\Models\User;
use App\Services\AR\CustomerItemPriceHistoryService;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    config(['pos.money_scale' => 1000, 'pos.currency' => 'QAR']);
    Role::findOrCreate('manager');
    $this->actor = User::factory()->create();
    $this->actor->assignRole('manager');
    $this->company = AccountingCompany::query()->create([
        'name' => 'Price History', 'code' => 'PRICE', 'is_default' => true, 'is_active' => true, 'base_currency' => 'QAR',
    ]);
    $this->branch = Branch::query()->create([
        'name' => 'History Branch', 'code' => 'HIST', 'company_id' => $this->company->id, 'is_active' => true,
    ]);
    DB::table('user_branch_access')->insert(['user_id' => $this->actor->id, 'branch_id' => $this->branch->id]);
    $this->customer = Customer::factory()->create();
    $this->item = MenuItem::factory()->create();
    $this->history = function (int $price, array $invoice = []) {
        $document = ArInvoice::factory()->create(array_merge([
            'customer_id' => $this->customer->id,
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'status' => 'issued', 'issue_date' => '2026-08-01',
        ], $invoice));
        ArInvoiceItem::factory()->create([
            'invoice_id' => $document->id, 'sellable_type' => MenuItem::class, 'sellable_id' => $this->item->id,
            'unit_price_cents' => $price, 'unit' => 'kg',
        ]);

        return $document;
    };
});

it('uses the last business dated issued price and excludes other customers statuses currencies and drafts', function () {
    ($this->history)(12345, ['issue_date' => '2026-08-20', 'created_at' => '2026-08-20']);
    ($this->history)(9000, ['issue_date' => '2026-08-10', 'created_at' => '2026-09-01']);
    foreach ([
        ['status' => 'draft'], ['status' => 'voided'], ['type' => 'credit_note'],
        ['voided_at' => now()], ['customer_id' => Customer::factory()->create()->id], ['currency' => 'USD'],
    ] as $attributes) {
        ($this->history)(99000, array_merge(['issue_date' => '2026-09-01'], $attributes));
    }
    $result = app(CustomerItemPriceHistoryService::class)->latest($this->actor, $this->branch->id, $this->customer->id, [$this->item->id]);
    expect($result[$this->item->id]['unit_price_cents'])->toBe(12345)
        ->and($result[$this->item->id]['issue_date'])->toBe('2026-08-20');
});

it('does not reveal prices across branch company or actor access boundaries', function () {
    ($this->history)(12345);
    $otherCompany = AccountingCompany::query()->create(['name' => 'Other', 'code' => 'OTHER', 'base_currency' => 'QAR', 'is_active' => true]);
    ($this->history)(99000, ['company_id' => $otherCompany->id, 'issue_date' => '2026-09-01']);
    $otherBranch = Branch::query()->create(['name' => 'Other', 'code' => 'OTHER', 'company_id' => $otherCompany->id, 'is_active' => true]);
    ($this->history)(88000, ['company_id' => $otherCompany->id, 'branch_id' => $otherBranch->id, 'issue_date' => '2026-09-02']);
    $service = app(CustomerItemPriceHistoryService::class);
    expect($service->latest($this->actor, $this->branch->id, $this->customer->id, [$this->item->id])[$this->item->id]['unit_price_cents'])->toBe(12345)
        ->and($service->latest($this->actor, $otherBranch->id, $this->customer->id, [$this->item->id]))->toBe([]);
    $denied = User::factory()->create();
    DB::table('user_branch_access')->insert(['user_id' => $denied->id, 'branch_id' => $this->branch->id]);
    expect($service->latest($denied, $this->branch->id, $this->customer->id, [$this->item->id]))->toBe([]);
});

it('refreshes price notes when customer and item selections change on create and edit without replacing entered prices', function () {
    ($this->history)(12345);
    Volt::actingAs($this->actor);
    Volt::test('receivables.invoices.create', ['invoice' => null, 'order_id' => null])
        ->set('branch_id', $this->branch->id)
        ->call('selectCustomer', $this->customer->id)
        ->set('selected_items.0.menu_item_id', $this->item->id)
        ->set('selected_items.0.unit_price', '20.000')
        ->assertSee('Last unit price: 12.345 QAR')
        ->assertSee('2026-08-01')
        ->assertSet('selected_items.0.unit_price', '20.000')
        ->call('selectCustomer', Customer::factory()->create()->id)
        ->assertDontSee('Last unit price: 12.345 QAR')
        ->assertSee('No previous invoice price.')
        ->call('selectCustomer', $this->customer->id)
        ->assertSee('Last unit price: 12.345 QAR')
        ->set('selected_items.0.menu_item_id', null)
        ->assertDontSee('Last unit price: 12.345 QAR');

    $draft = ($this->history)(77000, ['status' => 'draft', 'issue_date' => '2026-09-01']);
    Volt::test('receivables.invoices.create', ['invoice' => $draft])
        ->assertSee('Last unit price: 12.345 QAR')
        ->assertSet('selected_items.0.unit_price', '77.000');
});
