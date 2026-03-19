<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Reports\SupplierPurchasesReportQueryService;
use App\Support\Reports\CsvExport;
use App\Support\Reports\PdfExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupplierPurchasesReportController extends Controller
{
    private function filters(Request $request): array
    {
        return [
            'search' => $request->get('search', ''),
            'supplier_id' => $request->integer('supplier_id') ?: null,
            'item_id' => $request->integer('item_id') ?: null,
            'status' => $request->get('status', 'all'),
            'date_from' => $request->get('date_from'),
            'date_to' => $request->get('date_to'),
        ];
    }

    private function query(Request $request, SupplierPurchasesReportQueryService $service, int $limit = 3000)
    {
        return $service->query($this->filters($request))->limit($limit)->get();
    }

    public function print(Request $request, SupplierPurchasesReportQueryService $service)
    {
        $rows = $this->query($request, $service);

        return view('reports.supplier-purchases-print', [
            'rows' => $rows,
            'filters' => $this->filters($request),
            'generatedAt' => now(),
        ]);
    }

    public function csv(Request $request, SupplierPurchasesReportQueryService $service): StreamedResponse
    {
        $rows = $this->query($request, $service, 5000);
        $headers = [__('Supplier'), __('Item Code'), __('Item'), __('Ordered Qty'), __('Received Qty'), __('Avg Unit Price'), __('Total Amount'), __('PO Count'), __('First Order'), __('Last Order')];
        $data = $rows->map(fn ($row) => [
            $row->supplier_name,
            $row->item_code,
            $row->item_name,
            number_format((float) $row->ordered_quantity, 3, '.', ''),
            number_format((float) $row->received_quantity, 3, '.', ''),
            number_format((float) $row->avg_unit_price, 2, '.', ''),
            number_format((float) $row->total_amount, 2, '.', ''),
            (string) $row->po_count,
            $row->first_order_date,
            $row->last_order_date,
        ]);

        return CsvExport::stream($headers, $data, 'supplier-purchases-report.csv');
    }

    public function pdf(Request $request, SupplierPurchasesReportQueryService $service)
    {
        $rows = $this->query($request, $service);

        return PdfExport::download('reports.supplier-purchases-print', [
            'rows' => $rows,
            'filters' => $this->filters($request),
            'generatedAt' => now(),
        ], 'supplier-purchases-report.pdf');
    }
}
