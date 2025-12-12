<?php

use App\Models\Supplier;
use App\Models\User;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

it('redirects guests from suppliers index', function () {
    get(route('suppliers.index'))->assertRedirect();
});

it('forbids non-admin users from suppliers index', function () {
    $user = User::factory()->create(['status' => 'active']);
    actingAs($user)->get(route('suppliers.index'))->assertForbidden();
});

it('allows admin users to access suppliers index', function () {
    $user = User::factory()->create(['status' => 'active']);
    $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $user->assignRole($role);

    actingAs($user)->get(route('suppliers.index'))->assertOk();
});
