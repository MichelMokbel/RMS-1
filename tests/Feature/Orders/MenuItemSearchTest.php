<?php

use App\Models\MenuItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin');

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    DB::table('branches')->updateOrInsert(
        ['id' => 1],
        [
            'name' => 'Branch 1',
            'code' => 'B1',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]
    );
});

it('matches menu items regardless of token order', function () {
    $item = MenuItem::factory()->create([
        'code' => 'MI-000123',
        'name' => 'Beef Stroganoff Plate',
        'is_active' => true,
    ]);

    DB::table('menu_item_branches')->updateOrInsert(
        ['menu_item_id' => $item->id, 'branch_id' => 1],
        ['created_at' => now(), 'updated_at' => now()]
    );

    $this->actingAs($this->admin)
        ->get(route('orders.menu-items.search', ['q' => 'stroganoff beef', 'branch_id' => 1]))
        ->assertOk()
        ->assertJsonFragment([
            'id' => $item->id,
            'name' => 'Beef Stroganoff Plate',
            'code' => 'MI-000123',
        ]);
});
