<?php

use App\Models\AccountingCompany;
use App\Models\AccountingPeriod;
use App\Models\ApInvoice;
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
