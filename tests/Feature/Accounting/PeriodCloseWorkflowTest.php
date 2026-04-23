<?php

use App\Models\AccountingCompany;
use App\Models\AccountingPeriod;
use App\Models\ApInvoice;
use App\Models\BankAccount;
use App\Models\BankReconciliationRun;
use App\Models\ClosingChecklist;
use App\Models\ExpenseCategory;
use App\Models\ExpenseProfile;
use App\Models\PeriodLock;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Accounting\AccountingPeriodGateService;
use App\Services\Accounting\AccountingPeriodChecklistService;
use App\Services\Accounting\AccountingPeriodCloseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Role::findOrCreate('admin', 'web');
    Permission::findOrCreate('finance.access', 'web');

    $this->admin = User::factory()->create(['status' => 'active']);
    $this->admin->assignRole('admin');

    $this->finance = User::factory()->create(['status' => 'active']);
    $this->finance->givePermissionTo('finance.access');
});

function preparePeriodForClose(AccountingPeriod $period, User $user): void
{
    $period->update([
        'start_date' => now()->startOfMonth()->subMonth()->toDateString(),
        'end_date' => now()->endOfMonth()->subMonth()->toDateString(),
        'status' => 'open',
    ]);

    $service = app(AccountingPeriodCloseService::class);
    $checklistService = app(AccountingPeriodChecklistService::class);
    $service->readiness($period->fresh());

    BankAccount::query()
        ->where('company_id', $period->company_id)
        ->where('is_active', true)
        ->get()
        ->each(function (BankAccount $account) use ($period, $user) {
            BankReconciliationRun::query()->updateOrCreate(
                [
                    'bank_account_id' => $account->id,
                    'company_id' => $period->company_id,
                    'period_id' => $period->id,
                    'statement_date' => $period->end_date->toDateString(),
                ],
                [
                    'statement_import_id' => null,
                    'statement_ending_balance' => 0,
                    'book_ending_balance' => 0,
                    'variance_amount' => 0,
                    'status' => 'completed',
                    'completed_at' => now(),
                    'completed_by' => $user->id,
                ]
            );
        });

    $service->readiness($period->fresh());

    ClosingChecklist::query()
        ->where('period_id', $period->id)
        ->where('task_type', 'manual')
        ->get()
        ->each(fn (ClosingChecklist $item) => $checklistService->completeManualTask($item, $user->id, 'Reviewed in test'));
}

it('moves past periods to ended_open when statuses are refreshed', function () {
    $company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $period = AccountingPeriod::query()->where('company_id', $company->id)->orderBy('period_number')->firstOrFail();
    $period->update([
        'start_date' => now()->startOfMonth()->subMonth()->toDateString(),
        'end_date' => now()->endOfMonth()->subMonth()->toDateString(),
        'status' => 'open',
    ]);

    app(AccountingPeriodCloseService::class)->syncStatuses($company->id);

    expect($period->fresh()->status)->toBe('ended_open');
});

it('fails closed when no accounting period exists for the posting date', function () {
    $company = AccountingCompany::query()->where('is_default', true)->firstOrFail();

    expect(function () use ($company) {
        app(AccountingPeriodGateService::class)->assertDateOpen('2035-01-01', $company->id, null, 'ledger');
    })->toThrow(ValidationException::class);
});

it('blocks close until required checklist items are complete', function () {
    $company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $period = AccountingPeriod::query()->where('company_id', $company->id)->orderBy('period_number')->firstOrFail();
    $period->update([
        'start_date' => now()->startOfMonth()->subMonth()->toDateString(),
        'end_date' => now()->endOfMonth()->subMonth()->toDateString(),
        'status' => 'open',
    ]);

    $this->actingAs($this->finance)
        ->postJson(route('api.accounting.period-close.close', $period), [
            'close_note' => 'Month end close',
        ])
        ->assertStatus(422);

    expect($period->fresh()->status)->not->toBe('closed');
});

