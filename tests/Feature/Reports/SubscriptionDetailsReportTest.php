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
