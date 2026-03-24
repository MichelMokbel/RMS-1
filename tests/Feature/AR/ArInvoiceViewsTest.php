<?php

use App\Models\AccountingCompany;
use App\Models\ArInvoice;
use App\Models\ArInvoiceItem;
use App\Models\Customer;
use App\Models\Job;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
