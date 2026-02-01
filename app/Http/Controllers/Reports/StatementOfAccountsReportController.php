<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\ArInvoice;
use App\Models\Payment;
use App\Support\Money\MinorUnits;
use App\Support\Reports\CsvExport;
use App\Support\Reports\PdfExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StatementOfAccountsReportController extends Controller
{
    private function formatCents(?int $cents): string
    {
        return MinorUnits::format((int) ($cents ?? 0));
    }

    private function buildSummary(Request $request): array
    {
        $branchId = $request->integer('branch_id') ?? 0;
        $dateFrom = $request->filled('date_from') ? now()->parse($request->date_from)->startOfDay() : null;
        $dateTo = $request->filled('date_to') ? now()->parse($request->date_to)->endOfDay() : null;

        $invoiceBase = ArInvoice::query()
            ->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->whereIn('type', ['invoice', 'credit_note'])
            ->when($branchId > 0, fn ($q) => $q->where('branch_id', $branchId));

        $paymentBase = Payment::query()
            ->where('source', 'ar')
            ->when($branchId > 0, fn ($q) => $q->where('branch_id', $branchId));

        $openingInvoices = $dateFrom ? (int) $invoiceBase->clone()->whereDate('issue_date', '<', $dateFrom)->sum('total_cents') : 0;
        $openingPayments = $dateFrom ? (int) $paymentBase->clone()->whereDate('received_at', '<', $dateFrom)->sum('amount_cents') : 0;
        $opening = $openingInvoices - $openingPayments;

        $periodInvoices = (int) $invoiceBase->clone()
            ->when($dateFrom, fn ($q) => $q->whereDate('issue_date', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('issue_date', '<=', $dateTo))
            ->sum('total_cents');

        $periodPayments = (int) $paymentBase->clone()
            ->when($dateFrom, fn ($q) => $q->whereDate('received_at', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('received_at', '<=', $dateTo))
            ->sum('amount_cents');

        $closing = $opening + $periodInvoices - $periodPayments;

        return [
            'opening' => $opening,
            'invoices' => $periodInvoices,
            'payments' => $periodPayments,
            'closing' => $closing,
        ];
    }

    public function print(Request $request)
    {
        $summary = $this->buildSummary($request);
        $filters = $request->only(['branch_id', 'date_from', 'date_to']);

        return view('reports.statement-of-accounts-print', [
            'summary' => $summary,
            'filters' => $filters,
            'generatedAt' => now(),
            'formatCents' => fn ($c) => $this->formatCents($c),
        ]);
    }

    public function csv(Request $request): StreamedResponse
    {
        $summary = $this->buildSummary($request);
        $headers = [__('Opening'), __('Invoices'), __('Payments'), __('Closing')];
        $rows = collect([[
            $this->formatCents($summary['opening']),
            $this->formatCents($summary['invoices']),
            $this->formatCents($summary['payments']),
            $this->formatCents($summary['closing']),
        ]]);

        return CsvExport::stream($headers, $rows, 'statement-of-accounts.csv');
    }

    public function pdf(Request $request)
    {
        $summary = $this->buildSummary($request);
        $filters = $request->only(['branch_id', 'date_from', 'date_to']);

        return PdfExport::download('reports.statement-of-accounts-print', [
            'summary' => $summary,
            'filters' => $filters,
            'generatedAt' => now(),
            'formatCents' => fn ($c) => $this->formatCents($c),
        ], 'statement-of-accounts.pdf');
    }
}
