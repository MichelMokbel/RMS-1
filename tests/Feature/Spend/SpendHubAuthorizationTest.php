<?php

use App\Models\ExpenseCategory;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $adminRole = Role::findOrCreate('admin', 'web');
    $managerRole = Role::findOrCreate('manager', 'web');
    $staffRole = Role::findOrCreate('staff', 'web');
    Permission::findOrCreate('finance.access', 'web');

    $adminRole->syncPermissions([]);
    $managerRole->syncPermissions([]);
    $staffRole->syncPermissions([]);

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

    Storage::fake('public');
});

function createAndSubmitApprovedCandidate(User $creator): int
{
    $invoice = test()->actingAs($creator)->postJson(route('api.spend.expenses.store'), [
        'channel' => 'vendor',
        'supplier_id' => Supplier::query()->firstOrFail()->id,
        'category_id' => ExpenseCategory::query()->firstOrFail()->id,
        'expense_date' => now()->toDateString(),
        'description' => 'Authorization test',
        'amount' => 100,
        'tax_amount' => 0,
    ])->assertCreated()->json();

    test()->actingAs($creator)
        ->post(route('api.spend.expenses.attachments.store', (int) $invoice['id']), [
            'file' => UploadedFile::fake()->image('receipt.jpg'),
        ])
        ->assertCreated();

    test()->actingAs($creator)
        ->postJson(route('api.spend.expenses.submit', (int) $invoice['id']))
        ->assertOk();

    return (int) $invoice['id'];
}

it('redirects guests from spend hub', function () {
    $this->get('/spend')->assertRedirect('/login');
});

it('allows staff to create and submit but blocks approvals and accounting actions', function () {
    $invoiceId = createAndSubmitApprovedCandidate($this->staff);

    $this->actingAs($this->staff)
        ->postJson(route('api.spend.expenses.approve', $invoiceId), ['stage' => 'manager'])
        ->assertStatus(403);

    $this->actingAs($this->staff)
        ->postJson(route('api.spend.expenses.reject', $invoiceId), ['reason' => 'No'])
        ->assertStatus(403);

    $this->actingAs($this->staff)
        ->postJson(route('api.spend.expenses.post', $invoiceId))
        ->assertStatus(403);

    $this->actingAs($this->staff)
        ->postJson(route('api.spend.expenses.settle', $invoiceId), [])
        ->assertStatus(403);
});

it('allows manager stage approval and rejection but blocks finance stage and accounting', function () {
    $invoiceId = createAndSubmitApprovedCandidate($this->staff);

    $this->actingAs($this->manager)
        ->postJson(route('api.spend.expenses.approve', $invoiceId), ['stage' => 'manager'])
        ->assertOk();

    $invoiceId2 = createAndSubmitApprovedCandidate($this->staff);

    $this->actingAs($this->manager)
        ->postJson(route('api.spend.expenses.reject', $invoiceId2), ['reason' => 'Rejected by manager'])
        ->assertOk();

    $invoiceId3 = createAndSubmitApprovedCandidate($this->staff);

    $this->actingAs($this->manager)
        ->postJson(route('api.spend.expenses.approve', $invoiceId3), ['stage' => 'finance'])
        ->assertStatus(403);

    $this->actingAs($this->manager)
        ->postJson(route('api.spend.expenses.post', $invoiceId3))
        ->assertStatus(403);

    $this->actingAs($this->manager)
        ->postJson(route('api.spend.expenses.settle', $invoiceId3), [])
        ->assertStatus(403);
});

it('allows finance user to run finance approval posting and settlement', function () {
    $invoiceId = createAndSubmitApprovedCandidate($this->staff);

    // Force exception path so finance stage is required.
    $this->actingAs($this->manager)
        ->postJson(route('api.spend.expenses.reject', $invoiceId), ['reason' => 'reset'])
        ->assertOk();

    $invoice2 = test()->actingAs($this->staff)->postJson(route('api.spend.expenses.store'), [
        'channel' => 'vendor',
        'supplier_id' => $this->supplier->id,
        'category_id' => $this->category->id,
        'expense_date' => now()->toDateString(),
        'description' => 'Needs finance',
        'amount' => 1500,
        'tax_amount' => 0,
    ])->assertCreated()->json();

    $invoiceId2 = (int) $invoice2['id'];

    $this->actingAs($this->staff)
        ->postJson(route('api.spend.expenses.submit', $invoiceId2))
        ->assertOk();

    $this->actingAs($this->manager)
        ->postJson(route('api.spend.expenses.approve', $invoiceId2), ['stage' => 'manager'])
        ->assertOk()
        ->assertJsonPath('approval_status', 'manager_approved');

    $this->actingAs($this->finance)
        ->postJson(route('api.spend.expenses.approve', $invoiceId2), ['stage' => 'finance'])
        ->assertOk()
        ->assertJsonPath('approval_status', 'approved');

    $this->actingAs($this->finance)
        ->postJson(route('api.spend.expenses.post', $invoiceId2))
        ->assertOk()
        ->assertJsonPath('status', 'posted');

    $this->actingAs($this->finance)
        ->postJson(route('api.spend.expenses.settle', $invoiceId2), [])
        ->assertOk()
        ->assertJsonPath('status', 'paid');
});

it('blocks self approval for submitter', function () {
    $invoiceId = createAndSubmitApprovedCandidate($this->manager);

    $this->actingAs($this->manager)
        ->postJson(route('api.spend.expenses.approve', $invoiceId), ['stage' => 'manager'])
        ->assertStatus(422);
});

it('allows admin to perform all actions', function () {
    $invoiceId = createAndSubmitApprovedCandidate($this->staff);

    $this->actingAs($this->admin)
        ->postJson(route('api.spend.expenses.approve', $invoiceId), ['stage' => 'manager'])
        ->assertOk()
        ->assertJsonPath('approval_status', 'approved');

    $this->actingAs($this->admin)
        ->postJson(route('api.spend.expenses.post', $invoiceId))
        ->assertOk()
        ->assertJsonPath('status', 'posted');

    $this->actingAs($this->admin)
        ->postJson(route('api.spend.expenses.settle', $invoiceId), [])
        ->assertOk()
        ->assertJsonPath('status', 'paid');
});
