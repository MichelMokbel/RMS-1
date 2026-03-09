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

class SalesEntryMonthlyReportController extends Controller
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
     * @return Collection<int, array{month:string,branch_id:int,branch:string,count:int,total_cents:int}>
     */
    private function query(Request $request, Carbon $from, Carbon $to, int $limit = 2000): Collection
    {
        $rows = ArInvoice::query()
            ->select(['issue_date', 'total_cents', 'customer_id', 'branch_id'])
            ->where('type', 'invoice')
            ->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->when($request->filled('branch_id') && $request->integer('branch_id') > 0, fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('customer_id') && $request->integer('customer_id') > 0, fn ($q) => $q->where('customer_id', $request->integer('customer_id')))
            ->whereDate('issue_date', '>=', $from->toDateString())
            ->whereDate('issue_date', '<=', $to->toDateString())
            ->orderByDesc('issue_date')
            ->limit($limit)
            ->get();

        $branchNames = Branch::query()
            ->whereIn('id', $rows->pluck('branch_id')->filter()->unique()->values())
            ->pluck('name', 'id');

        return $rows
            ->groupBy(function ($inv) {
                $month = $inv->issue_date?->format('Y-m') ?? '';
                return $month.'|'.(int) ($inv->branch_id ?? 0);
            })
            ->map(function ($group, $key) use ($branchNames) {
                [$month, $branchId] = array_pad(explode('|', (string) $key, 2), 2, '0');
                $branchId = (int) $branchId;

                return [
                    'month' => $month,
                    'branch_id' => $branchId,
                    'branch' => (string) ($branchNames[$branchId] ?? ('Branch '.$branchId)),
                    'count' => $group->count(),
                    'total_cents' => (int) $group->sum('total_cents'),
                ];
            })
            ->sortBy([
                ['month', 'asc'],
                ['branch', 'asc'],
            ])
            ->values();
    }

    public function print(Request $request)
    {
        [$from, $to] = $this->resolvedMonthlyRange($request);
        $months = $this->query($request, $from, $to);
        $filters = array_merge(
            $request->only(['branch_id', 'customer_id']),
            ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()]
        );
        $generatedBy = (string) ($request->user()?->username ?: $request->user()?->name ?: '-');
        $warehouse = $request->integer('branch_id') > 0
            ? (string) (Branch::query()->whereKey($request->integer('branch_id'))->value('name') ?: $request->integer('branch_id'))
            : 'All Branches';

        return view('reports.sales-entry-monthly-print', [
            'months' => $months,
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
        $months = $this->query($request, $from, $to);
        $headers = [__('Month'), __('Branch'), __('Count'), __('Total')];
        $rows = $months->map(fn ($row) => [
            $row['month'],
            $row['branch'],
            $row['count'],
            $this->formatCents($row['total_cents']),
        ]);

        return CsvExport::stream($headers, $rows, 'sales-entry-monthly.csv');
    }

    public function pdf(Request $request)
    {
        [$from, $to] = $this->resolvedMonthlyRange($request);
        $months = $this->query($request, $from, $to);
        $filters = array_merge(
            $request->only(['branch_id', 'customer_id']),
            ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()]
        );
        $generatedBy = (string) ($request->user()?->username ?: $request->user()?->name ?: '-');
        $warehouse = $request->integer('branch_id') > 0
            ? (string) (Branch::query()->whereKey($request->integer('branch_id'))->value('name') ?: $request->integer('branch_id'))
            : 'All Branches';

        return PdfExport::download('reports.sales-entry-monthly-print', [
            'months' => $months,
            'filters' => $filters,
            'generatedAt' => now(),
            'generatedBy' => $generatedBy,
            'warehouse' => $warehouse,
            'salesPerson' => '-',
            'startAt' => $from,
            'endAt' => $to,
            'formatCents' => fn ($c) => $this->formatCents($c),
        ], 'sales-entry-monthly.pdf');
    }
}
