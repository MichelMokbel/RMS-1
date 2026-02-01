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

class MonthlyBranchSalesReportController extends Controller
{
    private function formatCents(?int $cents): string
    {
        return MinorUnits::format((int) ($cents ?? 0));
    }

    /**
     * @return Collection<int, array{month: string, branch_id: int, total_cents: int, count: int}>
     */
    private function query(Request $request, int $limit = 5000): Collection
    {
        $rows = ArInvoice::query()
            ->select(['issue_date', 'branch_id', 'total_cents'])
            ->where('type', 'invoice')
            ->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->when($request->filled('branch_id') && $request->integer('branch_id') > 0, fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('issue_date', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('issue_date', '<=', $request->date_to))
            ->orderByDesc('issue_date')
            ->limit($limit)
            ->get();

        return $rows
            ->groupBy(function ($inv) {
                $month = $inv->issue_date?->format('Y-m') ?? '';
                return $month.'|'.$inv->branch_id;
            })
            ->map(function ($group, $key) {
                [$month, $branchId] = explode('|', $key);
                return [
                    'month' => $month,
                    'branch_id' => (int) $branchId,
                    'count' => $group->count(),
                    'total_cents' => (int) $group->sum('total_cents'),
                ];
            })
            ->values();
    }

    public function print(Request $request)
    {
        $rows = $this->query($request);
        $filters = $request->only(['branch_id', 'date_from', 'date_to']);

        return view('reports.monthly-branch-sales-print', [
            'rows' => $rows,
            'filters' => $filters,
            'generatedAt' => now(),
            'formatCents' => fn ($c) => $this->formatCents($c),
        ]);
    }

    public function csv(Request $request): StreamedResponse
    {
        $rows = $this->query($request);
        $headers = [__('Month'), __('Branch'), __('Count'), __('Total')];
        $data = $rows->map(fn ($row) => [
            $row['month'],
            $row['branch_id'],
            $row['count'],
            $this->formatCents($row['total_cents']),
        ]);

        return CsvExport::stream($headers, $data, 'monthly-branch-sales.csv');
    }

    public function pdf(Request $request)
    {
        $rows = $this->query($request);
        $filters = $request->only(['branch_id', 'date_from', 'date_to']);

        return PdfExport::download('reports.monthly-branch-sales-print', [
            'rows' => $rows,
            'filters' => $filters,
            'generatedAt' => now(),
            'formatCents' => fn ($c) => $this->formatCents($c),
        ], 'monthly-branch-sales.pdf');
    }
}
