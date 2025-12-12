<?php

use App\Models\User;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function createAdminUser(): User
{
    $admin = User::factory()->create([
        'username' => 'admin',
        'password' => 'password',
        'status' => 'active',
    ]);

    $role = Role::firstOrCreate(['name' => 'admin']);
    $admin->assignRole($role);

    return $admin;
}

test('admin can create a user', function () {
    $this->actingAs(createAdminUser());

    Volt::test('users.create')
        ->set('username', 'new-user')
        ->set('email', 'new@example.com')
        ->set('password', 'secret123')
        ->set('password_confirmation', 'secret123')
        ->set('status', 'active')
        ->call('save')
        ->assertRedirect(route('users.index'));

    expect(User::where('username', 'new-user')->exists())->toBeTrue();
});

test('admin can edit a user', function () {
    $this->actingAs(createAdminUser());

    $user = User::factory()->create([
        'username' => 'edit-me',
        'email' => 'edit@example.com',
        'status' => 'active',
    ]);

    Volt::test('users.edit', ['user' => $user])
        ->set('username', 'edited-user')
        ->set('email', 'edited@example.com')
        ->set('status', 'active')
        ->call('updateUser')
        ->assertRedirect(route('users.index'));

    $user->refresh();

    expect($user->username)->toEqual('edited-user');
    expect($user->email)->toEqual('edited@example.com');
});

test('admin can deactivate a user', function () {
    $this->actingAs(createAdminUser());

    $user = User::factory()->create([
        'status' => 'active',
    ]);

    Volt::test('users.edit', ['user' => $user])
        ->call('toggleStatus');

    expect($user->fresh()->status)->toEqual('inactive');
});
