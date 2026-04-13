<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\PastryOrder;
use App\Support\Reports\CsvExport;
use App\Support\Reports\PdfExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PastryOrdersReportController extends Controller
{
    private function query(Request $request, int $limit = 500)
    {
        return PastryOrder::query()
            ->when(
                $request->filled('status') && $request->status !== 'all',
                fn ($q) => $q->where('status', $request->status)
            )
            ->when(
                $request->filled('branch_id'),
                fn ($q) => $q->where('branch_id', $request->integer('branch_id'))
            )
            ->when(
                $request->filled('scheduled_date'),
                fn ($q) => $q->whereDate('scheduled_date', $request->scheduled_date)
            )
            ->when(
                $request->filled('date_from'),
                fn ($q) => $q->whereDate('scheduled_date', '>=', $request->date_from)
            )
            ->when(
                $request->filled('date_to'),
                fn ($q) => $q->whereDate('scheduled_date', '<=', $request->date_to)
            )
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->search.'%';
                $q->where(fn ($qq) => $qq
                    ->where('order_number', 'like', $term)
                    ->orWhere('customer_name_snapshot', 'like', $term)
                    ->orWhere('customer_phone_snapshot', 'like', $term)
                );
            })
            ->orderByDesc('scheduled_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function print(Request $request)
    {
        $orders  = $this->query($request);
        $filters = $request->only(['status', 'branch_id', 'scheduled_date', 'date_from', 'date_to', 'search']);

        return view('reports.pastry-orders-print', [
            'orders'      => $orders,
            'filters'     => $filters,
            'generatedAt' => now(),
        ]);
    }

    public function csv(Request $request): StreamedResponse
    {
        $orders  = $this->query($request, 2000);
        $headers = [
            __('Order #'),
            __('Customer'),
            __('Phone'),
            __('Type'),
            __('Status'),
            __('Scheduled Date'),
            __('Items'),
            __('Total'),
        ];
        $rows = $orders->map(fn ($o) => [
            $o->order_number,
            $o->customer_name_snapshot ?? '',
            $o->customer_phone_snapshot ?? '',
            $o->type ?? '',
            $o->status ?? '',
            $o->scheduled_date?->format('Y-m-d') ?? '',
            $o->items()->count(),
            number_format((float) $o->total_amount, 3, '.', ''),
        ]);

        return CsvExport::stream($headers, $rows, 'pastry-orders-report.csv');
    }

    public function pdf(Request $request)
    {
        $orders  = $this->query($request);
        $filters = $request->only(['status', 'branch_id', 'scheduled_date', 'date_from', 'date_to', 'search']);

        return PdfExport::download('reports.pastry-orders-print', [
            'orders'      => $orders,
            'filters'     => $filters,
            'generatedAt' => now(),
        ], 'pastry-orders-report.pdf');
    }
}
