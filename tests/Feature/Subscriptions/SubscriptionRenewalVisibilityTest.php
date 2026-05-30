<?php

use App\Models\Customer;
use App\Models\MealSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function seedRenewalVisibilityBranch(int $id = 1): void
{
    DB::table('branches')->updateOrInsert(
        ['id' => $id],
        ['name' => 'Main Branch', 'code' => 'MAIN', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]
    );
}

beforeEach(function () {
    Role::findOrCreate('admin', 'web');
    seedRenewalVisibilityBranch();
});

it('shows expired renewed and expired not renewed labels on the subscriptions index', function () {
    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');

    $customer = Customer::factory()->create();

    MealSubscription::factory()->create([
        'subscription_code' => 'SUB-OLD-RENEWED',
        'customer_id' => $customer->id,
        'branch_id' => 1,
        'status' => 'expired',
        'start_date' => '2025-01-01',
        'end_date' => '2025-01-31',
    ]);

    MealSubscription::factory()->create([
        'subscription_code' => 'SUB-NEW-RENEWAL',
        'customer_id' => $customer->id,
        'branch_id' => 1,
        'status' => 'active',
        'start_date' => '2025-02-05',
    ]);

    MealSubscription::factory()->create([
        'subscription_code' => 'SUB-EXPIRED-NOT-RENEWED',
        'customer_id' => Customer::factory()->create()->id,
        'branch_id' => 1,
        'status' => 'expired',
        'start_date' => '2025-03-01',
        'end_date' => '2025-03-31',
    ]);

    $response = $this->actingAs($admin)->get(route('subscriptions.index'));

    $response->assertOk();
    $response->assertSee('SUB-OLD-RENEWED');
    $response->assertSee('Renewed');
    $response->assertSee('SUB-EXPIRED-NOT-RENEWED');
    $response->assertSee('Not Renewed');
});

it('shows the renewal successor on the subscription show page', function () {
    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');

    $customer = Customer::factory()->create(['name' => 'Renewal Customer']);

    $expired = MealSubscription::factory()->create([
        'subscription_code' => 'SUB-SHOW-OLD',
        'customer_id' => $customer->id,
        'branch_id' => 1,
        'status' => 'expired',
        'start_date' => '2025-01-01',
        'end_date' => '2025-01-31',
    ]);

    $renewal = MealSubscription::factory()->create([
        'subscription_code' => 'SUB-SHOW-NEW',
        'customer_id' => $customer->id,
        'branch_id' => 1,
        'status' => 'active',
        'start_date' => '2025-02-10',
    ]);

    $response = $this->actingAs($admin)->get(route('subscriptions.show', $expired));

    $response->assertOk();
    $response->assertSee('Renewed by');
    $response->assertSee($renewal->subscription_code);
    $response->assertSee('View renewal');
});
