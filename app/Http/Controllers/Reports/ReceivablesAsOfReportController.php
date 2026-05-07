<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Reports\ReceivablesAsOfReport;
use App\Support\Money\MinorUnits;
use App\Support\Reports\CsvExport;
use App\Support\Reports\PdfExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReceivablesAsOfReportController extends Controller
{
    public function __construct(private readonly ReceivablesAsOfReport $report)
    {
    }

    private function formatCents(?int $cents): string
    {
        return MinorUnits::format((int) ($cents ?? 0));
    }

    private function filters(Request $request): array
    {
        return [
            'as_of_date' => $this->report->asOf($request->all())->toDateString(),
            'branch_id' => $request->integer('branch_id') ?: null,
            'customer_id' => $request->integer('customer_id') ?: null,
        ];
    }

    public function print(Request $request)
    {
        $filters = $this->filters($request);

        return view('reports.receivables-as-of-print', [
            'rows' => $this->report->rows($filters),
            'filters' => $filters,
            'generatedAt' => now(),
            'formatCents' => fn ($c) => $this->formatCents($c),
        ]);
    }

    public function csv(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $headers = [
            __('Customer Code'),
            __('Customer'),
            __('Invoice #'),
            __('Issue Date'),
            __('Due Date'),
            __('Invoice Total'),
            __('Paid As Of'),
            __('Balance As Of'),
            __('Aging'),
        ];

        $rows = $this->report->rows($filters)->map(fn (array $row): array => [
            $row['customer_code'] ?? '',
            $row['customer_name'],
            $row['invoice_number'],
            $row['issue_date'],
            $row['due_date'],
            $this->formatCents($row['total_cents']),
            $this->formatCents($row['paid_as_of_cents']),
            $this->formatCents($row['balance_as_of_cents']),
            $row['aging_label'],
        ]);

        return CsvExport::stream($headers, $rows, 'receivables-as-of-report.csv');
    }

    public function pdf(Request $request)
    {
        $filters = $this->filters($request);

        return PdfExport::download('reports.receivables-as-of-print', [
            'rows' => $this->report->rows($filters),
            'filters' => $filters,
            'generatedAt' => now(),
            'formatCents' => fn ($c) => $this->formatCents($c),
        ], 'receivables-as-of-report.pdf', 'a4', 'landscape');
    }
}
