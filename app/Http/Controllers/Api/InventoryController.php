<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\InventoryAdjustmentRequest;
use App\Http\Requests\InventoryItemStoreRequest;
use App\Http\Requests\InventoryItemUpdateRequest;
use App\Models\InventoryItem;
use App\Services\Inventory\InventoryStockService;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function __construct(private InventoryStockService $stockService)
    {
    }

    public function index(Request $request)
    {
        $perPage = (int) $request->input('per_page', 20);
        $status = $request->input('status', 'active');
        $search = $request->input('search');
        $categoryId = $request->input('category_id');
        $supplierId = $request->input('supplier_id');
        $lowStock = $request->boolean('low_stock', false);

        $query = InventoryItem::query()
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))
            ->when($search, function ($q) use ($search) {
                $q->where(function ($inner) use ($search) {
                    $inner->where('item_code', 'like', '%'.$search.'%')
                        ->orWhere('name', 'like', '%'.$search.'%')
                        ->orWhere('location', 'like', '%'.$search.'%');
                });
            })
            ->when($lowStock, fn ($q) => $q->whereColumn('current_stock', '<=', 'minimum_stock'))
            ->orderBy('name');

        return $query->paginate($perPage)->through(function (InventoryItem $item) {
            return $item->toArray() + ['per_unit_cost' => $item->perUnitCost()];
        });
    }

    public function show(InventoryItem $item)
    {
        $transactions = $item->transactions()->limit(50)->get();
        return [
            'item' => $item->toArray() + ['per_unit_cost' => $item->perUnitCost()],
            'transactions' => $transactions,
        ];
    }

    public function store(InventoryItemStoreRequest $request)
    {
        $data = $request->validated();
        $initialStock = (int) ($data['initial_stock'] ?? 0);
        unset($data['initial_stock']);

        if ($request->file('image')) {
            $data['image_path'] = $this->storeImage($request->file('image'), $data['item_code']);
        }

        if (! empty($data['cost_per_unit'])) {
            $data['last_cost_update'] = now();
        }

        // save with current_stock 0 then adjust
        $data['current_stock'] = 0;
        $item = InventoryItem::create($data);

        if ($initialStock > 0) {
            $this->stockService->adjustStock($item->fresh(), $initialStock, __('Initial stock'), auth()->id());
        }

        return response()->json($item->fresh(), 201);
    }

    public function update(InventoryItemUpdateRequest $request, InventoryItem $item)
    {
        $data = $request->validated();
        $costChanged = array_key_exists('cost_per_unit', $data) && $data['cost_per_unit'] !== null && (float) $data['cost_per_unit'] !== (float) $item->cost_per_unit;
        $unitsChanged = array_key_exists('units_per_package', $data) && (int) $data['units_per_package'] !== (int) $item->units_per_package;

        if ($request->file('image')) {
            if ($item->image_path) {
                \Storage::disk('public')->delete($item->image_path);
            }
            $data['image_path'] = $this->storeImage($request->file('image'), $item->item_code);
        }

        if ($costChanged || $unitsChanged) {
            $data['last_cost_update'] = now();
        }

        // do not allow editing current_stock directly
        unset($data['current_stock']);

        $item->update($data);

        return response()->json($item->fresh());
    }

    public function adjust(InventoryAdjustmentRequest $request, InventoryItem $item)
    {
        $delta = $request->input('direction') === 'increase'
            ? (int) $request->input('quantity')
            : -(int) $request->input('quantity');

        $tx = $this->stockService->adjustStock($item, $delta, $request->input('notes'), auth()->id());

        return response()->json([
            'message' => __('Stock adjusted.'),
            'transaction' => $tx,
            'item' => $item->fresh(),
        ]);
    }

    private function storeImage($file, string $itemCode): string
    {
        return $file->storeAs(
            'inventory/items/'.$itemCode,
            $file->hashName(),
            'public'
        );
    }
}
