<?php

use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Orders\KitchenPreparationQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('kitchen', 'web');
    Permission::findOrCreate('kitchen.display', 'web');
    Role::findByName('kitchen', 'web')->givePermissionTo('kitchen.display');
});

it('shows daily preparation totals without customer or financial details', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('kitchen');

    DB::table('user_branch_access')->insert([
        'user_id' => $user->id,
        'branch_id' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $item = MenuItem::factory()->create(['name' => 'Test Lasagna']);

    foreach ([['Draft', 2], ['Delivered', 3], ['Cancelled', 99]] as [$status, $quantity]) {
        $order = Order::factory()->create([
            'branch_id' => 1,
            'status' => $status,
            'scheduled_date' => '2026-09-12',
            'customer_name_snapshot' => 'Private Customer Name',
            'customer_phone_snapshot' => '55555555',
            'delivery_address_snapshot' => 'Private address',
            'total_amount' => 999,
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'menu_item_id' => $item->id,
            'description_snapshot' => 'Test Lasagna',
            'quantity' => $quantity,
            'unit_price' => 199,
            'line_total' => $quantity * 199,
        ]);
    }

    $totals = app(KitchenPreparationQueryService::class)
        ->totalsForDay($user, 1, '2026-09-12');

    expect($totals)->toHaveCount(1)
        ->and((float) $totals->first()->total_quantity)->toBe(5.0);

    $this->actingAs($user)
        ->get('/kitchen/ops/1/2026-09-12')
        ->assertOk()
        ->assertSee('Test Lasagna')
        ->assertDontSee('Private Customer Name')
        ->assertDontSee('55555555')
        ->assertDontSee('Private address')
        ->assertDontSee('999')
        ->assertDontSee('Change status');
});
