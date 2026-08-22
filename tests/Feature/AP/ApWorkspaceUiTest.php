<?php

use App\Models\AccountingCompany;
use App\Models\AccountingPeriod;
use App\Models\ApInvoice;
use App\Models\ApInvoiceItem;
use App\Models\ApPayment;
use App\Models\ApPaymentAllocation;
use App\Models\BankAccount;
use App\Models\ExpenseProfile;
use App\Models\Job;
use App\Models\JobCostCode;
use App\Models\JobPhase;
use App\Models\LedgerAccount;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Permission;
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

it('shows the petty cash expense import entry point on accounts payable only to authorized admins', function () {
    Permission::findOrCreate('petty_cash.import');
    $importer = User::factory()->create();
    $importer->assignRole('admin');
    $importer->givePermissionTo('petty_cash.import');
    $manager = User::factory()->create();
    $manager->assignRole('staff');
    $manager->givePermissionTo('petty_cash.import');

    $this->actingAs($importer)
        ->get(route('payables.index'))
        ->assertOk()
        ->assertSee('Import Expenses')
        ->assertSee(route('petty-cash.imports.index'), false);

    $this->actingAs($manager)
        ->get(route('payables.index'))
        ->assertOk()
        ->assertDontSee('Import Expenses')
        ->assertDontSee(route('petty-cash.imports.index'), false);
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
        ->assertSee('Supplier Bill')
        ->assertSee('Petty Cash Expense')
        ->assertSee('Employee Reimbursement')
        ->assertSee('Advanced Documents')
        ->assertSee('Open Advanced')
        ->assertSee('Recurring Bill');
});

it('shows the not settled control for admin petty cash creation', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');

    $this->actingAs($user)
        ->get('/payables/invoices/create?document_type=expense&expense_channel=petty_cash')
        ->assertOk()
        ->assertSee('Not settled')
        ->assertSee('Create and Settle')
        ->assertSee('x-on:submit.prevent', false)
        ->assertDontSee('wire:submit="saveDraft"', false)
        ->assertSee('wire:click="saveDraft"', false);
});

it('offers an accounting-safe correction flow for closed expenses', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $invoice = ApInvoice::factory()->create([
        'status' => 'paid',
        'document_type' => 'expense',
        'is_expense' => true,
        'invoice_number' => 'PC-CLOSED-100',
        'total_amount' => 75,
    ]);
    ExpenseProfile::query()->create([
        'invoice_id' => $invoice->id,
        'channel' => 'petty_cash',
        'approval_status' => 'approved',
        'settled_at' => now(),
        'settlement_mode' => 'petty_cash_wallet',
    ]);
    $payment = ApPayment::factory()->create([
        'supplier_id' => $invoice->supplier_id,
        'amount' => 75,
        'payment_method' => 'petty_cash',
    ]);
    ApPaymentAllocation::factory()->create([
        'payment_id' => $payment->id,
        'invoice_id' => $invoice->id,
        'allocated_amount' => 75,
    ]);

    $this->actingAs($admin)
        ->get(route('payables.invoices.show', $invoice))
        ->assertOk()
        ->assertSee('Correct Expense')
        ->assertSee('Correct Closed Expense')
        ->assertSee('Void Expense')
        ->assertSee('Reverse &amp; Create Draft', false)
        ->assertDontSee('href="'.route('payables.invoices.edit', $invoice).'"', false);
});

