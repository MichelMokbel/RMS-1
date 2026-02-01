<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\ArInvoice;
use App\Support\Money\MinorUnits;
use App\Support\Reports\CsvExport;
use App\Support\Reports\PdfExport;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalesEntryMonthlyReportController extends Controller
{
    private function formatCents(?int $cents): string
    {
        return MinorUnits::format((int) ($cents ?? 0));
    }

    /**
     * @return Collection<int, array{month: string, count: int, total_cents: int}>
     */
    private function query(Request $request, int $limit = 2000): Collection
    {
        $rows = ArInvoice::query()
            ->select(['issue_date', 'total_cents', 'customer_id', 'branch_id'])
            ->where('type', 'invoice')
            ->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->when($request->filled('branch_id') && $request->integer('branch_id') > 0, fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('customer_id') && $request->integer('customer_id') > 0, fn ($q) => $q->where('customer_id', $request->integer('customer_id')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('issue_date', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('issue_date', '<=', $request->date_to))
            ->orderByDesc('issue_date')
            ->limit($limit)
            ->get();

        return $rows
            ->groupBy(fn ($inv) => $inv->issue_date?->format('Y-m') ?? '')
            ->map(fn ($group, $month) => [
                'month' => $month,
                'count' => $group->count(),
                'total_cents' => (int) $group->sum('total_cents'),
            ])
            ->values();
    }

    public function print(Request $request)
    {
        $months = $this->query($request);
        $filters = $request->only(['branch_id', 'customer_id', 'date_from', 'date_to']);

        return view('reports.sales-entry-monthly-print', [
            'months' => $months,
            'filters' => $filters,
            'generatedAt' => now(),
            'formatCents' => fn ($c) => $this->formatCents($c),
        ]);
    }

    public function csv(Request $request): StreamedResponse
    {
        $months = $this->query($request);
        $headers = [__('Month'), __('Count'), __('Total')];
        $rows = $months->map(fn ($row) => [
            $row['month'],
            $row['count'],
            $this->formatCents($row['total_cents']),
        ]);

        return CsvExport::stream($headers, $rows, 'sales-entry-monthly.csv');
    }

    public function pdf(Request $request)
    {
        $months = $this->query($request);
        $filters = $request->only(['branch_id', 'customer_id', 'date_from', 'date_to']);

        return PdfExport::download('reports.sales-entry-monthly-print', [
            'months' => $months,
            'filters' => $filters,
            'generatedAt' => now(),
            'formatCents' => fn ($c) => $this->formatCents($c),
        ], 'sales-entry-monthly.pdf');
    }
}
