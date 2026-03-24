<?php

use App\Models\AccountingCompany;
use App\Models\AccountingPeriod;
use App\Models\ApInvoice;
use App\Models\ApInvoiceAttachment;
use App\Models\ApInvoiceItem;
use App\Models\Job;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin');
    Role::findOrCreate('manager');
    Role::findOrCreate('staff');
    Permission::findOrCreate('finance.access');

    $this->admin = User::factory()->create(['status' => 'active']);
    $this->admin->assignRole('admin');

    Storage::fake('public');
});

it('blocks draft AP document creation for blocked suppliers', function () {
    $supplier = Supplier::factory()->create([
        'hold_status' => 'blocked',
    ]);

    $this->actingAs($this->admin)
        ->postJson('/api/ap/invoices', [
            'supplier_id' => $supplier->id,
            'document_type' => 'vendor_bill',
            'invoice_number' => 'INV-BLOCKED-100',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(10)->toDateString(),
            'tax_amount' => 0,
            'items' => [
                ['description' => 'Line', 'quantity' => 1, 'unit_price' => 10],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('supplier_id');
});

it('blocks posting invoices for held suppliers', function () {
    $supplier = Supplier::factory()->create([
        'hold_status' => 'hold',
    ]);

    $invoice = ApInvoice::factory()->create([
        'supplier_id' => $supplier->id,
        'status' => 'draft',
        'document_type' => 'vendor_bill',
    ]);

    ApInvoiceItem::factory()->create([
        'invoice_id' => $invoice->id,
        'quantity' => 1,
        'unit_price' => 100,
        'line_total' => 100,
    ]);

    $this->actingAs($this->admin)
        ->postJson("/api/ap/invoices/{$invoice->id}/post")
        ->assertStatus(422)
        ->assertJsonValidationErrors('supplier_id');
});

it('prefills supplier preferred payment method in the payables payment screen', function () {
    $supplier = Supplier::factory()->create([
        'preferred_payment_method' => 'card',
    ]);

    $invoice = ApInvoice::factory()->create([
        'supplier_id' => $supplier->id,
        'status' => 'posted',
        'total_amount' => 125,
    ]);

    $this->actingAs($this->admin);

    Livewire::test('payables.payments.create')
        ->set('supplier_id', $supplier->id)
        ->assertSet('payment_method', 'card')
        ->assertSet('allocations.0.invoice_id', $invoice->id);
});

it('blocks AP payment creation for held suppliers', function () {
    $supplier = Supplier::factory()->create([
        'hold_status' => 'hold',
    ]);

    $invoice = ApInvoice::factory()->create([
        'supplier_id' => $supplier->id,
        'status' => 'posted',
        'total_amount' => 100,
    ]);

    ApInvoiceItem::factory()->create([
        'invoice_id' => $invoice->id,
        'quantity' => 1,
        'unit_price' => 100,
        'line_total' => 100,
    ]);

    $this->actingAs($this->admin)
        ->postJson('/api/ap/payments', [
            'supplier_id' => $supplier->id,
            'payment_date' => now()->toDateString(),
            'amount' => 100,
            'payment_method' => 'cash',
            'allocations' => [
                ['invoice_id' => $invoice->id, 'allocated_amount' => 100],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('supplier_id');
});

it('allows AP attachment upload and deletion on paid documents while the period is open', function () {
    $supplier = Supplier::factory()->create();
    $company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $period = AccountingPeriod::query()->where('company_id', $company->id)->orderBy('period_number')->firstOrFail();
    $period->update(['status' => 'open']);

    $invoice = ApInvoice::factory()->create([
        'company_id' => $company->id,
        'period_id' => $period->id,
        'supplier_id' => $supplier->id,
        'status' => 'paid',
        'document_type' => 'vendor_bill',
    ]);

    $this->actingAs($this->admin);

    Livewire::test('payables.invoices.show', ['invoice' => $invoice])
        ->set('new_attachments', [UploadedFile::fake()->image('receipt.jpg')])
        ->call('uploadAttachments')
        ->assertHasNoErrors();

    $attachment = ApInvoiceAttachment::query()->where('invoice_id', $invoice->id)->first();

    expect($attachment)->not->toBeNull();
    expect(Storage::disk('public')->exists($attachment->file_path))->toBeTrue();

    Livewire::test('payables.invoices.show', ['invoice' => $invoice->fresh()])
        ->call('deleteAttachment', $attachment->id)
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('ap_invoice_attachments', [
        'id' => $attachment->id,
    ]);
});

it('blocks AP attachment changes once the invoice period is closed', function () {
    $supplier = Supplier::factory()->create();
    $company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $period = AccountingPeriod::query()->where('company_id', $company->id)->orderBy('period_number')->firstOrFail();
    $period->update(['status' => 'closed']);

    $invoice = ApInvoice::factory()->create([
        'company_id' => $company->id,
        'period_id' => $period->id,
        'supplier_id' => $supplier->id,
        'status' => 'paid',
        'document_type' => 'vendor_bill',
    ]);

    $attachment = ApInvoiceAttachment::query()->create([
        'invoice_id' => $invoice->id,
        'file_path' => 'ap-invoices/'.$invoice->id.'/existing.pdf',
        'original_name' => 'existing.pdf',
        'uploaded_by' => $this->admin->id,
    ]);

    Storage::disk('public')->put($attachment->file_path, 'stub');

    $this->actingAs($this->admin);

    Livewire::test('payables.invoices.show', ['invoice' => $invoice])
        ->set('new_attachments', [UploadedFile::fake()->image('late-receipt.jpg')])
        ->call('uploadAttachments')
        ->assertHasNoErrors();

    expect(ApInvoiceAttachment::query()->where('invoice_id', $invoice->id)->count())->toBe(1);

    Livewire::test('payables.invoices.show', ['invoice' => $invoice->fresh()])
        ->call('deleteAttachment', $attachment->id)
        ->assertHasNoErrors();

    $this->assertDatabaseHas('ap_invoice_attachments', [
        'id' => $attachment->id,
    ]);
});

it('persists job assignments when creating and updating AP draft invoices', function () {
    $supplier = Supplier::factory()->create();
    $company = AccountingCompany::query()->create([
        'name' => 'Main Company',
        'code' => 'MAIN',
        'base_currency' => 'QAR',
        'is_active' => true,
        'is_default' => true,
    ]);

    $jobA = Job::query()->create([
        'company_id' => $company->id,
        'name' => 'Venue Build',
        'code' => 'JOB-AP-A',
        'status' => 'active',
    ]);

    $jobB = Job::query()->create([
        'company_id' => $company->id,
        'name' => 'Pop-Up Launch',
        'code' => 'JOB-AP-B',
        'status' => 'active',
    ]);

    $this->actingAs($this->admin)
        ->postJson(route('api.ap.invoices.store'), [
            'company_id' => $company->id,
            'supplier_id' => $supplier->id,
            'job_id' => $jobA->id,
            'document_type' => 'vendor_bill',
            'invoice_number' => 'JOB-AP-100',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(10)->toDateString(),
            'tax_amount' => 0,
            'items' => [
                ['description' => 'Line', 'quantity' => 1, 'unit_price' => 10],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('job_id', $jobA->id);

    $invoice = ApInvoice::query()->where('invoice_number', 'JOB-AP-100')->firstOrFail();

    $this->actingAs($this->admin)
        ->putJson(route('api.ap.invoices.update', $invoice), [
            'company_id' => $company->id,
            'supplier_id' => $supplier->id,
            'job_id' => $jobB->id,
            'document_type' => 'vendor_bill',
            'invoice_number' => 'JOB-AP-100',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(10)->toDateString(),
            'tax_amount' => 0,
            'items' => [
                ['description' => 'Updated line', 'quantity' => 2, 'unit_price' => 15],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('job_id', $jobB->id);

    expect($invoice->fresh()->job_id)->toBe($jobB->id);
});