it('shows and searches AP invoice reference numbers in the workspace', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');
    $invoice = ApInvoice::factory()->create([
        'status' => 'draft',
        'document_type' => 'vendor_bill',
        'invoice_number' => 'AP-REFERENCE-100',
        'reference_number' => 'EXT-REFERENCE-900',
    ]);
    ApInvoiceItem::query()->create([
        'invoice_id' => $invoice->id,
        'description' => 'Reference test line',
        'quantity' => 1,
        'unit_price' => 10,
        'line_total' => 10,
    ]);

    $this->actingAs($user)
        ->get('/payables/invoices/create?document_type=vendor_bill')
        ->assertOk()
        ->assertSee('Reference #');

    $this->actingAs($user)
        ->get(route('payables.invoices.edit', $invoice))
        ->assertOk()
        ->assertSee('Reference #')
        ->assertSee('EXT-REFERENCE-900');

    Volt::actingAs($user);
    Volt::test('payables.invoices.edit', ['invoice' => $invoice])
        ->set('reference_number', 'EXT-REFERENCE-901')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('ap_invoices', [
        'id' => $invoice->id,
        'reference_number' => 'EXT-REFERENCE-901',
    ]);

    $this->actingAs($user)
        ->get(route('payables.invoices.show', $invoice))
        ->assertOk()
        ->assertSee('Reference')
        ->assertSee('EXT-REFERENCE-901');

    Volt::test('payables.index')
        ->set('search', 'EXT-REFERENCE-901')
        ->assertSee('AP-REFERENCE-100')
        ->assertSee('EXT-REFERENCE-901');
});

it('shows supplier creation quick links to admins on AP creation pages', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');

    $invoiceResponse = $this->actingAs($user)
        ->get('/payables/invoices/create?document_type=vendor_bill');

    $invoiceResponse
        ->assertOk()
        ->assertSee('Create Supplier')
        ->assertSee(route('suppliers.create'), false)
        ->assertSee('target="_blank"', false);

    $paymentResponse = $this->actingAs($user)
        ->get('/payables/payments/create');

    $paymentResponse
        ->assertOk()
        ->assertSee('Create Supplier')
        ->assertSee(route('suppliers.create'), false)
        ->assertSee('target="_blank"', false);
});

it('does not show the admin supplier creation link to AP staff', function () {
    $user = User::factory()->create();
    $user->assignRole('staff');

    $this->actingAs($user)
        ->get('/payables/invoices/create?document_type=vendor_bill')
        ->assertOk()
        ->assertDontSee('Create Supplier')
        ->assertDontSee(route('suppliers.create'), false);
});

it('updates AP unit prices on blur and uses stable line keys', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');
    $supplier = Supplier::factory()->create();
    $invoice = ApInvoice::factory()->create([
        'supplier_id' => $supplier->id,
        'status' => 'draft',
        'document_type' => 'vendor_bill',
        'is_expense' => false,
    ]);

    ApInvoiceItem::query()->create([
        'invoice_id' => $invoice->id,
        'description' => 'Test line',
        'quantity' => 1,
        'unit_price' => 12.5,
        'line_total' => 12.5,
    ]);

    $this->actingAs($user)
        ->get('/payables/invoices/create?document_type=vendor_bill')
        ->assertOk()
        ->assertSee('wire:model.blur="lines.0.unit_price"', false)
        ->assertDontSee('wire:model.live="lines.0.unit_price"', false)
        ->assertSee('wire:key="ap-invoice-create-line-0"', false);

    $this->actingAs($user)
        ->get("/payables/invoices/{$invoice->id}/edit")
        ->assertOk()
        ->assertSee('wire:model.blur="lines.0.unit_price"', false)
        ->assertDontSee('wire:model.live="lines.0.unit_price"', false)
        ->assertSee('wire:key="ap-invoice-edit-line-0"', false);
});

