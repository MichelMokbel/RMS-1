<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('manager', 'web');
    Role::findOrCreate('staff', 'web');
    Role::findOrCreate('cashier', 'web');
});

it('redirects guests from reports index', function () {
    $this->get(route('reports.index'))->assertRedirect(route('login'));
});

it('allows admin to view reports index', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('admin');
    $this->actingAs($user)->get(route('reports.index'))->assertStatus(200);
});

it('allows manager to view reports index', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('manager');
    $this->actingAs($user)->get(route('reports.index'))->assertStatus(200);
});

it('allows staff to view reports index', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('staff');
    $this->actingAs($user)->get(route('reports.index'))->assertStatus(200);
});

it('forbids user without admin or manager or staff role from reports index', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('cashier');
    $this->actingAs($user)->get(route('reports.index'))->assertStatus(403);
});

it('allows manager to view orders report', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('manager');
    $this->actingAs($user)->get(route('reports.orders'))->assertStatus(200);
});

it('allows staff to view orders report print', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('staff');
    $this->actingAs($user)->get(route('reports.orders.print'))->assertStatus(200);
});

it('redirects guests from reports orders csv', function () {
    $this->get(route('reports.orders.csv'))->assertRedirect(route('login'));
});
