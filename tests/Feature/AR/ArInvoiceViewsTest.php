<?php

use App\Models\AccountingCompany;
use App\Models\ArInvoice;
use App\Models\ArInvoiceItem;
use App\Models\Customer;
use App\Models\Job;
use App\Models\MenuItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('manager');
});

it('shows invoice and line-item notes on the invoice show page', function () {
    $user = User::factory()->create();
    $user->assignRole('manager');

    $customer = Customer::factory()->create();
    $invoice = ArInvoice::factory()->create([
        'customer_id' => $customer->id,
        'notes' => 'General invoice note',
    ]);
    ArInvoiceItem::factory()->create([
        'invoice_id' => $invoice->id,
        'description' => 'Chicken Shawarma',
        'line_notes' => 'No pickles',
    ]);

    $this->actingAs($user)
        ->get(route('invoices.show', $invoice))
        ->assertOk()
        ->assertSee('General invoice note')
        ->assertSee('No pickles');
});

it('shows invoice and line-item notes on the invoice print page', function () {
    $user = User::factory()->create();
    $user->assignRole('manager');

    $customer = Customer::factory()->create();
    $invoice = ArInvoice::factory()->create([
        'customer_id' => $customer->id,
        'notes' => 'Please deliver after 6 PM',
    ]);
    ArInvoiceItem::factory()->create([
        'invoice_id' => $invoice->id,
        'description' => 'Mixed Grill',
        'line_notes' => 'Extra garlic sauce',
    ]);

    $this->actingAs($user)
        ->get(route('invoices.print', $invoice))
        ->assertOk()
        ->assertSee('Please deliver after 6 PM')
        ->assertSee('Extra garlic sauce');
});

it('uses a wider container on invoice create and show pages', function () {
    $user = User::factory()->create();
    $user->assignRole('manager');

    $customer = Customer::factory()->create();
    $invoice = ArInvoice::factory()->create([
        'customer_id' => $customer->id,
    ]);

    $this->actingAs($user)
        ->get(route('invoices.create'))
        ->assertOk()
        ->assertSee('max-w-7xl', false);

    $this->actingAs($user)
        ->get(route('invoices.show', $invoice))
        ->assertOk()
        ->assertSee('max-w-7xl', false);
});

it('shows inline menu item creation controls on invoice create', function () {
    $user = User::factory()->create();
    $user->assignRole('manager');

    $this->actingAs($user)
        ->get(route('invoices.create'))
        ->assertOk()
        ->assertSee('Create Menu Item')
        ->assertSee('Item Code')
        ->assertSee('Name')
        ->assertSee('Category')
        ->assertSee('Selling Price')
        ->assertSee('Unit');
});

it('can create and auto-select a customer from invoice create', function () {
    $user = User::factory()->create();
    $user->assignRole('manager');

    Volt::actingAs($user);

    Volt::test('receivables.invoices.create')
        ->call('prepareCustomerModal')
        ->set('new_customer_name', 'Invoice Modal Customer')
        ->set('new_customer_phone', '55112233')
        ->call('createCustomer')
        ->assertHasNoErrors()
        ->assertSet('customer_id', Customer::query()->where('name', 'Invoice Modal Customer')->value('id'));

    $customer = Customer::query()->where('name', 'Invoice Modal Customer')->firstOrFail();
    expect($customer->customer_code)->toBe('CUST-0001');
});

it('auto-generates menu item code from invoice create modal', function () {
    $user = User::factory()->create();
    $user->assignRole('manager');

    Volt::actingAs($user);

    Volt::test('receivables.invoices.create')
        ->call('prepareMenuItemModal')
        ->set('menu_item_name', 'Invoice Modal Menu Item')
        ->set('menu_item_price', 15)
        ->call('createMenuItem')
        ->assertHasNoErrors();

    $item = MenuItem::query()->where('name', 'Invoice Modal Menu Item')->firstOrFail();
    expect($item->code)->toBe('MI-000001');
});

it('lets users clear the selected customer when editing a draft invoice', function () {
    $user = User::factory()->create();
    $user->assignRole('manager');

    $currentCustomer = Customer::factory()->create([
        'name' => 'Current Customer',
        'phone' => '11111111',
    ]);

    $draft = ArInvoice::factory()->create([
        'customer_id' => $currentCustomer->id,
        'status' => 'draft',
    ]);

    Volt::actingAs($user);

    Volt::test('receivables.invoices.create', ['invoice' => $draft])
        ->assertSet('customer_id', $currentCustomer->id)
        ->set('customer_search', 'Replacement Customer')
        ->assertSet('customer_id', null);
});

it('shows the job field on create and the assigned job on the invoice show page', function () {
    $user = User::factory()->create();
    $user->assignRole('manager');

    $company = AccountingCompany::query()->create([
        'name' => 'Main Company',
        'code' => 'MAIN',
        'base_currency' => 'QAR',
        'is_active' => true,
        'is_default' => true,
    ]);

    $job = Job::query()->create([
        'company_id' => $company->id,
        'name' => 'Catering Launch',
        'code' => 'JOB-AR-01',
        'status' => 'active',
    ]);

    $customer = Customer::factory()->create();
    $invoice = ArInvoice::factory()->create([
        'customer_id' => $customer->id,
        'job_id' => $job->id,
    ]);

    $this->actingAs($user)
        ->get(route('invoices.create'))
        ->assertOk()
        ->assertSee('Job')
        ->assertSee('JOB-AR-01');

    $this->actingAs($user)
        ->get(route('invoices.show', $invoice))
        ->assertOk()
        ->assertSee('JOB-AR-01')
        ->assertSee('Catering Launch');
});
