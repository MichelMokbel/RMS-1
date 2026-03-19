<?php

use App\Models\InventoryItem;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Services\Recipes\RecipeCostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('computes costing with package and unit quantities and overhead normalization', function () {
    $recipe = Recipe::factory()->create([
        'yield_quantity' => 2,
        'yield_unit' => 'kg',
        'overhead_pct' => 12, // should normalize to 0.12
        'selling_price_per_unit' => 20,
    ]);

    $invPackage = InventoryItem::factory()->create([
        'cost_per_unit' => 50, // package cost
        'units_per_package' => 10,
    ]);

    $invUnitNoPackage = InventoryItem::factory()->create([
        'cost_per_unit' => 30, // fallback per package
        'units_per_package' => 0,
    ]);

    RecipeItem::create([
        'recipe_id' => $recipe->id,
        'inventory_item_id' => $invPackage->id,
        'quantity' => 1, // 1 package => cost 50
        'unit' => 'pkg',
        'quantity_type' => 'package',
        'cost_type' => 'ingredient',
    ]);

    RecipeItem::create([
        'recipe_id' => $recipe->id,
        'inventory_item_id' => $invPackage->id,
        'quantity' => 5, // 5 units => 5 * (50/10) = 25
        'unit' => 'unit',
        'quantity_type' => 'unit',
        'cost_type' => 'ingredient',
    ]);

    RecipeItem::create([
        'recipe_id' => $recipe->id,
        'inventory_item_id' => $invUnitNoPackage->id,
        'quantity' => 2, // units_per_package = 0, fallback to package cost: 2 * 30 = 60
        'unit' => 'unit',
        'quantity_type' => 'unit',
        'cost_type' => 'ingredient',
    ]);

    $service = app(RecipeCostingService::class);
    $cost = $service->compute($recipe);

    // Base cost = 50 + 25 + 60 = 135
    expect($cost['base_cost_total'])->toBe(135.00);

    // Overhead 12% normalized => 16.2
    expect($cost['overhead_rate'])->toBe(0.12);
    expect($cost['overhead_amount'])->toBe(16.20);

    // Total with overhead = 151.2
    expect($cost['total_cost_with_overhead'])->toBe(151.20);

    // Cost per yield unit (yield 2) = 75.6
    expect($cost['cost_per_yield_unit'])->toBe(75.6);

    // Margin per unit = 20 - 75.6 = -55.6
    expect($cost['margin_amount_per_unit'])->toBe(-55.6);
    expect(round($cost['margin_pct'], 2))->toBe(-2.78);
});

it('computes costing recursively for nested sub recipes', function () {
    $oil = InventoryItem::factory()->create([
        'name' => 'Olive Oil',
        'cost_per_unit' => 20,
        'units_per_package' => 10,
    ]);

    $lettuce = InventoryItem::factory()->create([
        'name' => 'Lettuce',
        'cost_per_unit' => 8,
        'units_per_package' => 4,
    ]);

    $dressing = Recipe::factory()->create([
        'name' => 'Dressing',
        'yield_quantity' => 2,
        'yield_unit' => 'portion',
        'overhead_pct' => 0,
    ]);

    RecipeItem::create([
        'recipe_id' => $dressing->id,
        'inventory_item_id' => $oil->id,
        'sub_recipe_id' => null,
        'quantity' => 4,
        'unit' => 'ml',
        'quantity_type' => 'unit',
        'cost_type' => 'ingredient',
    ]);

    $salad = Recipe::factory()->create([
        'name' => 'Salad',
        'yield_quantity' => 1,
        'yield_unit' => 'plate',
        'overhead_pct' => 0,
    ]);

    RecipeItem::create([
        'recipe_id' => $salad->id,
        'inventory_item_id' => $lettuce->id,
        'sub_recipe_id' => null,
        'quantity' => 2,
        'unit' => 'leaf',
        'quantity_type' => 'unit',
        'cost_type' => 'ingredient',
    ]);

    RecipeItem::create([
        'recipe_id' => $salad->id,
        'inventory_item_id' => null,
        'sub_recipe_id' => $dressing->id,
        'quantity' => 1,
        'unit' => 'portion',
        'quantity_type' => 'unit',
        'cost_type' => 'ingredient',
    ]);

    $cost = app(RecipeCostingService::class)->compute($salad->fresh());

    expect($cost['base_cost_total'])->toBe(8.00);
    expect($cost['items'])->toHaveCount(2);
    expect(collect($cost['items'])->firstWhere('item_name', 'Olive Oil')['line_cost'])->toBe(4.0);
    expect(collect($cost['items'])->firstWhere('item_name', 'Olive Oil')['path'][0]['sub_recipe_name'])->toBe('Dressing');
});
