<?php

use App\Models\MenuItem;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

function apiMenuAdmin(): User
{
    $user = User::factory()->create(['status' => 'active']);
    $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $user->assignRole($role);
    return $user;
}

it('returns light menu items list', function () {
    MenuItem::factory()->count(2)->create(['is_active' => true]);
    MenuItem::factory()->inactive()->create();

    $user = apiMenuAdmin();
    $res = actingAs($user)->getJson('/api/menu-items');
    $res->assertOk();
    $data = $res->json();
    expect($data)->toBeArray();
});

it('rejects inactive branch for branch-scoped menu items api', function () {
    if (! Schema::hasTable('branches') || ! Schema::hasColumn('branches', 'is_active')) {
        $this->markTestSkipped('Branches table/is_active not available.');
    }

    DB::table('branches')->insert([
        'id' => 999,
        'name' => 'Inactive',
        'is_active' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $user = apiMenuAdmin();
    $res = actingAs($user)->getJson('/api/menu-items?branch_id=999');
    $res->assertStatus(422);
});

it('auto-generates menu item code when api payload omits it', function () {
    $user = apiMenuAdmin();

    actingAs($user)->postJson('/api/menu-items', [
        'name' => 'API Generated Menu Item',
        'selling_price_per_unit' => 10,
        'tax_rate' => 0,
        'is_active' => true,
        'display_order' => 1,
    ])->assertCreated();

    $item = MenuItem::query()->where('name', 'API Generated Menu Item')->firstOrFail();
    expect($item->code)->toBe('MI-000001');
});
