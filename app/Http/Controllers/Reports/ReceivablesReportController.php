<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\ArInvoice;
use App\Support\Reports\CsvExport;
use App\Support\Reports\PdfExport;
use App\Support\Money\MinorUnits;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReceivablesReportController extends Controller
{
    private function formatCents(?int $cents): string
    {
        return MinorUnits::format((int) ($cents ?? 0));
    }

    private function query(Request $request, int $limit = 500)
    {
        $paymentType = $request->filled('payment_type') && $request->payment_type !== 'all'
            ? (string) $request->payment_type
            : null;

        return ArInvoice::query()
            ->with(['customer', 'paymentAllocations.payment'])
            ->when($request->filled('branch_id') && $request->integer('branch_id') > 0, fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('status') && $request->status !== 'all', fn ($q) => $q->where('status', $request->status))
            ->when($paymentType !== null, fn (Builder $q) => $this->applyPaymentTypeFilter($q, $paymentType))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('issue_date', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('issue_date', '<=', $request->date_to))
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    private function applyPaymentTypeFilter(Builder $query, string $paymentType): Builder
    {
        $hasCash = fn (Builder $q) => $q
            ->where('amount_cents', '>', 0)
            ->whereHas('payment', fn (Builder $p) => $p->where('method', 'cash'));
        $hasNonCash = fn (Builder $q) => $q
            ->where('amount_cents', '>', 0)
            ->whereHas('payment', fn (Builder $p) => $p->where('method', '!=', 'cash'));

        return match (strtolower($paymentType)) {
            'cash' => $query
                ->whereHas('paymentAllocations', $hasCash)
                ->whereDoesntHave('paymentAllocations', $hasNonCash),
            'card' => $query
                ->whereHas('paymentAllocations', $hasNonCash)
                ->whereDoesntHave('paymentAllocations', $hasCash),
            'mixed' => $query
                ->whereHas('paymentAllocations', $hasCash)
                ->whereHas('paymentAllocations', $hasNonCash),
            'credit' => $query
                ->where('payment_type', 'credit')
                ->whereDoesntHave('paymentAllocations', fn (Builder $q) => $q->where('amount_cents', '>', 0)),
            default => $query->where('payment_type', $paymentType),
        };
    }

    private function resolvedPaymentType(ArInvoice $invoice): string
    {
        $hasCash = false;
        $hasCard = false;

        foreach ($invoice->paymentAllocations as $allocation) {
            $amount = max(0, (int) ($allocation->amount_cents ?? 0));
            if ($amount <= 0) {
                continue;
            }

            $method = strtolower((string) ($allocation->payment?->method ?? ''));
            if ($method === 'cash') {
                $hasCash = true;
            } else {
                // Non-cash settled methods are grouped under card at report level.
                $hasCard = true;
            }
        }

        if ($hasCash && $hasCard) {
            return 'mixed';
        }
        if ($hasCash) {
            return 'cash';
        }
        if ($hasCard) {
            return 'card';
        }

        return strtolower((string) ($invoice->payment_type ?: 'credit'));
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
            'resolvedPaymentType' => fn (ArInvoice $invoice): string => $this->resolvedPaymentType($invoice),
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
            $this->resolvedPaymentType($inv),
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
            'resolvedPaymentType' => fn (ArInvoice $invoice): string => $this->resolvedPaymentType($invoice),
        ], 'receivables-report.pdf');
    }
}
