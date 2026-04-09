<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Reports\PurchaseOrderInventoryListReportQueryService;
use App\Support\Reports\CsvExport;
use App\Support\Reports\PdfExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PurchaseOrderInventoryListReportController extends Controller
{
    private function filters(Request $request): array
    {
        return [
            'search' => $request->get('search', ''),
            'supplier_id' => $request->integer('supplier_id') ?: null,
            'status' => $request->get('status', 'all'),
            'date_from' => $request->get('date_from'),
            'date_to' => $request->get('date_to'),
        ];
    }

    private function query(Request $request, PurchaseOrderInventoryListReportQueryService $service, int $limit = 3000)
    {
        return $service->query($this->filters($request))->limit($limit)->get();
    }

    public function print(Request $request, PurchaseOrderInventoryListReportQueryService $service)
    {
        return view('reports.purchase-order-inventory-list-print', [
            'rows' => $this->query($request, $service),
            'filters' => $this->filters($request),
            'generatedAt' => now(),
        ]);
    }

    public function csv(Request $request, PurchaseOrderInventoryListReportQueryService $service): StreamedResponse
    {
        $rows = $this->query($request, $service, 5000);
        $headers = [__('Item Code'), __('Item'), __('Unit'), __('Ordered Quantity')];
        $data = $rows->map(fn ($row) => [
            $row->item_code,
            $row->item_name,
            $row->unit_of_measure,
            number_format((float) $row->ordered_quantity, 3, '.', ''),
        ]);

        return CsvExport::stream($headers, $data, 'purchase-order-inventory-list.csv');
    }

    public function pdf(Request $request, PurchaseOrderInventoryListReportQueryService $service)
    {
        return PdfExport::download('reports.purchase-order-inventory-list-print', [
            'rows' => $this->query($request, $service),
            'filters' => $this->filters($request),
            'generatedAt' => now(),
        ], 'purchase-order-inventory-list.pdf');
    }
}
