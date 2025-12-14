<?php

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Role::findOrCreate('admin', 'web');
});

it('blocks deleting category in use', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('admin');
    $cat = ExpenseCategory::factory()->create();
    Expense::factory()->create(['category_id' => $cat->id]);

    $this->actingAs($user);
    $resp = $this->deleteJson(route('api.expense-categories.destroy', $cat));
    $resp->assertStatus(422);
});
