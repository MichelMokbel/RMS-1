<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\AP\ApReportsService;
use App\Support\Reports\CsvExport;
use App\Support\Reports\PdfExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PayablesReportController extends Controller
{
    private function formatMoney(?float $amount): string
    {
        return number_format((float) ($amount ?? 0), 3, '.', '');
    }

    public function print(Request $request, ApReportsService $reportsService)
    {
        $tab = $request->get('tab', 'invoices');
        $invoiceFilters = [
            'supplier_id' => $request->filled('supplier_id') ? $request->integer('supplier_id') : null,
            'status' => $request->get('invoice_status', 'all'),
            'invoice_date_from' => $request->get('invoice_date_from'),
            'invoice_date_to' => $request->get('invoice_date_to'),
            'per_page' => 500,
        ];
        $paymentFilters = [
            'supplier_id' => $request->filled('supplier_id') ? $request->integer('supplier_id') : null,
            'payment_date_from' => $request->get('payment_date_from'),
            'payment_date_to' => $request->get('payment_date_to'),
            'payment_method' => $request->get('payment_method'),
            'per_page' => 500,
        ];
        $invoicePage = $reportsService->invoiceRegister($invoiceFilters);
        $paymentPage = $reportsService->paymentRegister($paymentFilters);
        $aging = $reportsService->agingSummary($request->filled('supplier_id') ? $request->integer('supplier_id') : null);
        $filters = $request->only(['tab', 'supplier_id', 'invoice_status', 'invoice_date_from', 'invoice_date_to', 'payment_date_from', 'payment_date_to', 'payment_method']);

        return view('reports.payables-print', [
            'tab' => $tab,
            'invoicePage' => $invoicePage,
            'paymentPage' => $paymentPage,
            'aging' => $aging,
            'filters' => $filters,
            'generatedAt' => now(),
            'formatMoney' => fn ($a) => $this->formatMoney($a),
        ]);
    }

    public function csv(Request $request, ApReportsService $reportsService): StreamedResponse
    {
        $tab = $request->get('tab', 'invoices');
        if ($tab === 'invoices') {
            $invoiceFilters = [
                'supplier_id' => $request->filled('supplier_id') ? $request->integer('supplier_id') : null,
                'status' => $request->get('invoice_status', 'all'),
                'invoice_date_from' => $request->get('invoice_date_from'),
                'invoice_date_to' => $request->get('invoice_date_to'),
                'per_page' => 2000,
            ];
            $page = $reportsService->invoiceRegister($invoiceFilters);
            $headers = [__('Invoice #'), __('Supplier'), __('Date'), __('Due'), __('Status'), __('Total'), __('Outstanding')];
            $rows = $page->getCollection()->map(function ($inv) {
                $outstanding = max((float) $inv->total_amount - (float) ($inv->paid_sum ?? 0), 0);
                return [$inv->invoice_number, $inv->supplier?->name ?? '', $inv->invoice_date?->format('Y-m-d'), $inv->due_date?->format('Y-m-d'), $inv->status, $this->formatMoney($inv->total_amount), $this->formatMoney($outstanding)];
            });
        } else {
            $paymentFilters = [
                'supplier_id' => $request->filled('supplier_id') ? $request->integer('supplier_id') : null,
                'payment_date_from' => $request->get('payment_date_from'),
                'payment_date_to' => $request->get('payment_date_to'),
                'payment_method' => $request->get('payment_method'),
                'per_page' => 2000,
            ];
            $page = $reportsService->paymentRegister($paymentFilters);
            $headers = [__('Payment #'), __('Supplier'), __('Date'), __('Method'), __('Amount')];
            $rows = $page->getCollection()->map(fn ($pay) => [$pay->id, $pay->supplier?->name ?? '', $pay->payment_date?->format('Y-m-d'), $pay->payment_method ?? '', $this->formatMoney($pay->amount ?? 0)]);
        }

        return CsvExport::stream($headers, $rows, 'payables-report.csv');
    }

    public function pdf(Request $request, ApReportsService $reportsService)
    {
        $tab = $request->get('tab', 'invoices');
        $invoiceFilters = [
            'supplier_id' => $request->filled('supplier_id') ? $request->integer('supplier_id') : null,
            'status' => $request->get('invoice_status', 'all'),
            'invoice_date_from' => $request->get('invoice_date_from'),
            'invoice_date_to' => $request->get('invoice_date_to'),
            'per_page' => 500,
        ];
        $paymentFilters = [
            'supplier_id' => $request->filled('supplier_id') ? $request->integer('supplier_id') : null,
            'payment_date_from' => $request->get('payment_date_from'),
            'payment_date_to' => $request->get('payment_date_to'),
            'payment_method' => $request->get('payment_method'),
            'per_page' => 500,
        ];
        $invoicePage = $reportsService->invoiceRegister($invoiceFilters);
        $paymentPage = $reportsService->paymentRegister($paymentFilters);
        $aging = $reportsService->agingSummary($request->filled('supplier_id') ? $request->integer('supplier_id') : null);
        $filters = $request->only(['tab', 'supplier_id', 'invoice_status', 'invoice_date_from', 'invoice_date_to', 'payment_date_from', 'payment_date_to', 'payment_method']);

        return PdfExport::download('reports.payables-print', [
            'tab' => $tab,
            'invoicePage' => $invoicePage,
            'paymentPage' => $paymentPage,
            'aging' => $aging,
            'filters' => $filters,
            'generatedAt' => now(),
            'formatMoney' => fn ($a) => $this->formatMoney($a),
        ], 'payables-report.pdf');
    }
}
