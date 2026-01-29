<?php

use App\Models\InventoryItem;
use App\Models\InventoryStock;
use App\Services\Inventory\InventoryAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('adds item availability to another branch', function () {
    DB::table('branches')->insert([
        'id' => 2,
        'name' => 'Branch 2',
        'code' => 'B2',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $item = InventoryItem::factory()->create();

    $service = app(InventoryAvailabilityService::class);
    $stock = $service->addToBranch($item, 2);

    expect($stock)->toBeInstanceOf(InventoryStock::class);
    expect((float) $stock->current_stock)->toBe(0.0);
    expect(InventoryStock::where('inventory_item_id', $item->id)->where('branch_id', 2)->count())->toBe(1);

    $service->addToBranch($item, 2);
    expect(InventoryStock::where('inventory_item_id', $item->id)->where('branch_id', 2)->count())->toBe(1);
});
