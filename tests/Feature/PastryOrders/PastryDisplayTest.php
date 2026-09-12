<?php

use App\Models\PastryOrder;
use App\Models\PastryOrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('pastry-user', 'web');
    Role::findOrCreate('manager', 'web');
    Permission::findOrCreate('pastry.display', 'web');
    Permission::findOrCreate('pastry-orders.manage', 'web');
    Role::findByName('pastry-user', 'web')->givePermissionTo('pastry.display');
});

function makePastryDisplayOrder(array $attributes = []): PastryOrder
{
    $order = PastryOrder::create(array_merge([
        'order_number' => 'PO-TEST-1',
        'branch_id' => 1,
        'status' => 'Draft',
        'type' => 'Delivery',
        'customer_name_snapshot' => 'Pastry Customer',
        'delivery_address_snapshot' => 'West Bay, Building 10',
        'scheduled_date' => '2026-09-12',
        'scheduled_time' => '15:30:00',
        'notes' => 'Write Happy Birthday',
        'total_before_tax' => 999,
        'total_amount' => 999,
    ], $attributes));

    PastryOrderItem::create([
        'pastry_order_id' => $order->id,
        'menu_item_id' => null,
        'description_snapshot' => 'Chocolate Cake',
        'quantity' => 1,
        'unit_price' => 999,
        'discount_amount' => 0,
        'line_total' => 999,
        'status' => 'Pending',
        'sort_order' => 0,
    ]);

    return $order;
}

it('shows pastry production details without prices or management actions', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('pastry-user');
    DB::table('user_branch_access')->insert([
        'user_id' => $user->id,
        'branch_id' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    makePastryDisplayOrder();
    makePastryDisplayOrder(['order_number' => 'PO-CANCELLED', 'status' => 'Cancelled']);

    $this->actingAs($user)
        ->get('/pastry-orders/display/1/2026-09-12')
        ->assertOk()
        ->assertSee('PO-TEST-1')
        ->assertSee('Pastry Customer')
        ->assertSee('Chocolate Cake')
        ->assertSee('West Bay, Building 10')
        ->assertSee('Write Happy Birthday')
        ->assertDontSee('PO-CANCELLED')
        ->assertDontSee('999')
        ->assertDontSee('Edit')
        ->assertDontSee('Invoice');

    $this->actingAs($user)->get('/pastry-orders')->assertForbidden();
});

it('forbids pastry users from another branch', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('pastry-user');
    DB::table('user_branch_access')->insert([
        'user_id' => $user->id,
        'branch_id' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('branches')->insert([
        'id' => 2,
        'name' => 'Branch 2',
        'code' => 'B2',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($user)
        ->get('/pastry-orders/display/2/2026-09-12')
        ->assertForbidden();
});

it('scopes pastry management print views to the managers assigned branches', function () {
    $manager = User::factory()->create(['status' => 'active']);
    $manager->assignRole('manager');
    DB::table('user_branch_access')->insert([
        'user_id' => $manager->id,
        'branch_id' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('branches')->insert([
        'id' => 2,
        'name' => 'Branch 2',
        'code' => 'B2',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    makePastryDisplayOrder(['order_number' => 'PO-ALLOWED', 'branch_id' => 1]);
    $forbidden = makePastryDisplayOrder(['order_number' => 'PO-OTHER-BRANCH', 'branch_id' => 2]);

    $this->actingAs($manager)
        ->get('/pastry-orders/print/all?scheduled_date=2026-09-12')
        ->assertOk()
        ->assertSee('PO-ALLOWED')
        ->assertDontSee('PO-OTHER-BRANCH');

    $this->actingAs($manager)
        ->get('/pastry-orders/'.$forbidden->id.'/print')
        ->assertNotFound();
});
