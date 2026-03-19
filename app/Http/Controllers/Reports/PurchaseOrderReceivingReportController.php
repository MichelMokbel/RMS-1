<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Reports\PurchaseOrderReceivingReportQueryService;
use App\Support\Reports\CsvExport;
use App\Support\Reports\PdfExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PurchaseOrderReceivingReportController extends Controller
{
    private function filters(Request $request): array
    {
        return [
            'search' => $request->get('search', ''),
            'supplier_id' => $request->integer('supplier_id') ?: null,
            'purchase_order_id' => $request->integer('purchase_order_id') ?: null,
            'item_id' => $request->integer('item_id') ?: null,
            'receiver_id' => $request->integer('receiver_id') ?: null,
            'date_from' => $request->get('date_from'),
            'date_to' => $request->get('date_to'),
        ];
    }

    private function query(Request $request, PurchaseOrderReceivingReportQueryService $service, int $limit = 3000)
    {
        return $service->query($this->filters($request))->limit($limit)->get();
    }

    public function print(Request $request, PurchaseOrderReceivingReportQueryService $service)
    {
        $rows = $this->query($request, $service);

        return view('reports.purchase-order-receiving-print', [
            'rows' => $rows,
            'filters' => $this->filters($request),
            'generatedAt' => now(),
        ]);
    }

    public function csv(Request $request, PurchaseOrderReceivingReportQueryService $service): StreamedResponse
    {
        $rows = $this->query($request, $service, 5000);
        $headers = [__('Received At'), __('PO #'), __('Supplier'), __('Item'), __('Qty'), __('Unit Cost'), __('Total Cost'), __('Receiver'), __('Notes')];
        $data = $rows->map(function ($row) {
            $receiving = $row->receiving;
            $po = $receiving?->purchaseOrder;

            return [
                $receiving?->received_at?->format('Y-m-d H:i'),
                $po?->po_number ?? '',
                $po?->supplier?->name ?? '',
                $row->item?->name ?? '',
                number_format((float) $row->received_quantity, 3, '.', ''),
                number_format((float) ($row->unit_cost ?? 0), 4, '.', ''),
                number_format((float) ($row->total_cost ?? 0), 4, '.', ''),
                $receiving?->creator?->username ?? $receiving?->creator?->email ?? '',
                $receiving?->notes ?? '',
            ];
        });

        return CsvExport::stream($headers, $data, 'purchase-order-receiving-report.csv');
    }

    public function pdf(Request $request, PurchaseOrderReceivingReportQueryService $service)
    {
        $rows = $this->query($request, $service);

        return PdfExport::download('reports.purchase-order-receiving-print', [
            'rows' => $rows,
            'filters' => $this->filters($request),
            'generatedAt' => now(),
        ], 'purchase-order-receiving-report.pdf');
    }
}
