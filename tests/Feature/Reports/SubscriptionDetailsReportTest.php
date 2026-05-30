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

it('shows renewed state and renewal code on the subscription details report', function () {
    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');

    $customer = Customer::factory()->create(['name' => 'Report Renewal Customer']);

    MealSubscription::factory()->create([
        'subscription_code' => 'SUB-REPORT-OLD',
        'customer_id' => $customer->id,
        'branch_id' => 1,
        'status' => 'expired',
        'start_date' => '2025-01-01',
        'end_date' => '2025-01-31',
    ]);

    MealSubscription::factory()->create([
        'subscription_code' => 'SUB-REPORT-NEW',
        'customer_id' => $customer->id,
        'branch_id' => 1,
        'status' => 'active',
        'start_date' => '2025-02-05',
    ]);

    $this->actingAs($admin);

    Volt::test('reports.subscription-details')
        ->set('date_from', '2025-01-01')
        ->set('date_to', '2025-12-31')
        ->assertSee('SUB-REPORT-OLD')
        ->assertSee('Renewed')
        ->assertSee('Renewed by SUB-REPORT-NEW');
});

it('filters the subscription details report to expired subscriptions that were not renewed', function () {
    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');

    $renewedCustomer = Customer::factory()->create(['name' => 'Renewed Customer']);
    $notRenewedCustomer = Customer::factory()->create(['name' => 'Not Renewed Customer']);

    MealSubscription::factory()->create([
        'subscription_code' => 'SUB-EXPIRED-RENEWED',
        'customer_id' => $renewedCustomer->id,
        'branch_id' => 1,
        'status' => 'expired',
        'start_date' => '2025-01-01',
        'end_date' => '2025-01-31',
        'created_at' => '2025-01-01 09:00:00',
    ]);

    MealSubscription::factory()->create([
        'subscription_code' => 'SUB-EXPIRED-RENEWED-NEXT',
        'customer_id' => $renewedCustomer->id,
        'branch_id' => 1,
        'status' => 'active',
        'start_date' => '2025-01-20',
        'created_at' => '2025-02-01 09:00:00',
    ]);

    MealSubscription::factory()->create([
        'subscription_code' => 'SUB-EXPIRED-NOT-RENEWED',
        'customer_id' => $notRenewedCustomer->id,
        'branch_id' => 1,
        'status' => 'expired',
        'start_date' => '2025-02-01',
        'end_date' => '2025-02-28',
        'created_at' => '2025-02-01 09:00:00',
    ]);

    $this->actingAs($admin);

    Volt::test('reports.subscription-details')
        ->set('date_from', '2025-01-01')
        ->set('date_to', '2025-12-31')
        ->set('renewal_state', 'expired_not_renewed')
        ->assertSee('SUB-EXPIRED-NOT-RENEWED')
        ->assertDontSee('SUB-EXPIRED-RENEWED')
        ->assertSee('Not Renewed');
});
