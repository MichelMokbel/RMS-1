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
                    'portion_type' => $extra['portion_type'] ?? 'plate',
                    'quantity' => (int) $extra['quantity'],
                ])->all(),
                'order_id' => $row['order_id'],
            ])->values();

        // Dish totals
        $dishTotals = [];
        foreach ($menuItems as $item) {
            $portions = [
                'plate' => $entries->sum(fn ($e) => (int) ($e['qty'][$item['id']]['plate'] ?? 0)),
                'half' => $entries->sum(fn ($e) => (int) ($e['qty'][$item['id']]['half'] ?? 0)),
                'full' => $entries->sum(fn ($e) => (int) ($e['qty'][$item['id']]['full'] ?? 0)),
            ];
            $dishTotals[$item['id']] = [
                'name' => $item['name'],
                'role' => $item['role'],
                'portions' => $portions,
                'quantity' => array_sum($portions),
            ];
        }

        // Extra totals
        $extraTotals = [];
        foreach ($entries as $entry) {
            foreach ($entry['extras'] as $extra) {
                $name = $extra['name'];
                $portionType = in_array($extra['portion_type'], ['half', 'full'], true) ? $extra['portion_type'] : 'plate';
                $key = $portionType === 'plate' ? $name : $name.'|'.$portionType;
                $extraTotals[$key] = $extraTotals[$key] ?? [
                    'name' => $name,
                    'portion_type' => $portionType,
                    'quantity' => 0,
                ];
                $extraTotals[$key]['quantity'] += $extra['quantity'];
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
