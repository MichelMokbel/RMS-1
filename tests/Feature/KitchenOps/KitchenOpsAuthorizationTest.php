<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function grantBranchAccessForKitchen(User $user, int $branchId = 1): void
{
    DB::table('branches')->insertOrIgnore([
        'id' => $branchId,
        'name' => 'Branch '.$branchId,
        'code' => 'B'.$branchId,
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('user_branch_access')->insertOrIgnore([
        'user_id' => (int) $user->id,
        'branch_id' => $branchId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

beforeEach(function () {
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('manager', 'web');
    Role::findOrCreate('cashier', 'web');
    Role::findOrCreate('kitchen', 'web');
    Permission::findOrCreate('kitchen.display', 'web');
    Role::findByName('kitchen', 'web')->givePermissionTo('kitchen.display');
});

it('redirects guests from kitchen ops', function () {
    $this->get('/kitchen/ops/1/2025-01-10')->assertRedirect('/login');
});

it('allows kitchen to view kitchen ops', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('kitchen');
    grantBranchAccessForKitchen($user);

    $this->actingAs($user)
        ->get('/kitchen/ops/1/2025-01-10')
        ->assertStatus(200);
});

it('forbids non-privileged user from kitchen ops', function () {
    $user = User::factory()->create(['status' => 'active']);

    $this->actingAs($user)
        ->get('/kitchen/ops/1/2025-01-10')
        ->assertStatus(403);
});

it('forbids kitchen users from another branch', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('kitchen');
    grantBranchAccessForKitchen($user, 1);
    grantBranchAccessForKitchen(User::factory()->create(), 2);

    $this->actingAs($user)
        ->get('/kitchen/ops/2/2025-01-10')
        ->assertStatus(403);
});

it('redirects kitchen users from the dashboard to preparation totals', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('kitchen');
    grantBranchAccessForKitchen($user);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertRedirect(route('kitchen.ops', [1, now()->toDateString()]));
});
