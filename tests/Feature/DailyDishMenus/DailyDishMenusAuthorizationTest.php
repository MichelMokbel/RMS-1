<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('manager', 'web');
    Role::findOrCreate('kitchen', 'web');
});

it('redirects guests from planner', function () {
    $this->get('/daily-dish/menus')->assertRedirect('/login');
});

it('allows admin to view planner', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('admin');

    $this->actingAs($user)
        ->get('/daily-dish/menus')
        ->assertStatus(200);
});

it('forbids non-privileged user', function () {
    $user = User::factory()->create(['status' => 'active']);

    $this->actingAs($user)
        ->get('/daily-dish/menus')
        ->assertStatus(403);
});

