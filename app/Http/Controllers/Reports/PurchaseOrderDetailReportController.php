<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrderItem;
use App\Support\Reports\CsvExport;
use App\Support\Reports\PdfExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PurchaseOrderDetailReportController extends Controller
{
    private function query(Request $request, int $limit = 2000)
    {
        return PurchaseOrderItem::query()
            ->with(['purchaseOrder.supplier', 'item'])
            ->whereHas('purchaseOrder', function ($q) use ($request) {
                $q->when($request->filled('search'), fn ($q2) => $q2->where('po_number', 'like', '%'.$request->search.'%'))
                    ->when($request->filled('status') && $request->status !== 'all', fn ($q2) => $q2->where('status', $request->status))
                    ->when($request->filled('supplier_id'), fn ($q2) => $q2->where('supplier_id', $request->integer('supplier_id')))
                    ->when($request->filled('date_from'), fn ($q2) => $q2->whereDate('order_date', '>=', $request->date_from))
                    ->when($request->filled('date_to'), fn ($q2) => $q2->whereDate('order_date', '<=', $request->date_to));
            })
            ->join('purchase_orders as po', 'po.id', '=', 'purchase_order_items.purchase_order_id')
            ->select('purchase_order_items.*')
            ->orderByDesc('po.order_date')
            ->orderBy('po.po_number')
            ->orderBy('purchase_order_items.id')
            ->limit($limit)
            ->get();
    }

    private function formatMoney(?float $amount): string
    {
        return number_format((float) ($amount ?? 0), 2, '.', '');
    }

    public function print(Request $request)
    {
        $items = $this->query($request);
        $filters = $request->only(['search', 'status', 'supplier_id', 'date_from', 'date_to']);

        return view('reports.purchase-order-detail-print', [
            'items' => $items,
            'filters' => $filters,
            'generatedAt' => now(),
            'formatMoney' => fn ($a) => $this->formatMoney($a),
        ]);
    }

    public function csv(Request $request): StreamedResponse
    {
        $items = $this->query($request, 5000);
        $headers = [
            __('PO #'),
            __('Supplier'),
            __('Order Date'),
            __('Status'),
            __('Item'),
            __('Quantity'),
            __('Unit Price'),
            __('Total Price'),
            __('Received Qty'),
        ];
        $rows = $items->map(function ($item) {
            $po = $item->purchaseOrder;
            return [
                $po?->po_number ?? '—',
                $po?->supplier?->name ?? '—',
                $po?->order_date?->format('Y-m-d') ?? '—',
                $po?->status ?? '—',
                $item->item?->name ?? $item->item_id,
                (string) $item->quantity,
                $this->formatMoney($item->unit_price),
                $this->formatMoney($item->total_price),
                (string) ($item->received_quantity ?? 0),
            ];
        });

        return CsvExport::stream($headers, $rows, 'purchase-order-detail.csv');
    }

    public function pdf(Request $request)
    {
        $items = $this->query($request);
        $filters = $request->only(['search', 'status', 'supplier_id', 'date_from', 'date_to']);

        return PdfExport::download('reports.purchase-order-detail-print', [
            'items' => $items,
            'filters' => $filters,
            'generatedAt' => now(),
            'formatMoney' => fn ($a) => $this->formatMoney($a),
        ], 'purchase-order-detail.pdf');
    }
}
