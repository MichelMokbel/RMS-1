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

it('filters subscriptions by customer name from the index search', function () {
    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');

    $matchingCustomer = Customer::factory()->create(['name' => 'Bilal Sinno']);
    $otherCustomer = Customer::factory()->create(['name' => 'Mazen Aridi']);

    MealSubscription::factory()->create([
        'subscription_code' => 'SUB-SEARCH-MATCH',
        'customer_id' => $matchingCustomer->id,
        'branch_id' => 1,
        'status' => 'active',
    ]);

    MealSubscription::factory()->create([
        'subscription_code' => 'SUB-SEARCH-OTHER',
        'customer_id' => $otherCustomer->id,
        'branch_id' => 1,
        'status' => 'active',
    ]);

    $this->actingAs($admin);

    Volt::test('subscriptions.index')
        ->set('search', 'Bilal')
        ->assertSee('SUB-SEARCH-MATCH')
        ->assertSee('Bilal Sinno')
        ->assertDontSee('SUB-SEARCH-OTHER')
        ->assertDontSee('Mazen Aridi');
});
