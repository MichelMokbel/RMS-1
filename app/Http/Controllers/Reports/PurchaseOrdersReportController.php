<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Support\Reports\CsvExport;
use App\Support\Reports\PdfExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PurchaseOrdersReportController extends Controller
{
    private function query(Request $request, int $limit = 500)
    {
        return PurchaseOrder::query()
            ->with(['supplier'])
            ->when($request->filled('search'), fn ($q) => $q->where('po_number', 'like', '%'.$request->search.'%'))
            ->when($request->filled('status') && $request->status !== 'all', fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->integer('supplier_id')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('order_date', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('order_date', '<=', $request->date_to))
            ->orderByDesc('order_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function print(Request $request)
    {
        $orders = $this->query($request);
        $filters = $request->only(['search', 'status', 'supplier_id', 'date_from', 'date_to']);

        return view('reports.purchase-orders-print', ['orders' => $orders, 'filters' => $filters, 'generatedAt' => now()]);
    }

    public function csv(Request $request): StreamedResponse
    {
        $orders = $this->query($request, 2000);
        $headers = [__('PO #'), __('Supplier'), __('Date'), __('Status'), __('Total')];
        $rows = $orders->map(fn ($o) => [$o->po_number, $o->supplier?->name ?? '', $o->order_date?->format('Y-m-d'), $o->status, number_format((float) $o->total_amount, 2, '.', '')]);

        return CsvExport::stream($headers, $rows, 'purchase-orders-report.csv');
    }

    public function pdf(Request $request)
    {
        $orders = $this->query($request);
        $filters = $request->only(['search', 'status', 'supplier_id', 'date_from', 'date_to']);

        return PdfExport::download('reports.purchase-orders-print', ['orders' => $orders, 'filters' => $filters, 'generatedAt' => now()], 'purchase-orders-report.pdf');
    }
}