it('always keeps one empty trailing line on AP creation', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');
    $supplier = Supplier::factory()->create();

    Volt::actingAs($user);
    $component = Volt::test('payables.invoices.create', ['requestedDocumentType' => 'vendor_bill'])
        ->assertCount('lines', 1)
        ->assertSet('lines.0.description', '')
        ->call('addLine')
        ->assertCount('lines', 2)
        ->call('removeLine', 1)
        ->assertCount('lines', 1)
        ->set('lines.0.description', 'First item')
        ->assertCount('lines', 2)
        ->assertSet('lines.1.description', '')
        ->set('lines.0.unit_price', 15.25)
        ->assertCount('lines', 2)
        ->set('lines.1.description', 'Second item')
        ->assertCount('lines', 3)
        ->assertSet('lines.2.description', '');

    $component
        ->set('lines.1.description', '')
        ->assertCount('lines', 2)
        ->call('saveDraft')
        ->assertHasErrors(['invoice_number'])
        ->assertHasNoErrors(['lines.1.description'])
        ->assertCount('lines', 2)
        ->assertSee('A new blank line appears automatically as you enter each item.')
        ->assertSee('Add Line')
        ->assertSee('wire:model.live.debounce.300ms="lines.1.description"', false);

    $component
        ->set('supplier_id', $supplier->id)
        ->set('invoice_number', 'AP-AUTO-LINE-100')
        ->call('saveDraft')
        ->assertHasNoErrors();

    $invoice = ApInvoice::query()->where('invoice_number', 'AP-AUTO-LINE-100')->firstOrFail();
    expect($invoice->items()->count())->toBe(1)
        ->and($invoice->items()->firstOrFail()->description)->toBe('First item');
});

it('always keeps one empty trailing line while editing a draft', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');
    $supplier = Supplier::factory()->create();
    $invoice = ApInvoice::factory()->create([
        'supplier_id' => $supplier->id,
        'status' => 'draft',
        'document_type' => 'vendor_bill',
        'is_expense' => false,
        'invoice_number' => 'AP-EDIT-AUTO-LINE-100',
    ]);
    ApInvoiceItem::query()->create([
        'invoice_id' => $invoice->id,
        'description' => 'Existing item',
        'quantity' => 1,
        'unit_price' => 12,
        'line_total' => 12,
    ]);

    Volt::actingAs($user);
    Volt::test('payables.invoices.edit', ['invoice' => $invoice])
        ->assertCount('lines', 2)
        ->assertSet('lines.1.description', '')
        ->call('addLine')
        ->assertCount('lines', 3)
        ->call('removeLine', 2)
        ->assertCount('lines', 2)
        ->set('lines.1.description', 'Added item')
        ->assertCount('lines', 3)
        ->assertSet('lines.2.description', '')
        ->set('lines.1.unit_price', 8.5)
        ->assertSee('Add Line')
        ->assertSee('wire:model.live.debounce.300ms="lines.2.description"', false)
        ->call('save')
        ->assertHasNoErrors();

    expect($invoice->items()->count())->toBe(2)
        ->and($invoice->items()->orderBy('id')->pluck('description')->all())
        ->toBe(['Existing item', 'Added item']);
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

it('offers posted AP correction as an editable version and blocks direct posted editing', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');
    $invoice = ApInvoice::factory()->create([
        'status' => 'posted',
        'document_type' => 'vendor_bill',
        'invoice_number' => 'AP-UI-REV-100',
    ]);

    $this->actingAs($user)
        ->get(route('payables.invoices.show', $invoice))
        ->assertOk()
        ->assertSee('Create Editable Version')
        ->assertSee('Void &amp; Create Draft', false);

    $this->actingAs($user)
        ->get(route('payables.invoices.edit', $invoice))
        ->assertRedirect(route('payables.invoices.show', $invoice));
});

it('shows AP revision lineage while keeping correction actions away from staff', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $staff = User::factory()->create();
    $staff->assignRole('staff');

    $original = ApInvoice::factory()->create([
        'status' => 'void',
        'invoice_number' => 'AP-HISTORY-100',
    ]);
    $revision = ApInvoice::factory()->create([
        'status' => 'draft',
        'invoice_number' => 'AP-HISTORY-100V1',
        'revision_root_id' => $original->id,
        'revision_source_id' => $original->id,
        'revision_number' => 1,
    ]);

    $this->actingAs($admin)
        ->get(route('payables.invoices.show', $revision))
        ->assertOk()
        ->assertSee('Version History')
        ->assertSee('Original')
        ->assertSee('Version V1');

    $this->actingAs($staff)
        ->get(route('payables.invoices.show', $revision))
        ->assertOk()
        ->assertDontSee('Create Editable Version')
        ->assertDontSee('Void &amp; Create Draft', false);
});

