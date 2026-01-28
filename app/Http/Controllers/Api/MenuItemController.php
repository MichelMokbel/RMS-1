<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MenuItemStoreRequest;
use App\Http\Requests\MenuItemUpdateRequest;
use App\Models\Category;
use App\Models\MenuItem;
use App\Services\Menu\MenuItemUsageService;
use Illuminate\Http\Request;

class MenuItemController extends Controller
{
    public function index(Request $request)
    {
        $light = $request->boolean('light', true);
        $search = $request->input('search');
        $categoryId = $request->input('category_id');
        $active = $request->has('active') ? $request->boolean('active') : true;
        $branchId = $request->input('branch_id');
        $perPage = (int) $request->input('per_page', 20);

        $query = MenuItem::query()
            ->when($active !== null, fn ($q) => $active ? $q->where('is_active', 1) : $q)
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->search($search)
            ->availableInBranch($branchId)
            ->ordered();

        if ($light) {
            return $query->limit($perPage)->get()->map(function (MenuItem $item) {
                return [
                    'id' => $item->id,
                    'text' => '['.$item->code.'] '.$item->name,
                    'price' => (float) $item->selling_price_per_unit,
                    'tax_rate' => (float) $item->tax_rate,
                ];
            });
        }

        return $query->paginate($perPage);
    }

    public function show(MenuItem $menuItem)
    {
        return $menuItem;
    }

    public function store(MenuItemStoreRequest $request)
    {
        $data = $request->validated();
        $menuItem = MenuItem::create($data);

        return response()->json($menuItem, 201);
    }

    public function update(MenuItemUpdateRequest $request, MenuItem $menuItem)
    {
        $data = $request->validated();
        $menuItem->update($data);

        return response()->json($menuItem);
    }

    public function destroy(MenuItem $menuItem, MenuItemUsageService $usageService)
    {
        if ($usageService->isMenuItemUsed($menuItem->id)) {
            return response()->json(['message' => __('Menu item is used in orders and cannot be deactivated.')], 422);
        }

        $menuItem->update(['is_active' => false]);

        return response()->json(['message' => __('Menu item deactivated.')]);
    }
}
