<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\ApInvoice;
use App\Support\Reports\CsvExport;
use App\Support\Reports\PdfExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PayablesSummaryReportController extends Controller
{
    private function formatMoney(?float $amount): string
    {
        return number_format((float) ($amount ?? 0), 2, '.', '');
    }

    private function query(Request $request)
    {
        return ApInvoice::query()
            ->whereIn('status', ['posted', 'partially_paid', 'paid'])
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('invoice_date', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('invoice_date', '<=', $request->date_to))
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
                'total' => (float) $group->sum('total_amount'),
                'outstanding' => (float) $group->sum(fn ($inv) => max((float) $inv->total_amount - (float) $inv->paidAmount(), 0)),
            ];
        })->values();

        $overdue = $invoices->filter(function ($inv) use ($asOf) {
            return $inv->outstandingAmount() > 0
                && $inv->due_date
                && $inv->due_date->lessThan($asOf);
        });

        return [
            'total' => (float) $invoices->sum('total_amount'),
            'outstanding' => (float) $invoices->sum(fn ($inv) => max((float) $inv->total_amount - (float) $inv->paidAmount(), 0)),
            'overdue' => (float) $overdue->sum(fn ($inv) => max((float) $inv->total_amount - (float) $inv->paidAmount(), 0)),
            'by_status' => $byStatus,
        ];
    }

    public function print(Request $request)
    {
        $summary = $this->buildSummary($request);
        $filters = $request->only(['date_from', 'date_to']);

        return view('reports.payables-summary-print', [
            'summary' => $summary,
            'filters' => $filters,
            'generatedAt' => now(),
            'formatMoney' => fn ($a) => $this->formatMoney($a),
        ]);
    }

    public function csv(Request $request): StreamedResponse
    {
        $summary = $this->buildSummary($request);
        $headers = [__('Status'), __('Count'), __('Total'), __('Outstanding')];
        $rows = collect($summary['by_status'])->map(fn ($row) => [
            $row['status'],
            $row['count'],
            $this->formatMoney($row['total']),
            $this->formatMoney($row['outstanding']),
        ]);

        return CsvExport::stream($headers, $rows, 'payables-summary.csv');
    }

    public function pdf(Request $request)
    {
        $summary = $this->buildSummary($request);
        $filters = $request->only(['date_from', 'date_to']);

        return PdfExport::download('reports.payables-summary-print', [
            'summary' => $summary,
            'filters' => $filters,
            'generatedAt' => now(),
            'formatMoney' => fn ($a) => $this->formatMoney($a),
        ], 'payables-summary.pdf');
    }
}