it('allows finance to close a period and only admin to reopen it', function () {
    $company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $period = AccountingPeriod::query()->where('company_id', $company->id)->orderBy('period_number')->firstOrFail();

    preparePeriodForClose($period, $this->finance);

    $this->actingAs($this->finance)
        ->postJson(route('api.accounting.period-close.close', $period), [
            'close_note' => 'Month end close',
        ])
        ->assertOk()
        ->assertJsonPath('period.status', 'closed')
        ->assertJsonPath('summary.is_ready', true);

    expect($period->fresh()->status)->toBe('closed');
    expect(PeriodLock::query()->where('period_id', $period->id)->where('lock_type', 'close')->exists())->toBeTrue();

    $this->actingAs($this->finance)
        ->postJson(route('api.accounting.period-close.reopen', $period), [
            'reopen_reason' => 'Need adjustment',
        ])
        ->assertStatus(403);

    $this->actingAs($this->admin)
        ->postJson(route('api.accounting.period-close.reopen', $period), [
            'reopen_reason' => 'Need adjustment',
            'move_lock_date_back' => true,
        ])
        ->assertOk()
        ->assertJsonPath('period.status', 'reopened');

    expect($period->fresh()->status)->toBe('reopened');
});

it('blocks ap bill posting into a closed period', function () {
    $company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $period = AccountingPeriod::query()->where('company_id', $company->id)->orderBy('period_number')->firstOrFail();
    preparePeriodForClose($period, $this->finance);

    $this->actingAs($this->finance)
        ->postJson(route('api.accounting.period-close.close', $period), [
            'close_note' => 'Closed for AP test',
        ])
        ->assertOk();

    $supplier = Supplier::factory()->create(['company_id' => $company->id]);

    $invoice = ApInvoice::query()->create([
        'company_id' => $company->id,
        'period_id' => $period->id,
        'supplier_id' => $supplier->id,
        'document_type' => 'vendor_bill',
        'is_expense' => false,
        'invoice_number' => 'LOCKED-BILL-1',
        'invoice_date' => $period->end_date->toDateString(),
        'due_date' => $period->end_date->toDateString(),
        'subtotal' => 100,
        'tax_amount' => 0,
        'total_amount' => 100,
        'status' => 'draft',
        'created_by' => $this->admin->id,
    ]);

    $invoice->items()->create([
        'description' => 'Locked period bill',
        'quantity' => 1,
        'unit_price' => 100,
        'line_total' => 100,
    ]);

    $this->actingAs($this->admin)
        ->postJson(route('api.ap.invoices.post', $invoice))
        ->assertStatus(422);
});

it('blocks expense posting into a closed period', function () {
    $company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $period = AccountingPeriod::query()->where('company_id', $company->id)->orderBy('period_number')->firstOrFail();
    preparePeriodForClose($period, $this->finance);

    $this->actingAs($this->finance)
        ->postJson(route('api.accounting.period-close.close', $period), [
            'close_note' => 'Closed for expense test',
        ])
        ->assertOk();

    $supplier = Supplier::factory()->create(['company_id' => $company->id]);
    $category = ExpenseCategory::factory()->create();

    $invoice = ApInvoice::query()->create([
        'company_id' => $company->id,
        'period_id' => $period->id,
        'supplier_id' => $supplier->id,
        'category_id' => $category->id,
        'document_type' => 'expense',
        'is_expense' => true,
        'invoice_number' => 'LOCKED-EXP-1',
        'invoice_date' => $period->end_date->toDateString(),
        'due_date' => $period->end_date->toDateString(),
        'subtotal' => 75,
        'tax_amount' => 0,
        'total_amount' => 75,
        'status' => 'draft',
        'created_by' => $this->admin->id,
    ]);

    $invoice->items()->create([
        'description' => 'Locked period expense',
        'quantity' => 1,
        'unit_price' => 75,
        'line_total' => 75,
    ]);

    ExpenseProfile::query()->create([
        'invoice_id' => $invoice->id,
        'channel' => 'vendor',
        'approval_status' => 'approved',
        'requires_finance_approval' => false,
    ]);

    $this->actingAs($this->admin)
        ->postJson(route('api.spend.expenses.post', $invoice))
        ->assertStatus(422);
});

it('blocks bank reconciliation finalization into a closed period', function () {
    $company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $period = AccountingPeriod::query()->where('company_id', $company->id)->orderBy('period_number')->firstOrFail();
    preparePeriodForClose($period, $this->finance);

    $this->actingAs($this->finance)
        ->postJson(route('api.accounting.period-close.close', $period), [
            'close_note' => 'Closed for banking test',
        ])
        ->assertOk();

    $bankAccount = BankAccount::query()->where('company_id', $company->id)->where('is_active', true)->firstOrFail();

    $this->actingAs($this->finance)
        ->postJson(route('api.accounting.banking.reconciliations.store'), [
            'bank_account_id' => $bankAccount->id,
            'statement_date' => $period->end_date->toDateString(),
            'statement_ending_balance' => 0,
        ])
        ->assertStatus(422);
});
