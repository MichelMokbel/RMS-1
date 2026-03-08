<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Spend\SpendReportService;
use App\Support\Reports\CsvExport;
use App\Support\Reports\PdfExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExpensesReportController extends Controller
{
    private function query(Request $request, int $limit = 500)
    {
        return app(SpendReportService::class)->collect(
            filters: [
                'search' => $request->input('search'),
                'source' => $request->input('source', 'all'),
                'supplier_id' => $request->integer('supplier_id') ?: null,
                'category_id' => $request->integer('category_id') ?: null,
                'payment_status' => $request->input('payment_status', 'all'),
                'payment_method' => $request->input('payment_method', 'all'),
                'date_from' => $request->input('date_from'),
                'date_to' => $request->input('date_to'),
            ],
            limit: $limit
        );
    }

    public function print(Request $request)
    {
        $expenses = $this->query($request);
        $filters = $request->only(['search', 'source', 'supplier_id', 'category_id', 'payment_status', 'payment_method', 'date_from', 'date_to']);

        return view('reports.expenses-print', ['expenses' => $expenses, 'filters' => $filters, 'generatedAt' => now()]);
    }

    public function csv(Request $request): StreamedResponse
    {
        $expenses = $this->query($request, 2000);
        $headers = [__('Date'), __('Reference'), __('Source'), __('Description'), __('Supplier'), __('Category'), __('Status'), __('Amount')];
        $rows = $expenses->map(fn ($e) => [
            $e['date'] ?? '',
            $e['reference'] ?? '',
            $e['source'] ?? '',
            $e['description'] ?? '',
            $e['supplier'] ?? '',
            $e['category'] ?? '',
            $e['status'] ?? '',
            number_format((float) ($e['amount'] ?? 0), 3, '.', ''),
        ]);

        return CsvExport::stream($headers, $rows, 'expenses-report.csv');
    }

    public function pdf(Request $request)
    {
        $expenses = $this->query($request);
        $filters = $request->only(['search', 'source', 'supplier_id', 'category_id', 'payment_status', 'payment_method', 'date_from', 'date_to']);

        return PdfExport::download('reports.expenses-print', ['expenses' => $expenses, 'filters' => $filters, 'generatedAt' => now()], 'expenses-report.pdf');
    }
}
