<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('manager', 'web');
});

it('redirects guests from subscriptions index', function () {
    $this->get('/subscriptions')->assertRedirect('/login');
});

it('allows admin to view subscriptions index', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('admin');

    $this->actingAs($user)
        ->get('/subscriptions')
        ->assertStatus(200);
});

it('forbids non-privileged user', function () {
    $user = User::factory()->create(['status' => 'active']);

    $this->actingAs($user)
        ->get('/subscriptions')
        ->assertStatus(403);
});

