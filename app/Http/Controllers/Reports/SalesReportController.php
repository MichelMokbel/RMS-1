<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\ArInvoice;
use App\Support\Reports\CsvExport;
use App\Support\Reports\PdfExport;
use App\Support\Money\MinorUnits;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalesReportController extends Controller
{
    private function formatCents(?int $cents): string
    {
        return MinorUnits::format((int) ($cents ?? 0));
    }

    private function formatInvoiceDateTime(ArInvoice $invoice): ?string
    {
        if (! $invoice->issue_date) {
            return null;
        }

        $dateTime = $invoice->issue_date->copy();
        if ($invoice->created_at) {
            $dateTime->setTimeFrom($invoice->created_at);
        }

        return $dateTime->format('Y-m-d H:i:s');
    }

    private function query(Request $request, int $limit = 500)
    {
        return ArInvoice::query()
            ->where('type', 'invoice')
            ->whereIn('status', ['issued', 'partially_paid', 'paid', 'voided'])
            ->when($request->filled('branch_id') && $request->integer('branch_id') > 0, fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('status') && $request->status !== 'all', fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('issue_date', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('issue_date', '<=', $request->date_to))
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function print(Request $request)
    {
        $sales = $this->query($request);
        $filters = $request->only(['branch_id', 'status', 'date_from', 'date_to']);

        return view('reports.sales-print', ['sales' => $sales, 'filters' => $filters, 'generatedAt' => now(), 'formatCents' => fn ($c) => $this->formatCents($c)]);
    }

    public function csv(Request $request): StreamedResponse
    {
        $sales = $this->query($request, 2000);
        $headers = [__('Invoice #'), __('POS Ref'), __('Date & Time'), __('Status'), __('Total')];
        $rows = $sales->map(fn ($s) => [
            $s->invoice_number ?: ('#'.$s->id),
            $s->pos_reference ?? '',
            $this->formatInvoiceDateTime($s),
            $s->status,
            $this->formatCents($s->total_cents),
        ]);

        return CsvExport::stream($headers, $rows, 'sales-report.csv');
    }

    public function pdf(Request $request)
    {
        $sales = $this->query($request);
        $filters = $request->only(['branch_id', 'status', 'date_from', 'date_to']);

        return PdfExport::download('reports.sales-print', [
            'sales' => $sales,
            'filters' => $filters,
            'generatedAt' => now(),
            'formatCents' => fn ($c) => $this->formatCents($c),
        ], 'sales-report.pdf');
    }
}
