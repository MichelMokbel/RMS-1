<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\ApInvoice;
use App\Models\ApPayment;
use App\Models\Supplier;
use App\Support\Reports\CsvExport;
use App\Support\Reports\PdfExport;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupplierStatementReportController extends Controller
{
    private function formatMoney(?float $amount): string
    {
        return number_format((float) ($amount ?? 0), 2, '.', '');
    }

    /**
     * @return array{opening:float, entries:Collection<int, array>}
     */
    private function buildStatement(Request $request): array
    {
        $supplierId = (int) ($request->integer('supplier_id') ?? 0);
        if ($supplierId <= 0) {
            return ['opening' => 0.0, 'entries' => collect()];
        }

        $dateFrom = $request->filled('date_from') ? now()->parse($request->date_from)->startOfDay() : null;
        $dateTo = $request->filled('date_to') ? now()->parse($request->date_to)->endOfDay() : null;

        $invoiceBase = ApInvoice::query()
            ->where('supplier_id', $supplierId)
            ->whereIn('status', ['posted', 'partially_paid', 'paid']);

        $paymentBase = ApPayment::query()
            ->where('supplier_id', $supplierId);

        $openingInvoices = $dateFrom ? (float) $invoiceBase->clone()->whereDate('invoice_date', '<', $dateFrom)->sum('total_amount') : 0.0;
        $openingPayments = $dateFrom ? (float) $paymentBase->clone()->whereDate('payment_date', '<', $dateFrom)->sum('amount') : 0.0;
        $opening = $openingInvoices - $openingPayments;

        $invoiceRange = $invoiceBase->clone()
            ->when($dateFrom, fn ($q) => $q->whereDate('invoice_date', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('invoice_date', '<=', $dateTo))
            ->get()
            ->map(function (ApInvoice $inv) {
                $amount = (float) ($inv->total_amount ?? 0);
                return [
                    'date' => $inv->invoice_date?->format('Y-m-d') ?? '',
                    'description' => __('Invoice :no', ['no' => $inv->invoice_number]),
                    'debit' => 0.0,
                    'credit' => $amount,
                ];
            });

        $paymentRange = $paymentBase->clone()
            ->when($dateFrom, fn ($q) => $q->whereDate('payment_date', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('payment_date', '<=', $dateTo))
            ->get()
            ->map(function (ApPayment $pay) {
                $amount = (float) ($pay->amount ?? 0);
                return [
                    'date' => $pay->payment_date?->format('Y-m-d') ?? '',
                    'description' => __('Payment #:id', ['id' => $pay->id]),
                    'debit' => $amount,
                    'credit' => 0.0,
                ];
            });

        $entries = $invoiceRange->merge($paymentRange)->sortBy('date')->values();

        return ['opening' => $opening, 'entries' => $entries];
    }

    public function print(Request $request)
    {
        $statement = $this->buildStatement($request);
        $filters = $request->only(['supplier_id', 'date_from', 'date_to']);
        $supplier = $request->filled('supplier_id') ? Supplier::find($request->integer('supplier_id')) : null;

        return view('reports.supplier-statement-print', [
            'statement' => $statement,
            'supplier' => $supplier,
            'filters' => $filters,
            'generatedAt' => now(),
            'formatMoney' => fn ($a) => $this->formatMoney($a),
        ]);
    }

    public function csv(Request $request): StreamedResponse
    {
        $statement = $this->buildStatement($request);
        $headers = [__('Date'), __('Description'), __('Debit'), __('Credit'), __('Balance')];

        $balance = $statement['opening'];
        $rows = $statement['entries']->map(function ($entry) use (&$balance) {
            $balance += (float) $entry['credit'] - (float) $entry['debit'];
            return [
                $entry['date'],
                $entry['description'],
                $this->formatMoney($entry['debit']),
                $this->formatMoney($entry['credit']),
                $this->formatMoney($balance),
            ];
        });

        return CsvExport::stream($headers, $rows, 'supplier-statement.csv');
    }

    public function pdf(Request $request)
    {
        $statement = $this->buildStatement($request);
        $filters = $request->only(['supplier_id', 'date_from', 'date_to']);
        $supplier = $request->filled('supplier_id') ? Supplier::find($request->integer('supplier_id')) : null;

        return PdfExport::download('reports.supplier-statement-print', [
            'statement' => $statement,
            'supplier' => $supplier,
            'filters' => $filters,
            'generatedAt' => now(),
            'formatMoney' => fn ($a) => $this->formatMoney($a),
        ], 'supplier-statement.pdf');
    }
}