it('shows the job, phase, and cost code fields on AP create and edit pages and the assigned values on the show page', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');

    $company = AccountingCompany::query()->create([
        'name' => 'Main Company',
        'code' => 'MAIN',
        'base_currency' => 'QAR',
        'is_active' => true,
        'is_default' => true,
    ]);

    $job = Job::query()->create([
        'company_id' => $company->id,
        'name' => 'Office Fit-Out',
        'code' => 'JOB-AP-01',
        'status' => 'active',
    ]);

    $phase = JobPhase::query()->create([
        'job_id' => $job->id,
        'name' => 'Electrical',
        'code' => 'ELEC',
        'status' => 'active',
    ]);

    $costCode = JobCostCode::query()->create([
        'company_id' => $company->id,
        'name' => 'Materials',
        'code' => 'MAT',
        'is_active' => true,
    ]);

    $supplier = Supplier::factory()->create();
    $invoice = ApInvoice::factory()->create([
        'supplier_id' => $supplier->id,
        'job_id' => $job->id,
        'job_phase_id' => $phase->id,
        'job_cost_code_id' => $costCode->id,
        'status' => 'draft',
        'document_type' => 'vendor_bill',
        'is_expense' => false,
    ]);

    $this->actingAs($user)
        ->get('/payables/invoices/create?document_type=vendor_bill')
        ->assertOk()
        ->assertSee('Job')
        ->assertSee('Phase')
        ->assertSee('Cost Code')
        ->assertSee('JOB-AP-01');

    $this->actingAs($user)
        ->get("/payables/invoices/{$invoice->id}/edit")
        ->assertOk()
        ->assertSee('Job')
        ->assertSee('Phase')
        ->assertSee('Cost Code')
        ->assertSee('JOB-AP-01');

    $this->actingAs($user)
        ->get("/payables/invoices/{$invoice->id}")
        ->assertOk()
        ->assertSee('JOB-AP-01')
        ->assertSee('Office Fit-Out')
        ->assertSee('ELEC')
        ->assertSee('Electrical')
        ->assertSee('MAT')
        ->assertSee('Materials');
});

it('shows the derived period finalization state on AP pages', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');

    $company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $period = AccountingPeriod::query()->where('company_id', $company->id)->orderBy('period_number')->firstOrFail();
    $period->update(['status' => 'closed']);

    $supplier = Supplier::factory()->create();
    $invoice = ApInvoice::factory()->create([
        'company_id' => $company->id,
        'period_id' => $period->id,
        'supplier_id' => $supplier->id,
        'status' => 'paid',
        'document_type' => 'vendor_bill',
        'invoice_number' => 'PERIOD-FINAL-01',
    ]);

    $this->actingAs($user)
        ->get('/payables')
        ->assertOk()
        ->assertSee('Period Finalization')
        ->assertSee('Finalized by Period Close');

    $this->actingAs($user)
        ->get("/payables/invoices/{$invoice->id}")
        ->assertOk()
        ->assertSee('Period Finalization')
        ->assertSee('Finalized by Period Close')
        ->assertSee('This document is finalized because its accounting period is closed.');
});

it('shows payment references in the allocations table on the AP document page', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');

    $supplier = Supplier::factory()->create();
    $invoice = ApInvoice::factory()->create([
        'supplier_id' => $supplier->id,
        'status' => 'partially_paid',
        'document_type' => 'vendor_bill',
        'total_amount' => 250,
    ]);

    $payment = ApPayment::query()->create([
        'supplier_id' => $supplier->id,
        'payment_date' => now()->toDateString(),
        'amount' => 100,
        'payment_method' => 'bank_transfer',
        'reference' => 'PAY-REF-1001',
        'currency_code' => 'QAR',
        'created_by' => $user->id,
        'posted_at' => now(),
        'posted_by' => $user->id,
    ]);

    ApPaymentAllocation::query()->create([
        'payment_id' => $payment->id,
        'invoice_id' => $invoice->id,
        'allocated_amount' => 100,
    ]);

    $this->actingAs($user)
        ->get("/payables/invoices/{$invoice->id}")
        ->assertOk()
        ->assertSee('Reference')
        ->assertSee('PAY-REF-1001');
});

