<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DailyDishMenu;
use App\Services\DailyDish\DailyDishMenuService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class DailyDishMenuController extends Controller
{
    public function index(Request $request)
    {
        $query = DailyDishMenu::query()->with('items')
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('service_date', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('service_date', '<=', $request->input('to')))
            ->orderBy('service_date');

        return response()->json($query->paginate($request->integer('per_page', 15)));
    }

    public function show(DailyDishMenu $menu)
    {
        return response()->json($menu->load(['items']));
    }

    public function upsert(int $branchId, string $serviceDate, Request $request, DailyDishMenuService $service)
    {
        if (Schema::hasTable('branches') && ! \DB::table('branches')->where('id', $branchId)->exists()) {
            throw ValidationException::withMessages(['branch_id' => __('Invalid branch.')]);
        }

        $payload = $request->validate([
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.menu_item_id' => ['required', 'integer'],
            'items.*.role' => ['required', 'in:main,diet,vegetarian,salad,dessert,addon'],
            'items.*.sort_order' => ['nullable', 'integer'],
            'items.*.is_required' => ['boolean'],
            'include_inactive' => ['sometimes', 'boolean'],
        ]);

        $menu = $service->upsertMenu(
            $branchId,
            $serviceDate,
            $payload,
            $request->user()->id,
            $payload['include_inactive'] ?? false
        );

        return response()->json($menu->load('items'));
    }

    public function publish(DailyDishMenu $menu, Request $request, DailyDishMenuService $service)
    {
        $menu = $service->publish($menu, $request->user()->id);

        return response()->json($menu);
    }

    public function unpublish(DailyDishMenu $menu, Request $request, DailyDishMenuService $service)
    {
        $menu = $service->unpublish($menu, $request->user()->id);

        return response()->json($menu);
    }

    public function clone(DailyDishMenu $menu, Request $request, DailyDishMenuService $service)
    {
        $branchRule = ['required', 'integer'];
        if (Schema::hasTable('branches')) {
            $branchRule[] = Rule::exists('branches', 'id');
        }

        $data = $request->validate([
            'to_date' => ['required', 'date'],
            'branch_id' => $branchRule,
        ]);

        $cloned = $service->cloneMenu($menu, $data['to_date'], $data['branch_id'], $request->user()->id);

        return response()->json($cloned->load('items'));
    }
}
