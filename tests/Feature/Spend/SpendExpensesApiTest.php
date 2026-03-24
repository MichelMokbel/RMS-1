<?php

use App\Models\ApInvoice;
use App\Models\ExpenseCategory;
use App\Models\PettyCashWallet;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('manager', 'web');
    Role::findOrCreate('staff', 'web');
    Permission::findOrCreate('finance.access', 'web');

    $this->admin = User::factory()->create(['status' => 'active']);
    $this->admin->assignRole('admin');

    $this->manager = User::factory()->create(['status' => 'active']);
    $this->manager->assignRole('manager');

    $this->staff = User::factory()->create(['status' => 'active']);
    $this->staff->assignRole('staff');

    $this->finance = User::factory()->create(['status' => 'active']);
    $this->finance->givePermissionTo('finance.access');

    $this->supplier = Supplier::factory()->create();
    $this->category = ExpenseCategory::factory()->create();
    $this->wallet = PettyCashWallet::factory()->create([
        'active' => true,
        'balance' => 500,
    ]);

    Storage::fake('public');
});

function createDraftExpense(User $user, array $overrides = []): array
{
    $payload = array_merge([
        'channel' => 'vendor',
        'supplier_id' => Supplier::query()->firstOrFail()->id,
        'category_id' => ExpenseCategory::query()->firstOrFail()->id,
        'expense_date' => now()->toDateString(),
        'description' => 'Expense line',
        'amount' => 100,
        'tax_amount' => 0,
    ], $overrides);

    return test()
        ->actingAs($user)
        ->postJson(route('api.spend.expenses.store'), $payload)
        ->assertCreated()
        ->json();
}

function attachReceipt(User $user, int $invoiceId): void
{
    test()->actingAs($user)
        ->post(route('api.spend.expenses.attachments.store', $invoiceId), [
            'file' => UploadedFile::fake()->image('receipt.jpg'),
        ])
        ->assertCreated();
}

it('creates canonical expense drafts for vendor petty cash and reimbursement channels', function () {
    $vendor = createDraftExpense($this->staff, ['channel' => 'vendor']);
    $petty = createDraftExpense($this->staff, [
        'channel' => 'petty_cash',
        'wallet_id' => $this->wallet->id,
    ]);
    $reimbursement = createDraftExpense($this->staff, ['channel' => 'reimbursement']);

    expect($vendor['status'])->toBe('draft')
        ->and($vendor['approval_status'])->toBe('draft')
        ->and($vendor['channel'])->toBe('vendor')
        ->and($petty['channel'])->toBe('petty_cash')
        ->and($reimbursement['channel'])->toBe('reimbursement');

    $this->assertDatabaseHas('expense_profiles', [
        'invoice_id' => $vendor['id'],
        'channel' => 'vendor',
        'approval_status' => 'draft',
    ]);

    $this->assertDatabaseHas('expense_profiles', [
        'invoice_id' => $petty['id'],
        'channel' => 'petty_cash',
        'wallet_id' => $this->wallet->id,
        'approval_status' => 'draft',
    ]);

    $this->assertDatabaseHas('expense_profiles', [
        'invoice_id' => $reimbursement['id'],
        'channel' => 'reimbursement',
        'approval_status' => 'draft',
    ]);
});

it('submits draft to submitted state', function () {
    $draft = createDraftExpense($this->staff);

    $this->actingAs($this->staff)
        ->postJson(route('api.spend.expenses.submit', $draft['id']))
        ->assertOk()
        ->assertJsonPath('approval_status', 'submitted');

    $this->assertDatabaseHas('expense_profiles', [
        'invoice_id' => $draft['id'],
        'approval_status' => 'submitted',
        'submitted_by' => $this->staff->id,
    ]);
});

