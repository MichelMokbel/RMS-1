<?php

use App\Models\InventoryItem;
use App\Models\InventoryStock;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipeProduction;
use App\Models\User;
use App\Services\Recipes\RecipeProductionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function baseRecipeForProduction(): Recipe
{
    $inv = InventoryItem::factory()->create([
        'units_per_package' => 5,
        'cost_per_unit' => 20,
    ]);
    InventoryStock::where('inventory_item_id', $inv->id)
        ->where('branch_id', (int) config('inventory.default_branch_id', 1))
        ->update(['current_stock' => 10]);

    $recipe = Recipe::factory()->create([
        'yield_quantity' => 5, // yields 5 units
        'yield_unit' => 'unit',
    ]);

    RecipeItem::create([
        'recipe_id' => $recipe->id,
        'inventory_item_id' => $inv->id,
        'quantity' => 5, // 5 units
        'unit' => 'unit',
        'quantity_type' => 'unit',
        'cost_type' => 'ingredient',
    ]);

    return $recipe->fresh();
}

it('produces recipe and deducts stock with integer constraint', function () {
    $recipe = baseRecipeForProduction();
    $service = app(RecipeProductionService::class);
    $user = User::factory()->create(['status' => 'active']);

    $production = $service->produce($recipe, [
        'produced_quantity' => 5, // factor 1
        'reference' => 'BATCH-1',
    ], userId: $user->id);

    expect($production)->toBeInstanceOf(RecipeProduction::class);
    $recipe->refresh();
    $inv = $recipe->items()->first()->inventoryItem->fresh();

    // quantity_type unit -> 5 units / units_per_package 5 => 1 package
    expect((float) $inv->current_stock)->toBe(9.0);
});

it('allows fractional deduction', function () {
    $recipe = baseRecipeForProduction();
    $service = app(RecipeProductionService::class);
    $user = User::factory()->create(['status' => 'active']);

    // produced_quantity 2.5 => factor 0.5 => required 2.5 units => 2.5/5 = 0.5 packages (fractional)
    $production = $service->produce($recipe, [
        'produced_quantity' => 2.5,
    ], userId: $user->id);

    expect($production)->toBeInstanceOf(RecipeProduction::class);
    $recipe->refresh();
    $inv = $recipe->items()->first()->inventoryItem->fresh();
    expect((float) $inv->current_stock)->toBe(9.5);
});

it('blocks insufficient stock when negative stock disallowed', function () {
    config()->set('inventory.allow_negative_stock', false);

    $recipe = baseRecipeForProduction();
    $service = app(RecipeProductionService::class);
    $user = User::factory()->create(['status' => 'active']);

    // This would require 2 packages (10 units), stock is 10 packages? no 10 units => 2 packages deduction -> stock becomes 8 packages? Wait
    // actually stock is 10 (packages), we set as units? current_stock is 10 (packages). Deduction 2 packages ok. Make more
    $inv = $recipe->items()->first()->inventoryItem;
    $branchId = (int) config('inventory.default_branch_id', 1);
    InventoryStock::where('inventory_item_id', $inv->id)->where('branch_id', $branchId)->update(['current_stock' => 1]);

    expect(fn () => $service->produce($recipe, [
        'produced_quantity' => 10, // factor 2 => required 10 units => 2 packages, but stock set to 1 package
    ], userId: $user->id))->toThrow(ValidationException::class);
});

it('deducts nested sub recipe ingredients recursively', function () {
    $lettuce = InventoryItem::factory()->create([
        'name' => 'Lettuce',
        'units_per_package' => 2,
        'cost_per_unit' => 10,
    ]);
    $tomato = InventoryItem::factory()->create([
        'name' => 'Tomato',
        'units_per_package' => 4,
        'cost_per_unit' => 12,
    ]);

    $branchId = (int) config('inventory.default_branch_id', 1);
    InventoryStock::where('inventory_item_id', $lettuce->id)->where('branch_id', $branchId)->update(['current_stock' => 5]);
    InventoryStock::where('inventory_item_id', $tomato->id)->where('branch_id', $branchId)->update(['current_stock' => 5]);

    $sauce = Recipe::factory()->create([
        'name' => 'Sauce',
        'yield_quantity' => 2,
        'yield_unit' => 'portion',
    ]);
    RecipeItem::create([
        'recipe_id' => $sauce->id,
        'inventory_item_id' => $tomato->id,
        'sub_recipe_id' => null,
        'quantity' => 4,
        'unit' => 'unit',
        'quantity_type' => 'unit',
        'cost_type' => 'ingredient',
    ]);

    $salad = Recipe::factory()->create([
        'name' => 'Salad',
        'yield_quantity' => 1,
        'yield_unit' => 'plate',
    ]);
    RecipeItem::create([
        'recipe_id' => $salad->id,
        'inventory_item_id' => $lettuce->id,
        'sub_recipe_id' => null,
        'quantity' => 2,
        'unit' => 'unit',
        'quantity_type' => 'unit',
        'cost_type' => 'ingredient',
    ]);
    RecipeItem::create([
        'recipe_id' => $salad->id,
        'inventory_item_id' => null,
        'sub_recipe_id' => $sauce->id,
        'quantity' => 1,
        'unit' => 'portion',
        'quantity_type' => 'unit',
        'cost_type' => 'ingredient',
    ]);

    $service = app(RecipeProductionService::class);
    $user = User::factory()->create(['status' => 'active']);

    $production = $service->produce($salad->fresh(), [
        'produced_quantity' => 2,
    ], userId: $user->id);

    expect($production)->toBeInstanceOf(RecipeProduction::class);
    expect((float) $lettuce->fresh()->current_stock)->toBe(3.0);
    expect((float) $tomato->fresh()->current_stock)->toBe(4.0);
});
