<?php

use App\Models\InventoryItem;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin', 'web');
});

if (! function_exists('adminUser')) {
    function adminUser(): User
    {
        $u = User::factory()->create(['status' => 'active']);
        $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $u->assignRole($role);
        return $u;
    }
}

it('creates recipe with items transactionally', function () {
    $user = adminUser();
    $inv = InventoryItem::factory()->create();

    $recipeId = DB::transaction(function () use ($inv) {
        $recipe = Recipe::create([
            'name' => 'Test Recipe',
            'yield_quantity' => 1.0,
            'yield_unit' => 'kg',
            'overhead_pct' => 0.1,
        ]);

        RecipeItem::create([
            'recipe_id' => $recipe->id,
            'inventory_item_id' => $inv->id,
            'quantity' => 1,
            'unit' => 'kg',
            'quantity_type' => 'unit',
            'cost_type' => 'ingredient',
        ]);

        return $recipe->id;
    });

    expect(Recipe::find($recipeId))->not()->toBeNull();
    expect(RecipeItem::where('recipe_id', $recipeId)->count())->toBe(1);
});

it('updates recipe by re-syncing items', function () {
    $user = adminUser();
    $inv1 = InventoryItem::factory()->create();
    $inv2 = InventoryItem::factory()->create();

    $recipe = Recipe::factory()->create([
        'yield_quantity' => 1,
        'yield_unit' => 'kg',
    ]);
    $item = RecipeItem::create([
        'recipe_id' => $recipe->id,
        'inventory_item_id' => $inv1->id,
        'quantity' => 1,
        'unit' => 'kg',
        'quantity_type' => 'unit',
        'cost_type' => 'ingredient',
    ]);

    DB::transaction(function () use ($recipe, $inv2) {
        $recipe->update([
            'name' => 'Updated',
        ]);

        $recipe->items()->delete();

        RecipeItem::create([
            'recipe_id' => $recipe->id,
            'inventory_item_id' => $inv2->id,
            'quantity' => 2,
            'unit' => 'kg',
            'quantity_type' => 'unit',
            'cost_type' => 'ingredient',
        ]);
    });

    $recipe->refresh();
    expect($recipe->name)->toBe('Updated');
    expect($recipe->items()->count())->toBe(1);
    expect($recipe->items()->first()->inventory_item_id)->toBe($inv2->id);
});
