<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\ArInvoice;
use App\Support\Money\MinorUnits;
use App\Support\Reports\CsvExport;
use App\Support\Reports\PdfExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalesAllReportController extends Controller
{
    private function formatCents(?int $cents): string
    {
        return MinorUnits::format((int) ($cents ?? 0));
    }

    private function query(Request $request, int $limit = 500)
    {
        return ArInvoice::query()
            ->with(['customer'])
            ->where('type', 'invoice')
            ->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->when($request->filled('branch_id') && $request->integer('branch_id') > 0, fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('customer_id') && $request->integer('customer_id') > 0, fn ($q) => $q->where('customer_id', $request->integer('customer_id')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('issue_date', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('issue_date', '<=', $request->date_to))
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function print(Request $request)
    {
        $invoices = $this->query($request);
        $filters = $request->only(['branch_id', 'customer_id', 'date_from', 'date_to']);

        return view('reports.sales-all-print', [
            'invoices' => $invoices,
            'filters' => $filters,
            'generatedAt' => now(),
            'formatCents' => fn ($c) => $this->formatCents($c),
        ]);
    }

    public function csv(Request $request): StreamedResponse
    {
        $invoices = $this->query($request, 2000);
        $headers = [__('Invoice #'), __('Date'), __('Branch'), __('Customer'), __('Total')];
        $rows = $invoices->map(fn ($inv) => [
            $inv->invoice_number ?: ('#'.$inv->id),
            $inv->issue_date?->format('Y-m-d'),
            $inv->branch_id,
            $inv->customer?->name ?? '',
            $this->formatCents($inv->total_cents),
        ]);

        return CsvExport::stream($headers, $rows, 'sales-all-report.csv');
    }

    public function pdf(Request $request)
    {
        $invoices = $this->query($request);
        $filters = $request->only(['branch_id', 'customer_id', 'date_from', 'date_to']);

        return PdfExport::download('reports.sales-all-print', [
            'invoices' => $invoices,
            'filters' => $filters,
            'generatedAt' => now(),
            'formatCents' => fn ($c) => $this->formatCents($c),
        ], 'sales-all-report.pdf');
    }
}
