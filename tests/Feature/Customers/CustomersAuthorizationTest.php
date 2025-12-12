<?php

use App\Models\User;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

it('redirects guests from customers index', function () {
    get(route('customers.index'))->assertRedirect();
});

it('forbids non-privileged users', function () {
    $user = User::factory()->create(['status' => 'active']);
    actingAs($user)->get(route('customers.index'))->assertForbidden();
});

it('allows admin to view customers', function () {
    $user = User::factory()->create(['status' => 'active']);
    $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $user->assignRole($role);

    actingAs($user)->get(route('customers.index'))->assertOk();
});
