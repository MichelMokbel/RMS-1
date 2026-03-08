<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function grantBranchAccessForOps(User $user, int $branchId = 1): void
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

it('redirects guests from daily dish ops day', function () {
    $this->get('/daily-dish/ops/1/2025-01-10')->assertRedirect('/login');
});

it('allows admin to view daily dish ops day', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('admin');

    $this->actingAs($user)
        ->get('/daily-dish/ops/1/2025-01-10')
        ->assertStatus(200);
});

it('allows kitchen to view daily dish ops day', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('kitchen');
    grantBranchAccessForOps($user);

    $this->actingAs($user)
        ->get('/daily-dish/ops/1/2025-01-10')
        ->assertStatus(200);
});

it('allows cashier to view daily dish ops day', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('cashier');
    grantBranchAccessForOps($user);

    $this->actingAs($user)
        ->get('/daily-dish/ops/1/2025-01-10')
        ->assertStatus(200);
});

it('forbids a non-privileged user from daily dish ops day', function () {
    $user = User::factory()->create(['status' => 'active']);

    $this->actingAs($user)
        ->get('/daily-dish/ops/1/2025-01-10')
        ->assertStatus(403);
});

it('allows cashier to view manual daily dish create page', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('cashier');
    grantBranchAccessForOps($user);

    $this->actingAs($user)
        ->get('/daily-dish/ops/1/2025-01-10/manual/create')
        ->assertStatus(200);
});

it('forbids kitchen from manual daily dish create page', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('kitchen');

    $this->actingAs($user)
        ->get('/daily-dish/ops/1/2025-01-10/manual/create')
        ->assertStatus(403);
});

