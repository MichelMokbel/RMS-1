<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DailyDishMenu;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class PublicDailyDishController extends Controller
{
    public function menus(Request $request)
    {
        $branchRule = ['nullable', 'integer'];
        if (Schema::hasTable('branches')) {
            $branchRule[] = Rule::exists('branches', 'id');
        }

        $data = $request->validate([
            'branch_id' => $branchRule,
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $branchId = (int) ($data['branch_id'] ?? 1);
        $from = isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : now()->startOfDay();
        $to = isset($data['to']) ? Carbon::parse($data['to'])->endOfDay() : now()->addDays(45)->endOfDay();

        $menus = DailyDishMenu::query()
            ->where('status', 'published')
            ->where('branch_id', $branchId)
            ->whereDate('service_date', '>=', $from->toDateString())
            ->whereDate('service_date', '<=', $to->toDateString())
            ->with(['items.menuItem'])
            ->orderBy('service_date')
            ->get();

        $out = $menus->map(function (DailyDishMenu $menu) {
            $items = $menu->items->filter(fn ($i) => $i->menuItem !== null);

            $mains = $items
                ->where('role', 'main')
                ->values()
                ->map(fn ($row) => [
                    'id' => (int) $row->menu_item_id,
                    'name' => (string) ($row->menuItem->name ?? ''),
                    'arabic_name' => $row->menuItem->arabic_name,
                ])
                ->all();

            $saladRow = $items->firstWhere('role', 'salad');
            $dessertRow = $items->firstWhere('role', 'dessert');

            return [
                // Website-compatible fields
                'key' => $menu->service_date?->format('Y-m-d'),
                'enDay' => $menu->service_date?->format('l M j'),
                'arDay' => null,
                'mains' => array_values(array_filter(array_map(fn ($m) => $m['name'] !== '' ? $m : null, $mains))),
                'salad' => $saladRow?->menuItem?->name ?? null,
                'dessert' => $dessertRow?->menuItem?->name ?? null,

                // ID-rich fields for reliable submissions (preferred)
                'salad_item' => $saladRow ? [
                    'id' => (int) $saladRow->menu_item_id,
                    'name' => (string) ($saladRow->menuItem->name ?? ''),
                    'arabic_name' => $saladRow->menuItem->arabic_name,
                ] : null,
                'dessert_item' => $dessertRow ? [
                    'id' => (int) $dessertRow->menu_item_id,
                    'name' => (string) ($dessertRow->menuItem->name ?? ''),
                    'arabic_name' => $dessertRow->menuItem->arabic_name,
                ] : null,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => $out,
        ]);
    }
}

