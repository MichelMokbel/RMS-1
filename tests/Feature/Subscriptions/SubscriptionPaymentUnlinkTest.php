<?php

use App\Models\Customer;
use App\Models\MealSubscription;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('manager', 'web');

    DB::table('branches')->updateOrInsert(
        ['id' => 1],
        ['name' => 'Main Branch', 'code' => 'MAIN', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]
    );
});

function makeFinanceWriter(): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $role = Role::firstOrCreate(['name' => 'manager'], ['guard_name' => 'web']);
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole($role);

    return $user;
}

it('can unlink a payment from the subscription show page', function () {
    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');

    $customer = Customer::factory()->create();
    $payment = Payment::factory()->create([
        'customer_id' => $customer->id,
        'branch_id' => 1,
    ]);

    $subscription = MealSubscription::factory()->create([
        'customer_id' => $customer->id,
        'branch_id' => 1,
        'source_payment_id' => $payment->id,
        'uses_invoice_tracking' => true,
        'meals_used' => 7,
    ]);

    Volt::actingAs($admin);

    Volt::test('subscriptions.show', ['subscription' => $subscription])
        ->call('unlinkPayment')
        ->assertHasNoErrors();

    $subscription->refresh();

    expect($subscription->source_payment_id)->toBeNull();
    expect($subscription->uses_invoice_tracking)->toBeFalse();
    expect($subscription->meals_used)->toBe(7);
});

it('can unlink a subscription from the payment show page', function () {
    $user = makeFinanceWriter();

    $customer = Customer::factory()->create();
    $payment = Payment::factory()->create([
        'customer_id' => $customer->id,
        'branch_id' => 1,
        'source' => 'ar',
    ]);

    $subscription = MealSubscription::factory()->create([
        'customer_id' => $customer->id,
        'branch_id' => 1,
        'source_payment_id' => $payment->id,
        'uses_invoice_tracking' => true,
        'meals_used' => 4,
    ]);

    Volt::actingAs($user);

    Volt::test('receivables.payments.show', ['payment' => $payment])
        ->call('unlinkSubscription', $subscription->id)
        ->assertHasNoErrors();

    $subscription->refresh();

    expect($subscription->source_payment_id)->toBeNull();
    expect($subscription->uses_invoice_tracking)->toBeFalse();
    expect($subscription->meals_used)->toBe(4);
});
