<?php

use App\Models\User;
use Spatie\Permission\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('redirects guest from purchase orders', function () {
    $this->get('/purchase-orders')->assertRedirect('/login');
});

it('allows admin to view purchase orders', function () {
    Role::findOrCreate('admin');
    $user = User::factory()->create();
    $user->assignRole('admin');

    $this->actingAs($user)
        ->get('/purchase-orders')
        ->assertOk();
});
