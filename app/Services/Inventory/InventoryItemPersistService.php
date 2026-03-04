<?php

namespace App\Services\Inventory;

use App\Models\InventoryItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class InventoryItemPersistService
{
    private InventoryStockService $stockService;

    public function __construct(InventoryStockService $stockService)
    {
        $this->stockService = $stockService;
    }

    public function createFromForm(array $data, ?UploadedFile $image, ?int $actorId): InventoryItem
    {
        $actorId = ($actorId && $actorId > 0) ? $actorId : null;

        $branchId = (int) ($data['branch_id'] ?? config('inventory.default_branch_id', 1));
        if ($branchId <= 0) {
            $branchId = 1;
        }

        $initialStock = (float) ($data['current_stock'] ?? 0);

        unset($data['branch_id'], $data['current_stock'], $data['image'], $data['item_code']);

        return DB::transaction(function () use ($data, $image, $actorId, $branchId, $initialStock): InventoryItem {
            $data['item_code'] = $this->nextItemCode(lockForUpdate: true);

            if ($image) {
                $data['image_path'] = $this->storeImage($image, (string) $data['item_code']);
            }

            if (array_key_exists('cost_per_unit', $data) && $data['cost_per_unit'] !== null) {
                $data['last_cost_update'] = now();
            }

            $item = InventoryItem::create($data);

            if ($initialStock > 0.0005) {
                $this->stockService->adjustStock($item->fresh(), $initialStock, __('Initial stock'), $actorId, $branchId);
            }

            return $item->fresh();
        });
    }

    public function updateFromForm(InventoryItem $item, array $data, ?UploadedFile $image): InventoryItem
    {
        unset($data['branch_id'], $data['current_stock'], $data['image']);

        if ($image) {
            if ($item->image_path) {
                Storage::disk('public')->delete($item->image_path);
            }
            $data['image_path'] = $this->storeImage($image, (string) ($data['item_code'] ?? $item->item_code));
        }

        $costChanged = array_key_exists('cost_per_unit', $data)
            && $data['cost_per_unit'] !== null
            && (float) $data['cost_per_unit'] !== (float) ($item->cost_per_unit ?? 0);

        $unitsChanged = array_key_exists('units_per_package', $data)
            && (float) $data['units_per_package'] !== (float) ($item->units_per_package ?? 0);

        if ($costChanged || $unitsChanged) {
            $data['last_cost_update'] = now();
        }

        $item->update($data);

        return $item->fresh();
    }

    private function storeImage(UploadedFile $file, string $itemCode): string
    {
        return $file->storeAs(
            'inventory/items/'.$itemCode,
            $file->hashName(),
            'public'
        );
    }

    public function nextItemCode(bool $lockForUpdate = false): string
    {
        $query = InventoryItem::query()
            ->select('item_code')
            ->whereNotNull('item_code');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $codes = $query->pluck('item_code');

        $bestPrefix = '';
        $bestNumber = null;
        $bestDigits = 0;

        foreach ($codes as $code) {
            $code = (string) $code;

            if (! preg_match('/^(\D*)(\d+)$/', $code, $matches)) {
                continue;
            }

            $prefix = (string) $matches[1];
            $numberPart = (string) $matches[2];
            $number = (int) $numberPart;
            $digits = strlen($numberPart);

            if ($bestNumber === null || $number > $bestNumber || ($number === $bestNumber && $digits > $bestDigits)) {
                $bestPrefix = $prefix;
                $bestNumber = $number;
                $bestDigits = $digits;
            }
        }

        if ($bestNumber === null) {
            return '1';
        }

        $next = $bestNumber + 1;
        $nextPadded = str_pad((string) $next, $bestDigits, '0', STR_PAD_LEFT);

        return $bestPrefix.$nextPadded;
    }
}
