<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\ArInvoice;
use App\Support\Reports\CsvExport;
use App\Support\Reports\PdfExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReceivablesReportController extends Controller
{
    private function formatCents(?int $cents): string
    {
        $cents = (int) ($cents ?? 0);
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);
        $whole = intdiv($cents, 1000);
        $frac = $cents % 1000;
        return $sign.$whole.'.'.str_pad((string) $frac, 3, '0', STR_PAD_LEFT);
    }

    private function query(Request $request, int $limit = 500)
    {
        return ArInvoice::query()
            ->with(['customer'])
            ->when($request->filled('branch_id') && $request->integer('branch_id') > 0, fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('status') && $request->status !== 'all', fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('payment_type') && $request->payment_type !== 'all', fn ($q) => $q->where('payment_type', $request->payment_type))
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
        $filters = $request->only(['branch_id', 'status', 'payment_type', 'date_from', 'date_to']);

        return view('reports.receivables-print', [
            'invoices' => $invoices,
            'filters' => $filters,
            'generatedAt' => now(),
            'formatCents' => fn ($c) => $this->formatCents($c),
        ]);
    }

    public function csv(Request $request): StreamedResponse
    {
        $invoices = $this->query($request, 2000);
        $headers = [__('Invoice #'), __('Customer'), __('Date'), __('Status'), __('Payment Type'), __('Total'), __('Balance')];
        $rows = $invoices->map(fn ($inv) => [
            $inv->invoice_number ?: ('#'.$inv->id),
            $inv->customer?->name ?? '',
            $inv->issue_date?->format('Y-m-d'),
            $inv->status ?? '',
            $inv->payment_type ?? '',
            $this->formatCents($inv->total_cents),
            $this->formatCents($inv->balance_cents),
        ]);

        return CsvExport::stream($headers, $rows, 'receivables-report.csv');
    }

    public function pdf(Request $request)
    {
        $invoices = $this->query($request);
        $filters = $request->only(['branch_id', 'status', 'payment_type', 'date_from', 'date_to']);

        return PdfExport::download('reports.receivables-print', [
            'invoices' => $invoices,
            'filters' => $filters,
            'generatedAt' => now(),
            'formatCents' => fn ($c) => $this->formatCents($c),
        ], 'receivables-report.pdf');
    }
}
