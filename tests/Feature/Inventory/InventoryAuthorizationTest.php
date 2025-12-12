<?php

use App\Models\User;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

it('redirects guests from inventory index', function () {
    get(route('inventory.index'))->assertRedirect();
});

it('allows admin to view inventory', function () {
    $user = User::factory()->create(['status' => 'active']);
    $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $user->assignRole($role);

    actingAs($user)->get(route('inventory.index'))->assertOk();
});
