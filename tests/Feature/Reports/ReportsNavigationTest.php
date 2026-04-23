<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin', 'web');
});

it('links back to the current report category from a non-accounting report page', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('admin');

    $this->actingAs($user)
        ->get(route('reports.orders'))
        ->assertOk()
        ->assertSee(route('reports.index', ['category' => 'sales']), false);
});
