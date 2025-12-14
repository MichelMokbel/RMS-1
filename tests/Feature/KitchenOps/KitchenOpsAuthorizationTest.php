<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

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