it('manager approval auto-approves when no exception flags', function () {
    $draft = createDraftExpense($this->staff);
    attachReceipt($this->staff, (int) $draft['id']);

    $this->actingAs($this->staff)
        ->postJson(route('api.spend.expenses.submit', $draft['id']))
        ->assertOk();

    $response = $this->actingAs($this->manager)
        ->postJson(route('api.spend.expenses.approve', $draft['id']), ['stage' => 'manager'])
        ->assertOk();

    $response->assertJsonPath('approval_status', 'approved')
        ->assertJsonPath('requires_finance_approval', false)
        ->assertJsonPath('exception_flags', []);
});

it('manager approval goes to manager_approved when exception flags exist', function () {
    $draft = createDraftExpense($this->staff, ['amount' => 1500]);

    $this->actingAs($this->staff)
        ->postJson(route('api.spend.expenses.submit', $draft['id']))
        ->assertOk();

    $response = $this->actingAs($this->manager)
        ->postJson(route('api.spend.expenses.approve', $draft['id']), ['stage' => 'manager'])
        ->assertOk();

    $response->assertJsonPath('approval_status', 'manager_approved')
        ->assertJsonPath('requires_finance_approval', true);

    expect($response->json('exception_flags'))->toContain('amount_over_threshold');
    expect($response->json('exception_flags'))->toContain('missing_attachment');
});

it('supplier-specific approval thresholds escalate expense approval', function () {
    $supplier = Supplier::factory()->create([
        'approval_threshold' => 50,
    ]);

    $draft = createDraftExpense($this->staff, [
        'supplier_id' => $supplier->id,
        'amount' => 100,
    ]);

    attachReceipt($this->staff, (int) $draft['id']);

    $this->actingAs($this->staff)
        ->postJson(route('api.spend.expenses.submit', $draft['id']))
        ->assertOk();

    $response = $this->actingAs($this->manager)
        ->postJson(route('api.spend.expenses.approve', $draft['id']), ['stage' => 'manager'])
        ->assertOk();

    $response->assertJsonPath('approval_status', 'manager_approved')
        ->assertJsonPath('requires_finance_approval', true);

    expect($response->json('exception_flags'))->toContain('supplier_threshold_exceeded');
});

it('requires finance approval for manager approved expenses', function () {
    $draft = createDraftExpense($this->staff, ['amount' => 1500]);

    $this->actingAs($this->staff)
        ->postJson(route('api.spend.expenses.submit', $draft['id']))
        ->assertOk();

    $this->actingAs($this->manager)
        ->postJson(route('api.spend.expenses.approve', $draft['id']), ['stage' => 'manager'])
        ->assertOk()
        ->assertJsonPath('approval_status', 'manager_approved');

    $this->actingAs($this->finance)
        ->postJson(route('api.spend.expenses.approve', $draft['id']), ['stage' => 'finance'])
        ->assertOk()
        ->assertJsonPath('approval_status', 'approved');
});

it('rejects from submitted and manager approved states and requires reason', function () {
    $draftA = createDraftExpense($this->staff);

    $this->actingAs($this->staff)
        ->postJson(route('api.spend.expenses.submit', $draftA['id']))
        ->assertOk();

    $this->actingAs($this->manager)
        ->postJson(route('api.spend.expenses.reject', $draftA['id']), ['reason' => 'Policy mismatch'])
        ->assertOk()
        ->assertJsonPath('approval_status', 'rejected');

    $draftB = createDraftExpense($this->staff, ['amount' => 1500]);

    $this->actingAs($this->staff)
        ->postJson(route('api.spend.expenses.submit', $draftB['id']))
        ->assertOk();

    $this->actingAs($this->manager)
        ->postJson(route('api.spend.expenses.approve', $draftB['id']), ['stage' => 'manager'])
        ->assertOk();

    $this->actingAs($this->finance)
        ->postJson(route('api.spend.expenses.reject', $draftB['id']), ['reason' => 'Finance rejected'])
        ->assertOk()
        ->assertJsonPath('approval_status', 'rejected');

    $this->actingAs($this->manager)
        ->postJson(route('api.spend.expenses.reject', $draftB['id']), [])
        ->assertStatus(422);
});

