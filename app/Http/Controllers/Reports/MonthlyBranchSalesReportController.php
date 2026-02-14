<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\ArInvoice;
use App\Models\Branch;
use App\Support\Money\MinorUnits;
use App\Support\Reports\CsvExport;
use App\Support\Reports\PdfExport;
use Carbon\Carbon;
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
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolvedMonthlyRange(Request $request): array
    {
        $start = now()->startOfMonth();
        $end = now()->endOfMonth();

        $from = $request->filled('date_from')
            ? Carbon::parse((string) $request->input('date_from'))->startOfDay()
            : $start->copy()->startOfDay();
        $to = $request->filled('date_to')
            ? Carbon::parse((string) $request->input('date_to'))->endOfDay()
            : $end->copy()->endOfDay();

        return [$from, $to];
    }

    /**
     * @return Collection<int, array{month: string, branch_id: int, total_cents: int, count: int}>
     */
    private function query(Request $request, Carbon $from, Carbon $to, int $limit = 5000): Collection
    {
        $rows = ArInvoice::query()
            ->select(['issue_date', 'branch_id', 'total_cents'])
            ->where('type', 'invoice')
            ->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->when($request->filled('branch_id') && $request->integer('branch_id') > 0, fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->whereDate('issue_date', '>=', $from->toDateString())
            ->whereDate('issue_date', '<=', $to->toDateString())
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
        [$from, $to] = $this->resolvedMonthlyRange($request);
        $rows = $this->query($request, $from, $to);
        $filters = array_merge(
            $request->only(['branch_id']),
            ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()]
        );
        $generatedBy = (string) ($request->user()?->username ?: $request->user()?->name ?: '-');
        $warehouse = $request->integer('branch_id') > 0
            ? (string) (Branch::query()->whereKey($request->integer('branch_id'))->value('name') ?: $request->integer('branch_id'))
            : 'All Branches';

        return view('reports.monthly-branch-sales-print', [
            'rows' => $rows,
            'filters' => $filters,
            'generatedAt' => now(),
            'generatedBy' => $generatedBy,
            'warehouse' => $warehouse,
            'salesPerson' => '-',
            'startAt' => $from,
            'endAt' => $to,
            'formatCents' => fn ($c) => $this->formatCents($c),
        ]);
    }

    public function csv(Request $request): StreamedResponse
    {
        [$from, $to] = $this->resolvedMonthlyRange($request);
        $rows = $this->query($request, $from, $to);
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
        [$from, $to] = $this->resolvedMonthlyRange($request);
        $rows = $this->query($request, $from, $to);
        $filters = array_merge(
            $request->only(['branch_id']),
            ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()]
        );
        $generatedBy = (string) ($request->user()?->username ?: $request->user()?->name ?: '-');
        $warehouse = $request->integer('branch_id') > 0
            ? (string) (Branch::query()->whereKey($request->integer('branch_id'))->value('name') ?: $request->integer('branch_id'))
            : 'All Branches';

        return PdfExport::download('reports.monthly-branch-sales-print', [
            'rows' => $rows,
            'filters' => $filters,
            'generatedAt' => now(),
            'generatedBy' => $generatedBy,
            'warehouse' => $warehouse,
            'salesPerson' => '-',
            'startAt' => $from,
            'endAt' => $to,
            'formatCents' => fn ($c) => $this->formatCents($c),
        ], 'monthly-branch-sales.pdf');
    }
}
