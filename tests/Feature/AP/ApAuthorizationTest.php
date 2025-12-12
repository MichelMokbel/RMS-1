<?php

use App\Models\User;
use Spatie\Permission\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin');
});

it('redirects guest from payables index', function () {
    $this->get('/payables')->assertRedirect('/login');
});

it('allows admin to hit api invoices', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');
    $this->actingAs($user)->get('/api/ap/invoices')->assertOk();
});
