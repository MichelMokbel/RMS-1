<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Support\Reports\CsvExport;
use App\Support\Reports\PdfExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalesReportController extends Controller
{
    private function formatCents(?int $cents): string
    {
        $cents = (int) ($cents ?? 0);
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);
        $whole = intdiv($cents, 1000);
        $frac = $cents % 1000;
        return $sign.$whole.'.'.str_pad((string) $frac, 3, '0', STR_PAD_LEFT);
    }

    private function query(Request $request, int $limit = 500)
    {
        return Sale::query()
            ->when($request->filled('branch_id') && $request->integer('branch_id') > 0, fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('status') && $request->status !== 'all', fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('pos_date', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('pos_date', '<=', $request->date_to))
            ->orderByDesc('pos_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function print(Request $request)
    {
        $sales = $this->query($request);
        $filters = $request->only(['branch_id', 'status', 'date_from', 'date_to']);

        return view('reports.sales-print', ['sales' => $sales, 'filters' => $filters, 'generatedAt' => now(), 'formatCents' => fn ($c) => $this->formatCents($c)]);
    }

    public function csv(Request $request): StreamedResponse
    {
        $sales = $this->query($request, 2000);
        $headers = [__('Sale #'), __('Date'), __('Status'), __('Total')];
        $rows = $sales->map(fn ($s) => [$s->sale_number, $s->pos_date?->format('Y-m-d'), $s->status, $this->formatCents($s->total_cents)]);

        return CsvExport::stream($headers, $rows, 'sales-report.csv');
    }

    public function pdf(Request $request)
    {
        $sales = $this->query($request);
        $filters = $request->only(['branch_id', 'status', 'date_from', 'date_to']);

        return PdfExport::download('reports.sales-print', [
            'sales' => $sales,
            'filters' => $filters,
            'generatedAt' => now(),
            'formatCents' => fn ($c) => $this->formatCents($c),
        ], 'sales-report.pdf');
    }
}
