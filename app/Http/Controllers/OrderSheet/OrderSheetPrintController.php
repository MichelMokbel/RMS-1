<?php

namespace App\Http\Controllers\OrderSheet;

use App\Http\Controllers\Controller;
use App\Models\DailyDishMenu;
use App\Models\OrderSheet;
use Carbon\Carbon;
use Illuminate\Http\Request;

class OrderSheetPrintController extends Controller
{
    private function resolveDate(Request $request): Carbon
    {
        return $request->filled('date')
            ? Carbon::parse((string) $request->input('date'))->startOfDay()
            : now()->startOfDay();
    }

    private function buildData(Carbon $date): array
    {
        $sheet = OrderSheet::with([
            'entries.quantities.dailyDishMenuItem.menuItem',
            'entries.extras',
            'entries.customer',
        ])->whereDate('sheet_date', $date->toDateString())->first();

        $menu = DailyDishMenu::with(['items.menuItem'])
            ->whereDate('service_date', $date->toDateString())
            ->first();

        $rolePriority = ['main' => 0, 'diet' => 1, 'vegetarian' => 2, 'salad' => 3, 'dessert' => 4];
        $menuItems = $menu
            ? $menu->items
                ->sortBy(fn ($item) => $rolePriority[$item->role] ?? 5)
                ->map(fn ($item) => [
                    'id'           => $item->id,
                    'menu_item_id' => $item->menu_item_id,
                    'name'         => $item->menuItem?->name ?? '—',
                    'role'         => $item->role ?? '',
                ])->values()->all()
            : [];

        $entries = collect();

        if ($sheet && $sheet->entries->isNotEmpty()) {
            $entries = $sheet->entries
                ->filter(fn ($e) => filled($e->customer_name))
                ->map(function ($entry) use ($menuItems) {
                    $qty = collect($menuItems)->mapWithKeys(fn ($item) =>
                        [$item['id'] => (int) optional($entry->quantities->firstWhere('daily_dish_menu_item_id', $item['id']))->quantity ?? 0]
                    )->all();

                    $extras = $entry->extras
                        ->filter(fn ($e) => $e->quantity > 0)
                        ->map(fn ($e) => [
                            'name'     => $e->menu_item_name,
                            'quantity' => $e->quantity,
                        ])->values()->all();

                    return [
                        'customer_name' => $entry->customer_name,
                        'location'      => $entry->location ?? '',
                        'remarks'       => $entry->remarks ?? '',
                        'qty'           => $qty,
                        'extras'        => $extras,
                        'order_id'      => $entry->order_id,
                    ];
                })
                ->values();
        }

        // Dish totals
        $dishTotals = [];
        foreach ($menuItems as $item) {
            $dishTotals[$item['id']] = [
                'name'     => $item['name'],
                'role'     => $item['role'],
                'quantity' => $entries->sum(fn ($e) => (int) ($e['qty'][$item['id']] ?? 0)),
            ];
        }

        // Extra totals
        $extraTotals = [];
        foreach ($entries as $entry) {
            foreach ($entry['extras'] as $extra) {
                $name = $extra['name'];
                $extraTotals[$name] = $extraTotals[$name] ?? ['name' => $name, 'quantity' => 0];
                $extraTotals[$name]['quantity'] += $extra['quantity'];
            }
        }

        return compact('menuItems', 'entries', 'dishTotals', 'extraTotals', 'date');
    }

    public function byOrder(Request $request)
    {
        $date = $this->resolveDate($request);
        $data = $this->buildData($date);
        $data['generatedAt'] = now();
        $data['generatedBy'] = $request->user()?->username ?: $request->user()?->name ?: '-';

        return view('reports.order-sheet-by-order-print', $data);
    }

    public function byItemTotals(Request $request)
    {
        $date = $this->resolveDate($request);
        $data = $this->buildData($date);
        $data['generatedAt'] = now();
        $data['generatedBy'] = $request->user()?->username ?: $request->user()?->name ?: '-';

        return view('reports.order-sheet-by-item-print', $data);
    }
}
