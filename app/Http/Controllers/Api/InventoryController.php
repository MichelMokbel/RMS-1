<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\InventoryAdjustmentRequest;
use App\Http\Requests\InventoryAvailabilityRequest;
use App\Http\Requests\InventoryItemStoreRequest;
use App\Http\Requests\InventoryItemUpdateRequest;
use App\Models\InventoryItem;
use App\Models\InventoryStock;
use App\Services\Inventory\InventoryAvailabilityService;
use App\Services\Inventory\InventoryStockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

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
        $branchId = (int) $request->input('branch_id');

        if ($branchId > 0 && Schema::hasTable('branches')) {
            $q = DB::table('branches')->where('id', $branchId);
            if (Schema::hasColumn('branches', 'is_active')) {
                $q->where('is_active', 1);
            }
            if (! $q->exists()) {
                return response()->json(['message' => __('Invalid branch.')], 422);
            }
        }

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
            ->orderBy('name');

        if ($branchId > 0) {
            $query->leftJoin('inventory_stocks as inv_stock', function ($join) use ($branchId) {
                $join->on('inventory_items.id', '=', 'inv_stock.inventory_item_id')
                    ->where('inv_stock.branch_id', '=', $branchId);
            })
                ->select('inventory_items.*', DB::raw('COALESCE(inv_stock.current_stock, 0) as current_stock'));
        } elseif (Schema::hasTable('inventory_stocks')) {
            $totals = DB::table('inventory_stocks')
                ->select('inventory_item_id', DB::raw('SUM(current_stock) as total_stock'))
                ->groupBy('inventory_item_id');

            $query->leftJoinSub($totals, 'inv_total', function ($join) {
                $join->on('inventory_items.id', '=', 'inv_total.inventory_item_id');
            })->select('inventory_items.*', DB::raw('COALESCE(inv_total.total_stock, 0) as current_stock'));
        }

        if ($lowStock) {
            if ($branchId > 0) {
                $query->whereRaw('COALESCE(inv_stock.current_stock, 0) <= inventory_items.minimum_stock');
            } elseif (Schema::hasTable('inventory_stocks')) {
                $query->whereRaw('COALESCE(inv_total.total_stock, 0) <= inventory_items.minimum_stock');
            }
        }

        return $query->paginate($perPage)->through(function (InventoryItem $item) use ($branchId) {
            $payload = $item->toArray() + ['per_unit_cost' => $item->perUnitCost()];
            if ($branchId > 0) {
                $payload['branch_id'] = $branchId;
            }
            return $payload;
        });
    }

    public function show(Request $request, InventoryItem $item)
    {
        $branchId = (int) $request->input('branch_id', config('inventory.default_branch_id', 1));
        if ($branchId <= 0) {
            $branchId = (int) config('inventory.default_branch_id', 1);
        }

        if ($branchId > 0 && Schema::hasTable('branches')) {
            $q = DB::table('branches')->where('id', $branchId);
            if (Schema::hasColumn('branches', 'is_active')) {
                $q->where('is_active', 1);
            }
            if (! $q->exists()) {
                return response()->json(['message' => __('Invalid branch.')], 422);
            }
        }

        $transactions = $item->transactions()
            ->when($branchId > 0, fn ($q) => $q->where('branch_id', $branchId))
            ->limit(50)
            ->get();

        $branchStock = InventoryStock::where('inventory_item_id', $item->id)
            ->where('branch_id', $branchId)
            ->value('current_stock');

        $itemData = $item->toArray();
        if ($branchStock === null) {
            $branchStock = 0;
        }
        $globalStock = Schema::hasTable('inventory_stocks')
            ? (float) InventoryStock::where('inventory_item_id', $item->id)->sum('current_stock')
            : 0.0;
        $itemData['current_stock'] = (float) $branchStock;
        $itemData['branch_id'] = $branchId;
        $itemData['global_stock'] = $globalStock;

        return [
            'item' => $itemData + ['per_unit_cost' => $item->perUnitCost()],
            'transactions' => $transactions,
        ];
    }

    public function store(InventoryItemStoreRequest $request)
    {
        $data = $request->validated();
        $initialStock = (float) ($data['initial_stock'] ?? 0);
        $branchId = (int) ($data['branch_id'] ?? $request->input('branch_id'));
        unset($data['initial_stock'], $data['branch_id']);

        if ($request->file('image')) {
            $data['image_path'] = $this->storeImage($request->file('image'), $data['item_code']);
        }

        if (! empty($data['cost_per_unit'])) {
            $data['last_cost_update'] = now();
        }

        $item = InventoryItem::create($data);

        if ($initialStock > 0) {
            $this->stockService->adjustStock($item->fresh(), $initialStock, __('Initial stock'), Auth::id(), $branchId > 0 ? $branchId : null);
        }

        return response()->json($item->fresh(), 201);
    }

    public function update(InventoryItemUpdateRequest $request, InventoryItem $item)
    {
        $data = $request->validated();
        $costChanged = array_key_exists('cost_per_unit', $data) && $data['cost_per_unit'] !== null && (float) $data['cost_per_unit'] !== (float) $item->cost_per_unit;
        $unitsChanged = array_key_exists('units_per_package', $data) && (float) $data['units_per_package'] !== (float) $item->units_per_package;

        if ($request->file('image')) {
            if ($item->image_path) {
                Storage::disk('public')->delete($item->image_path);
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
            ? (float) $request->input('quantity')
            : -(float) $request->input('quantity');

        $branchId = $request->integer('branch_id');
        $tx = $this->stockService->adjustStock($item, $delta, $request->input('notes'), Auth::id(), $branchId);

        return response()->json([
            'message' => __('Stock adjusted.'),
            'transaction' => $tx,
            'item' => $item->fresh(),
        ]);
    }

    public function addAvailability(InventoryAvailabilityRequest $request, InventoryItem $item, InventoryAvailabilityService $availabilityService)
    {
        $branchId = $request->integer('branch_id');
        $stock = $availabilityService->addToBranch($item, $branchId);

        return response()->json([
            'message' => __('Availability added.'),
            'stock' => $stock,
        ], 201);
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
