<?php

use App\Models\ApInvoice;
use App\Models\ExpenseCategory;
use App\Models\Supplier;
use App\Models\User;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::findOrCreate('admin');
    Role::findOrCreate('manager');
    Role::findOrCreate('staff');
    Permission::findOrCreate('finance.access');
    Permission::findOrCreate('accounting.write');
});

it('links finance writers to expense category management from accounts payable', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)
        ->get(route('payables.index'))
        ->assertOk()
        ->assertSee('Expense Categories')
        ->assertSee(route('payables.categories.index'), false);

    $this->get(route('payables.categories.index'))
        ->assertOk()
        ->assertSee('Manage the categories available for AP expenses and petty cash imports.');
});

it('allows an accounting writer to manage categories but denies read-only AP users', function () {
    $writer = User::factory()->create();
    $writer->assignRole('staff');
    $writer->givePermissionTo('accounting.write');

    $reader = User::factory()->create();
    $reader->assignRole('staff');
    $reader->givePermissionTo('finance.access');

    $this->actingAs($writer)
        ->get(route('payables.categories.index'))
        ->assertOk();

    $this->actingAs($reader)
        ->get(route('payables.index'))
        ->assertOk()
        ->assertDontSee(route('payables.categories.index'), false);

    $this->get(route('payables.categories.index'))
        ->assertForbidden();
});

it('creates and validates expense categories', function () {
    $manager = User::factory()->create();
    $manager->assignRole('manager');
    ExpenseCategory::factory()->create(['name' => 'Existing Category']);

    Volt::actingAs($manager);
    $component = Volt::test('payables.categories.index')
        ->set('category_name', '  Kitchen Supplies  ')
        ->set('category_description', '  Daily consumables  ')
        ->call('saveCategory')
        ->assertHasNoErrors()
        ->assertSet('category_name', '');

    $created = ExpenseCategory::query()->where('name', 'Kitchen Supplies')->firstOrFail();
    expect($created->description)->toBe('Daily consumables')
        ->and($created->active)->toBeTrue();
    $this->assertDatabaseHas('accounting_audit_logs', [
        'actor_id' => $manager->id,
        'action' => 'expense_category.created',
        'subject_type' => ExpenseCategory::class,
        'subject_id' => $created->id,
    ]);

    $component
        ->set('category_name', 'Existing Category')
        ->call('saveCategory')
        ->assertHasErrors(['category_name' => 'unique']);
});

it('edits and activates or deactivates an expense category without deleting history', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $category = ExpenseCategory::factory()->create([
        'name' => 'Old Name',
        'description' => 'Old description',
        'active' => true,
    ]);
    $invoice = ApInvoice::factory()->create([
        'category_id' => $category->id,
        'document_type' => 'expense',
        'is_expense' => true,
    ]);

    Volt::actingAs($admin);
    Volt::test('payables.categories.index')
        ->call('editCategory', $category->id)
        ->assertSet('category_name', 'Old Name')
        ->set('category_name', 'Kitchen Operations')
        ->set('category_description', 'Updated description')
        ->call('saveCategory')
        ->assertHasNoErrors()
        ->call('toggleCategory', $category->id);

    $category->refresh();
    expect($category->name)->toBe('Kitchen Operations')
        ->and($category->description)->toBe('Updated description')
        ->and($category->active)->toBeFalse()
        ->and($invoice->fresh()->category_id)->toBe($category->id);

    Volt::test('payables.categories.index')
        ->call('toggleCategory', $category->id);

    expect($category->fresh()->active)->toBeTrue();
});

it('hides inactive categories on new expenses but retains the assigned category while editing', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $assigned = ExpenseCategory::factory()->create(['name' => 'Assigned Inactive', 'active' => false]);
    ExpenseCategory::factory()->create(['name' => 'Other Inactive', 'active' => false]);
    ExpenseCategory::factory()->create(['name' => 'Active Category', 'active' => true]);
    $invoice = ApInvoice::factory()->create([
        'category_id' => $assigned->id,
        'status' => 'draft',
        'document_type' => 'expense',
        'is_expense' => true,
    ]);

    $this->actingAs($admin)
        ->get('/payables/invoices/create?document_type=expense&expense_channel=vendor')
        ->assertOk()
        ->assertSee('Active Category')
        ->assertDontSee('Assigned Inactive')
        ->assertDontSee('Other Inactive');

    $this->get(route('payables.invoices.edit', $invoice))
        ->assertOk()
        ->assertSee('Assigned Inactive')
        ->assertSee('Active Category')
        ->assertDontSee('Other Inactive');
});

it('rejects inactive category IDs submitted directly to AP expense APIs', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $supplier = Supplier::factory()->create();
    $category = ExpenseCategory::factory()->create(['active' => false]);

    $this->actingAs($admin)
        ->postJson('/api/ap/invoices', [
            'supplier_id' => $supplier->id,
            'category_id' => $category->id,
            'document_type' => 'expense',
            'expense_channel' => 'vendor',
            'invoice_number' => 'INACTIVE-CATEGORY-AP',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'tax_amount' => 0,
            'items' => [
                ['description' => 'Invalid category line', 'quantity' => 1, 'unit_price' => 10],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('category_id');

    $this->postJson(route('api.spend.expenses.store'), [
        'channel' => 'vendor',
        'supplier_id' => $supplier->id,
        'category_id' => $category->id,
        'expense_date' => now()->toDateString(),
        'description' => 'Invalid category expense',
        'amount' => 10,
        'tax_amount' => 0,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('category_id');

    expect(ApInvoice::query()->where('category_id', $category->id)->exists())->toBeFalse();
});

it('deactivates categories through the legacy delete API instead of erasing them', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $category = ExpenseCategory::factory()->create(['active' => true]);

    $this->actingAs($admin)
        ->deleteJson(route('api.expense-categories.destroy', $category))
        ->assertNoContent();

    expect($category->fresh())->not->toBeNull()
        ->and($category->fresh()->active)->toBeFalse();
    $this->assertDatabaseHas('accounting_audit_logs', [
        'actor_id' => $admin->id,
        'action' => 'expense_category.deactivated',
        'subject_type' => ExpenseCategory::class,
        'subject_id' => $category->id,
    ]);
});

it('redirects the legacy expense category URL to AP category management', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)
        ->get(route('expenses.categories'))
        ->assertRedirect(route('payables.categories.index'));
});