it('allows a manager to approve a submitted expense from the AP workspace', function () {
    $submitter = User::factory()->create();
    $submitter->assignRole('staff');

    $manager = User::factory()->create();
    $manager->assignRole('manager');

    $supplier = Supplier::factory()->create();
    $invoice = ApInvoice::factory()->create([
        'supplier_id' => $supplier->id,
        'document_type' => 'expense',
        'is_expense' => true,
        'status' => 'draft',
        'total_amount' => 100,
    ]);

    ExpenseProfile::query()->create([
        'invoice_id' => $invoice->id,
        'channel' => 'vendor',
        'approval_status' => 'submitted',
        'submitted_by' => $submitter->id,
        'submitted_at' => now(),
        'requires_finance_approval' => false,
    ]);

    Volt::actingAs($manager);

    Volt::test('payables.index')
        ->call('approveManager', $invoice->id);

    $this->assertDatabaseHas('expense_profiles', [
        'invoice_id' => $invoice->id,
        'approval_status' => 'approved',
        'manager_approved_by' => $manager->id,
    ]);
});

it('does not render settle for submitted expenses and lets admin review self-submitted rows', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $supplier = Supplier::factory()->create();
    $invoice = ApInvoice::factory()->create([
        'supplier_id' => $supplier->id,
        'document_type' => 'expense',
        'is_expense' => true,
        'status' => 'posted',
        'total_amount' => 100,
    ]);

    ExpenseProfile::query()->create([
        'invoice_id' => $invoice->id,
        'channel' => 'vendor',
        'approval_status' => 'submitted',
        'submitted_by' => $admin->id,
        'submitted_at' => now(),
        'requires_finance_approval' => false,
    ]);

    $this->actingAs($admin)
        ->get('/payables?tab=approvals')
        ->assertOk()
        ->assertSee('Manager Approve')
        ->assertDontSee('wire:click="settleExpense('.$invoice->id.')"', false);
});

it('settles a posted expense from the AP workspace using the default bank account when needed', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $submitter = User::factory()->create();
    $managerApprover = User::factory()->create();

    $company = AccountingCompany::query()->create([
        'name' => 'Main Company',
        'code' => 'MAIN',
        'base_currency' => 'QAR',
        'is_active' => true,
        'is_default' => true,
    ]);

    $bankLedger = LedgerAccount::query()->create([
        'company_id' => $company->id,
        'code' => 'BANK-LEDGER-01',
        'name' => 'Operating Bank',
        'type' => 'asset',
        'account_class' => 'asset',
        'is_active' => true,
        'allow_direct_posting' => true,
    ]);

    $bankAccount = BankAccount::query()->create([
        'company_id' => $company->id,
        'ledger_account_id' => $bankLedger->id,
        'name' => 'Primary Bank',
        'code' => 'BANK-01',
        'account_type' => 'checking',
        'bank_name' => 'Local Bank',
        'account_number_last4' => '1234',
        'currency_code' => 'QAR',
        'is_default' => true,
        'is_active' => true,
    ]);

    $supplier = Supplier::factory()->create();
    $invoice = ApInvoice::factory()->create([
        'company_id' => $company->id,
        'supplier_id' => $supplier->id,
        'document_type' => 'expense',
        'is_expense' => true,
        'status' => 'posted',
        'subtotal' => 120,
        'tax_amount' => 0,
        'total_amount' => 120,
    ]);

    ExpenseProfile::query()->create([
        'invoice_id' => $invoice->id,
        'channel' => 'vendor',
        'approval_status' => 'approved',
        'submitted_by' => $submitter->id,
        'submitted_at' => now(),
        'manager_approved_by' => $managerApprover->id,
        'manager_approved_at' => now(),
        'requires_finance_approval' => false,
    ]);

    Volt::actingAs($admin);

    Volt::test('payables.index')
        ->call('settleExpense', $invoice->id);

    $this->assertDatabaseHas('ap_payments', [
        'supplier_id' => $supplier->id,
        'company_id' => $company->id,
        'bank_account_id' => $bankAccount->id,
        'payment_method' => 'bank_transfer',
        'amount' => 120.00,
    ]);

    $this->assertDatabaseHas('expense_profiles', [
        'invoice_id' => $invoice->id,
        'settlement_mode' => 'manual_ap_payment',
    ]);

    expect($invoice->fresh()->status)->toBe('paid');
});

