<?php

use App\Models\User;
use App\Services\Security\IamUserService;
use App\Services\Security\RolePermissionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function iamAdmin(): User
{
    Role::findOrCreate('admin', 'web');
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('admin');

    return $user;
}

test('admin can access iam users hub', function () {
    $this->actingAs(iamAdmin())
        ->get(route('iam.users.index'))
        ->assertOk();
});

test('non-admin cannot access iam users hub', function () {
    $user = User::factory()->create(['status' => 'active']);

    $this->actingAs($user)
        ->get(route('iam.users.index'))
        ->assertForbidden();
});

test('cannot deactivate the last active admin from iam edit', function () {
    $admin = iamAdmin();

    expect(fn () => app(IamUserService::class)->update($admin, [
        'status' => 'inactive',
        'roles' => ['admin'],
        'permissions' => [],
        'branch_ids' => [],
        'pos_enabled' => true,
    ], $admin))->toThrow(ValidationException::class);
});

test('user with direct reports permission can access reports index without role', function () {
    Permission::findOrCreate('reports.access', 'web');

    $user = User::factory()->create(['status' => 'active']);
    $user->givePermissionTo('reports.access');

    $this->actingAs($user)
        ->get(route('reports.index'))
        ->assertOk();
});

test('branch allowlist denies disallowed branch on branch scoped route', function () {
    Permission::findOrCreate('orders.access', 'web');

    DB::table('branches')->insertOrIgnore([
        'id' => 1,
        'name' => 'B1',
        'code' => 'B1',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('branches')->insertOrIgnore([
        'id' => 2,
        'name' => 'B2',
        'code' => 'B2',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $user = User::factory()->create(['status' => 'active']);
    $user->givePermissionTo('orders.access');
    DB::table('user_branch_access')->insertOrIgnore([
        'user_id' => $user->id,
        'branch_id' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('orders.print', ['branch_id' => 2]))
        ->assertForbidden();
});

test('admin can edit role permissions from iam roles page', function () {
    $admin = iamAdmin();
    $role = Role::findOrCreate('waiter', 'web');
    Permission::findOrCreate('orders.access', 'web');
    app(RolePermissionService::class)->updateRolePermissions($admin, $role, ['orders.access']);

    expect($role->fresh()->hasPermissionTo('orders.access'))->toBeTrue();
});
