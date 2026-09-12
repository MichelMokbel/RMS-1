<?php

namespace App\Http\Controllers\OrderSheet;

use App\Http\Controllers\Controller;
use App\Services\OrderSheet\OrderSheetEditorService;
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
        $payload = app(OrderSheetEditorService::class)->snapshot($date->toDateString());
        $menuItems = $payload['menuItems'];
        $entries = collect($payload['rows'])
            ->filter(fn ($row) => filled($row['customer_name']))
            ->map(fn ($row) => [
                'customer_name' => $row['customer_name'],
                'location' => $row['location'],
                'remarks' => $row['remarks'] ?? '',
                'qty' => $row['quantities'],
                'extras' => collect($row['extras'] ?? [])->map(fn ($extra) => [
                    'name' => $extra['name'],
                    'quantity' => (int) $extra['quantity'],
                ])->all(),
                'order_id' => $row['order_id'],
            ])->values();

        // Dish totals
        $dishTotals = [];
        foreach ($menuItems as $item) {
            $dishTotals[$item['id']] = [
                'name' => $item['name'],
                'role' => $item['role'],
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