it('settles all visible settleable expenses from the AP workspace', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $company = AccountingCompany::query()->create([
        'name' => 'Main Company',
        'code' => 'MAIN',
        'base_currency' => 'QAR',
        'is_active' => true,
        'is_default' => true,
    ]);

    $bankLedger = LedgerAccount::query()->create([
        'company_id' => $company->id,
        'code' => 'BANK-LEDGER-02',
        'name' => 'Operating Bank',
        'type' => 'asset',
        'account_class' => 'asset',
        'is_active' => true,
        'allow_direct_posting' => true,
    ]);

    BankAccount::query()->create([
        'company_id' => $company->id,
        'ledger_account_id' => $bankLedger->id,
        'name' => 'Primary Bank',
        'code' => 'BANK-02',
        'account_type' => 'checking',
        'bank_name' => 'Local Bank',
        'account_number_last4' => '1234',
        'currency_code' => 'QAR',
        'is_default' => true,
        'is_active' => true,
    ]);

    $supplier = Supplier::factory()->create();

    $invoiceA = ApInvoice::factory()->create([
        'company_id' => $company->id,
        'supplier_id' => $supplier->id,
        'document_type' => 'expense',
        'is_expense' => true,
        'status' => 'posted',
        'subtotal' => 80,
        'tax_amount' => 0,
        'total_amount' => 80,
    ]);

    $invoiceB = ApInvoice::factory()->create([
        'company_id' => $company->id,
        'supplier_id' => $supplier->id,
        'document_type' => 'expense',
        'is_expense' => true,
        'status' => 'partially_paid',
        'subtotal' => 140,
        'tax_amount' => 0,
        'total_amount' => 140,
    ]);

    ExpenseProfile::query()->insert([
        [
            'invoice_id' => $invoiceA->id,
            'channel' => 'vendor',
            'approval_status' => 'approved',
            'submitted_by' => $admin->id,
            'submitted_at' => now(),
            'manager_approved_by' => $admin->id,
            'manager_approved_at' => now(),
            'requires_finance_approval' => false,
        ],
        [
            'invoice_id' => $invoiceB->id,
            'channel' => 'vendor',
            'approval_status' => 'approved',
            'submitted_by' => $admin->id,
            'submitted_at' => now(),
            'manager_approved_by' => $admin->id,
            'manager_approved_at' => now(),
            'requires_finance_approval' => false,
        ],
    ]);

    Volt::actingAs($admin);

    Volt::test('payables.index')
        ->set('tab', 'expenses')
        ->call('settleAllExpenses');

    expect($invoiceA->fresh()->status)->toBe('paid');
    expect($invoiceB->fresh()->status)->toBe('paid');

    $this->assertDatabaseHas('expense_profiles', [
        'invoice_id' => $invoiceA->id,
        'settlement_mode' => 'manual_ap_payment',
    ]);
    $this->assertDatabaseHas('expense_profiles', [
        'invoice_id' => $invoiceB->id,
        'settlement_mode' => 'manual_ap_payment',
    ]);
});
