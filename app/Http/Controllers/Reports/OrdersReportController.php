<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\Reports\CsvExport;
use App\Support\Reports\PdfExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrdersReportController extends Controller
{
    private function query(Request $request, int $limit = 500)
    {
        return Order::query()
            ->when($request->filled('status') && $request->status !== 'all', fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('source'), fn ($q) => $q->where('source', $request->source))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('daily_dish_filter') && $request->daily_dish_filter === 'only', fn ($q) => $q->where('is_daily_dish', 1))
            ->when($request->filled('daily_dish_filter') && $request->daily_dish_filter === 'exclude', fn ($q) => $q->where(fn ($qq) => $qq->whereNull('is_daily_dish')->orWhere('is_daily_dish', 0)))
            ->when($request->filled('scheduled_date'), fn ($q) => $q->whereDate('scheduled_date', $request->scheduled_date))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('scheduled_date', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('scheduled_date', '<=', $request->date_to))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->search.'%';
                $q->where(fn ($qq) => $qq->where('order_number', 'like', $term)->orWhere('customer_name_snapshot', 'like', $term)->orWhere('customer_phone_snapshot', 'like', $term));
            })
            ->when(Schema::hasTable('meal_plan_request_orders'), function ($q) {
                $q->whereNotExists(function ($sub) {
                    $sub->selectRaw('1')->from('meal_plan_request_orders as mpro')
                        ->join('meal_plan_requests as mpr', 'mpr.id', '=', 'mpro.meal_plan_request_id')
                        ->whereColumn('mpro.order_id', 'orders.id')
                        ->whereNotIn('mpr.status', ['converted', 'closed']);
                });
            })
            ->orderByDesc('scheduled_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function print(Request $request)
    {
        $orders = $this->query($request);
        $filters = $request->only(['status', 'source', 'branch_id', 'daily_dish_filter', 'scheduled_date', 'date_from', 'date_to', 'search']);

        return view('reports.orders-print', [
            'orders' => $orders,
            'filters' => $filters,
            'generatedAt' => now(),
        ]);
    }

    public function csv(Request $request): StreamedResponse
    {
        $orders = $this->query($request, 2000);
        $headers = [__('Order #'), __('Source'), __('Branch'), __('Status'), __('Customer'), __('Scheduled'), __('Total')];
        $rows = $orders->map(fn ($o) => [
            $o->order_number,
            $o->source ?? '',
            $o->branch_id ?? '',
            $o->status ?? '',
            $o->customer_name_snapshot ?? '',
            $o->scheduled_date?->format('Y-m-d').' '.$o->scheduled_time,
            number_format((float) $o->total_amount, 3, '.', ''),
        ]);

        return CsvExport::stream($headers, $rows, 'orders-report.csv');
    }

    public function pdf(Request $request)
    {
        $orders = $this->query($request);
        $filters = $request->only(['status', 'source', 'branch_id', 'daily_dish_filter', 'scheduled_date', 'date_from', 'date_to', 'search']);

        return PdfExport::download('reports.orders-print', [
            'orders' => $orders,
            'filters' => $filters,
            'generatedAt' => now(),
        ], 'orders-report.pdf');
    }
}