it('blocks posting unless approval status is approved', function () {
    $draft = createDraftExpense($this->staff, ['amount' => 1500]);

    $this->actingAs($this->staff)
        ->postJson(route('api.spend.expenses.submit', $draft['id']))
        ->assertOk();

    $this->actingAs($this->manager)
        ->postJson(route('api.spend.expenses.approve', $draft['id']), ['stage' => 'manager'])
        ->assertOk()
        ->assertJsonPath('approval_status', 'manager_approved');

    $this->actingAs($this->finance)
        ->postJson(route('api.spend.expenses.post', $draft['id']))
        ->assertStatus(422);
});

it('blocks settlement unless invoice is posted or partially paid', function () {
    $draft = createDraftExpense($this->staff);
    attachReceipt($this->staff, (int) $draft['id']);

    $this->actingAs($this->staff)
        ->postJson(route('api.spend.expenses.submit', $draft['id']))
        ->assertOk();

    $this->actingAs($this->manager)
        ->postJson(route('api.spend.expenses.approve', $draft['id']), ['stage' => 'manager'])
        ->assertOk()
        ->assertJsonPath('approval_status', 'approved');

    $this->actingAs($this->finance)
        ->postJson(route('api.spend.expenses.settle', $draft['id']), [])
        ->assertStatus(422);
});

it('settles petty cash channel via AP payment allocation and wallet deduction', function () {
    $startingBalance = (float) $this->wallet->balance;

    $draft = createDraftExpense($this->staff, [
        'channel' => 'petty_cash',
        'wallet_id' => $this->wallet->id,
        'amount' => 100,
    ]);
    attachReceipt($this->staff, (int) $draft['id']);

    $this->actingAs($this->staff)
        ->postJson(route('api.spend.expenses.submit', $draft['id']))
        ->assertOk();

    $this->actingAs($this->manager)
        ->postJson(route('api.spend.expenses.approve', $draft['id']), ['stage' => 'manager'])
        ->assertOk()
        ->assertJsonPath('approval_status', 'approved');

    $this->actingAs($this->finance)
        ->postJson(route('api.spend.expenses.post', $draft['id']))
        ->assertOk()
        ->assertJsonPath('status', 'posted');

    $this->actingAs($this->finance)
        ->postJson(route('api.spend.expenses.settle', $draft['id']), [
            'payment_method' => 'petty_cash',
        ])
        ->assertOk()
        ->assertJsonPath('status', 'paid');

    $invoice = ApInvoice::query()->findOrFail((int) $draft['id']);

    $this->assertDatabaseHas('ap_payments', [
        'supplier_id' => $this->supplier->id,
        'payment_method' => 'petty_cash',
        'amount' => 100.00,
    ]);

    $this->assertDatabaseHas('ap_payment_allocations', [
        'invoice_id' => $invoice->id,
        'allocated_amount' => 100.00,
    ]);

    $this->assertDatabaseHas('expense_profiles', [
        'invoice_id' => $invoice->id,
        'settlement_mode' => 'petty_cash_wallet',
    ]);

    expect((float) $this->wallet->fresh()->balance)->toBe(round($startingBalance - 100.0, 2));
});

it('returns 410 for legacy expense endpoints', function () {
    $this->actingAs($this->admin)->getJson('/api/expenses')->assertStatus(410);
    $this->actingAs($this->admin)->getJson('/api/expenses/999')->assertStatus(410);
    $this->actingAs($this->admin)->postJson('/api/expenses', [])->assertStatus(410);
    $this->actingAs($this->admin)->putJson('/api/expenses/999', [])->assertStatus(410);
    $this->actingAs($this->admin)->deleteJson('/api/expenses/999')->assertStatus(410);
    $this->actingAs($this->admin)->postJson('/api/expenses/999/payments', [])->assertStatus(410);
    $this->actingAs($this->admin)->postJson('/api/expenses/999/attachments', [])->assertStatus(410);
});
