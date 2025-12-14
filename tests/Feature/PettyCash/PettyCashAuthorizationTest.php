<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('redirects guests from petty cash index', function () {
    $this->get('/petty-cash')->assertRedirect('/login');
});

it('allows admin role to view petty cash index', function () {
    $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)
        ->get('/petty-cash')
        ->assertStatus(200);
});
