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

class DailySalesReportController extends Controller
{
    private function formatCents(?int $cents): string
    {
        return MinorUnits::format((int) ($cents ?? 0));
    }

    /**
     * @return Collection<int, array{date: string, count: int, total_cents: int}>
     */
    private function query(Request $request, int $limit = 2000): Collection
    {
        $rows = ArInvoice::query()
            ->select(['issue_date', 'total_cents'])
            ->where('type', 'invoice')
            ->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->when($request->filled('branch_id') && $request->integer('branch_id') > 0, fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('issue_date', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('issue_date', '<=', $request->date_to))
            ->orderByDesc('issue_date')
            ->limit($limit)
            ->get();

        return $rows
            ->groupBy(fn ($inv) => $inv->issue_date?->format('Y-m-d') ?? '')
            ->map(fn ($group, $date) => [
                'date' => $date,
                'count' => $group->count(),
                'total_cents' => (int) $group->sum('total_cents'),
            ])
            ->values();
    }

    public function print(Request $request)
    {
        $days = $this->query($request);
        $filters = $request->only(['branch_id', 'date_from', 'date_to']);

        return view('reports.daily-sales-print', [
            'days' => $days,
            'filters' => $filters,
            'generatedAt' => now(),
            'formatCents' => fn ($c) => $this->formatCents($c),
        ]);
    }

    public function csv(Request $request): StreamedResponse
    {
        $days = $this->query($request);
        $headers = [__('Date'), __('Count'), __('Total')];
        $rows = $days->map(fn ($row) => [
            $row['date'],
            $row['count'],
            $this->formatCents($row['total_cents']),
        ]);

        return CsvExport::stream($headers, $rows, 'daily-sales-report.csv');
    }

    public function pdf(Request $request)
    {
        $days = $this->query($request);
        $filters = $request->only(['branch_id', 'date_from', 'date_to']);

        return PdfExport::download('reports.daily-sales-print', [
            'days' => $days,
            'filters' => $filters,
            'generatedAt' => now(),
            'formatCents' => fn ($c) => $this->formatCents($c),
        ], 'daily-sales-report.pdf');
    }
}
