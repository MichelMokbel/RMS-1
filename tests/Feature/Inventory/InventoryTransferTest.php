<?php

use App\Models\InventoryItem;
use App\Models\InventoryStock;
use App\Models\InventoryTransaction;
use App\Models\SubledgerEntry;
use App\Models\User;
use App\Services\Inventory\InventoryAvailabilityService;
use App\Services\Inventory\InventoryTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('transfers stock between branches and records ledger', function () {
    DB::table('branches')->insert([
        'id' => 2,
        'name' => 'Branch 2',
        'code' => 'B2',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $user = User::factory()->create(['status' => 'active']);

    $item = InventoryItem::factory()->create([
        'current_stock' => 10,
        'cost_per_unit' => 5.00,
    ]);

    $availability = app(InventoryAvailabilityService::class);
    $availability->addToBranch($item, 2);

    $service = app(InventoryTransferService::class);
    $transfer = $service->createAndPost($item, 1, 2, 3, $user->id, 'Transfer test');

    $fromStock = InventoryStock::where('inventory_item_id', $item->id)->where('branch_id', 1)->value('current_stock');
    $toStock = InventoryStock::where('inventory_item_id', $item->id)->where('branch_id', 2)->value('current_stock');

    expect((float) $fromStock)->toBe(7.0);
    expect((float) $toStock)->toBe(3.0);

    expect((float) $item->fresh()->current_stock)->toBe(10.0);

    $txCount = InventoryTransaction::where('reference_type', 'transfer')
        ->where('reference_id', $transfer->id)
        ->count();
    expect($txCount)->toBe(2);

    $entry = SubledgerEntry::where('source_type', 'inventory_transfer')
        ->where('source_id', $transfer->id)
        ->first();
    expect($entry)->not->toBeNull();
    expect($entry->lines()->count())->toBe(2);
});

it('supports bulk transfers with multiple items', function () {
    DB::table('branches')->insert([
        'id' => 2,
        'name' => 'Branch 2',
        'code' => 'B2',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $user = User::factory()->create(['status' => 'active']);

    $itemA = InventoryItem::factory()->create([
        'current_stock' => 10,
        'cost_per_unit' => 4.50,
    ]);
    $itemB = InventoryItem::factory()->create([
        'current_stock' => 5,
        'cost_per_unit' => 2.00,
    ]);

    $service = app(InventoryTransferService::class);
    $transfer = $service->createAndPostBulk(1, 2, [
        ['item_id' => $itemA->id, 'quantity' => 2],
        ['item_id' => $itemB->id, 'quantity' => 1.5],
    ], $user->id, 'Bulk transfer test');

    $fromA = InventoryStock::where('inventory_item_id', $itemA->id)->where('branch_id', 1)->value('current_stock');
    $toA = InventoryStock::where('inventory_item_id', $itemA->id)->where('branch_id', 2)->value('current_stock');
    $fromB = InventoryStock::where('inventory_item_id', $itemB->id)->where('branch_id', 1)->value('current_stock');
    $toB = InventoryStock::where('inventory_item_id', $itemB->id)->where('branch_id', 2)->value('current_stock');

    expect((float) $fromA)->toBe(8.0);
    expect((float) $toA)->toBe(2.0);
    expect((float) $fromB)->toBe(3.5);
    expect((float) $toB)->toBe(1.5);

    $txCount = InventoryTransaction::where('reference_type', 'transfer')
        ->where('reference_id', $transfer->id)
        ->count();
    expect($txCount)->toBe(4);

    expect($transfer->lines()->count())->toBe(2);
    $entry = SubledgerEntry::where('source_type', 'inventory_transfer')
        ->where('source_id', $transfer->id)
        ->first();
    expect($entry)->not->toBeNull();
});
