<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Inventory\InventoryItemIndexQueryService;
use App\Support\Reports\CsvExport;
use App\Support\Reports\PdfExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventoryReportController extends Controller
{
    private function query(Request $request, InventoryItemIndexQueryService $queryService, int $limit = 500)
    {
        $filters = [
            'status' => $request->get('status', 'active'),
            'category_id' => $request->filled('category_id') ? $request->integer('category_id') : null,
            'supplier_id' => $request->filled('supplier_id') ? $request->integer('supplier_id') : null,
            'branch_id' => $request->filled('branch_id') ? $request->integer('branch_id') : null,
            'search' => $request->get('search', ''),
            'low_stock_only' => (bool) $request->get('low_stock_only'),
        ];

        return $queryService->query($filters)->with('category')->limit($limit)->get();
    }

    public function print(Request $request, InventoryItemIndexQueryService $queryService)
    {
        $items = $this->query($request, $queryService);
        $filters = $request->only(['search', 'status', 'category_id', 'supplier_id', 'branch_id', 'low_stock_only']);

        return view('reports.inventory-print', ['items' => $items, 'filters' => $filters, 'generatedAt' => now()]);
    }

    public function csv(Request $request, InventoryItemIndexQueryService $queryService): StreamedResponse
    {
        $items = $this->query($request, $queryService, 2000);
        $headers = [__('Code'), __('Name'), __('Category'), __('Current Stock'), __('Min Stock'), __('Cost')];
        $rows = $items->map(fn ($i) => [
            $i->item_code ?? '',
            $i->name ?? '',
            $i->category?->name ?? '',
            number_format((float) ($i->current_stock ?? 0), 3, '.', ''),
            number_format((float) ($i->minimum_stock ?? 0), 3, '.', ''),
            number_format((float) ($i->cost_per_unit ?? 0), 3, '.', ''),
        ]);

        return CsvExport::stream($headers, $rows, 'inventory-report.csv');
    }

    public function pdf(Request $request, InventoryItemIndexQueryService $queryService)
    {
        $items = $this->query($request, $queryService);
        $filters = $request->only(['search', 'status', 'category_id', 'supplier_id', 'branch_id', 'low_stock_only']);

        return PdfExport::download('reports.inventory-print', ['items' => $items, 'filters' => $filters, 'generatedAt' => now()], 'inventory-report.pdf');
    }
}
