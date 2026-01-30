<?php

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('manager', 'web');
});

it('orders report print respects status filter', function () {
    Order::factory()->create(['status' => 'Draft', 'order_number' => 'ORD-001']);
    Order::factory()->create(['status' => 'Confirmed', 'order_number' => 'ORD-002']);
    Order::factory()->create(['status' => 'Draft', 'order_number' => 'ORD-003']);

    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('manager');

    $response = $this->actingAs($user)->get(route('reports.orders.print').'?status=Draft');
    $response->assertStatus(200);
    $response->assertSee('ORD-001');
    $response->assertSee('ORD-003');
    $response->assertDontSee('ORD-002');
});
