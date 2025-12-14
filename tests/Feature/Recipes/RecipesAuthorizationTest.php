<?php

use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('manager', 'web');
});

it('redirects guests from recipes index', function () {
    $this->get('/recipes')->assertRedirect('/login');
});

it('allows admin role to view recipes index', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('admin');

    $this->actingAs($user)
        ->get('/recipes')
        ->assertStatus(200);
});

it('forbids non-manager/admin roles', function () {
    $user = User::factory()->create(['status' => 'active']);

    $this->actingAs($user)
        ->get('/recipes')
        ->assertStatus(403);
});

it('shows recipe show page for admin', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('admin');
    $recipe = Recipe::factory()->create();

    $this->actingAs($user)
        ->get(route('recipes.show', $recipe))
        ->assertStatus(200);
});

