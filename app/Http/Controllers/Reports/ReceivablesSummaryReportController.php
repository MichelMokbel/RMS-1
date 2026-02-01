<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\ArInvoice;
use App\Support\Money\MinorUnits;
use App\Support\Reports\CsvExport;
use App\Support\Reports\PdfExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReceivablesSummaryReportController extends Controller
{
    private function formatCents(?int $cents): string
    {
        return MinorUnits::format((int) ($cents ?? 0));
    }

    private function query(Request $request)
    {
        return ArInvoice::query()
            ->where('type', 'invoice')
            ->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->when($request->filled('branch_id') && $request->integer('branch_id') > 0, fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('issue_date', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('issue_date', '<=', $request->date_to))
            ->get();
    }

    private function buildSummary(Request $request): array
    {
        $invoices = $this->query($request);
        $asOf = $request->filled('date_to') ? now()->parse($request->date_to) : now();

        $byStatus = $invoices->groupBy('status')->map(function ($group, $status) {
            return [
                'status' => $status,
                'count' => $group->count(),
                'total_cents' => (int) $group->sum('total_cents'),
                'balance_cents' => (int) $group->sum('balance_cents'),
            ];
        })->values();

        $overdue = $invoices->filter(function ($inv) use ($asOf) {
            return $inv->balance_cents > 0
                && $inv->due_date
                && $inv->due_date->lessThan($asOf);
        });

        return [
            'total_invoiced_cents' => (int) $invoices->sum('total_cents'),
            'total_balance_cents' => (int) $invoices->sum('balance_cents'),
            'overdue_balance_cents' => (int) $overdue->sum('balance_cents'),
            'by_status' => $byStatus,
        ];
    }

    public function print(Request $request)
    {
        $summary = $this->buildSummary($request);
        $filters = $request->only(['branch_id', 'date_from', 'date_to']);

        return view('reports.receivables-summary-print', [
            'summary' => $summary,
            'filters' => $filters,
            'generatedAt' => now(),
            'formatCents' => fn ($c) => $this->formatCents($c),
        ]);
    }

    public function csv(Request $request): StreamedResponse
    {
        $summary = $this->buildSummary($request);
        $headers = [__('Status'), __('Count'), __('Total'), __('Balance')];
        $rows = collect($summary['by_status'])->map(fn ($row) => [
            $row['status'],
            $row['count'],
            $this->formatCents($row['total_cents']),
            $this->formatCents($row['balance_cents']),
        ]);

        return CsvExport::stream($headers, $rows, 'receivables-summary.csv');
    }

    public function pdf(Request $request)
    {
        $summary = $this->buildSummary($request);
        $filters = $request->only(['branch_id', 'date_from', 'date_to']);

        return PdfExport::download('reports.receivables-summary-print', [
            'summary' => $summary,
            'filters' => $filters,
            'generatedAt' => now(),
            'formatCents' => fn ($c) => $this->formatCents($c),
        ], 'receivables-summary.pdf');
    }
}
