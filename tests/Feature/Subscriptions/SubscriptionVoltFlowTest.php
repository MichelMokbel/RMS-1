<?php

use App\Models\Customer;
use App\Models\MealSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin', 'web');

    DB::table('branches')->updateOrInsert(
        ['id' => 1],
        ['name' => 'Main Branch', 'code' => 'MAIN', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]
    );
});

it('redirects to the new subscription after create save', function () {
    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');
    $customer = Customer::factory()->create();

    $this->actingAs($admin);

    $response = Volt::test('subscriptions.create')
        ->set('customer_id', $customer->id)
        ->set('branch_id', 1)
        ->set('status', 'active')
        ->set('start_date', '2025-01-01')
        ->set('default_order_type', 'Delivery')
        ->set('preferred_role', 'main')
        ->set('weekdays', [1, 2, 3]);

    $response->call('save')->assertHasNoErrors();

    $subscription = MealSubscription::query()->latest('id')->firstOrFail();

    $response->assertRedirect(route('subscriptions.show', $subscription, absolute: false));
});

it('redirects back to the subscription after edit save', function () {
    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');
    $customer = Customer::factory()->create();
    $subscription = MealSubscription::factory()->create([
        'customer_id' => $customer->id,
        'branch_id' => 1,
        'status' => 'active',
        'start_date' => '2025-01-01',
        'end_date' => null,
        'default_order_type' => 'Delivery',
        'preferred_role' => 'main',
    ]);

    DB::table('meal_subscription_days')->insert([
        'subscription_id' => $subscription->id,
        'weekday' => 1,
    ]);

    $this->actingAs($admin);

    $response = Volt::test('subscriptions.edit', ['subscription' => $subscription])
        ->set('notes', 'Updated notes')
        ->set('weekdays', [1, 2, 3]);

    $response->call('save')->assertHasNoErrors();

    $response->assertRedirect(route('subscriptions.show', $subscription->fresh(), absolute: false));
    expect($subscription->fresh()->notes)->toBe('Updated notes');
});
