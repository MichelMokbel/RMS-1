<?php

use App\Models\MenuItem;
use App\Models\User;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

function apiMenuAdmin(): User
{
    $user = User::factory()->create(['status' => 'active']);
    $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $user->assignRole($role);
    return $user;
}

it('returns light menu items list', function () {
    MenuItem::factory()->count(2)->create(['is_active' => true]);
    MenuItem::factory()->inactive()->create();

    $user = apiMenuAdmin();
    $res = actingAs($user)->getJson('/api/menu-items');
    $res->assertOk();
    $data = $res->json();
    expect($data)->toBeArray();
});
