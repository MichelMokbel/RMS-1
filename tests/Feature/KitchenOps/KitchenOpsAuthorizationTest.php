<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

