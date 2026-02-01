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

class SuppliersStatementReportController extends Controller
{
    private function formatMoney(?float $amount): string
    {
        return number_format((float) ($amount ?? 0), 2, '.', '');
    }

    /**
     * @return Collection<int, array{supplier_id:int, supplier_name:string, opening:float, invoices:float, payments:float, closing:float}>
     */
    private function query(Request $request): Collection
    {
        $dateFrom = $request->filled('date_from') ? now()->parse($request->date_from)->startOfDay() : null;
        $dateTo = $request->filled('date_to') ? now()->parse($request->date_to)->endOfDay() : null;

        $invoiceBase = ApInvoice::query()
            ->whereIn('status', ['posted', 'partially_paid', 'paid']);

        $paymentBase = ApPayment::query();

        $invoiceBefore = $dateFrom
            ? $invoiceBase->clone()
                ->whereDate('invoice_date', '<', $dateFrom)
                ->selectRaw('supplier_id, SUM(total_amount) as total')
                ->groupBy('supplier_id')
                ->pluck('total', 'supplier_id')
            : collect();

        $invoiceRange = $invoiceBase->clone()
            ->when($dateFrom, fn ($q) => $q->whereDate('invoice_date', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('invoice_date', '<=', $dateTo))
            ->selectRaw('supplier_id, SUM(total_amount) as total')
            ->groupBy('supplier_id')
            ->pluck('total', 'supplier_id');

        $paymentBefore = $dateFrom
            ? $paymentBase->clone()
                ->whereDate('payment_date', '<', $dateFrom)
                ->selectRaw('supplier_id, SUM(amount) as total')
                ->groupBy('supplier_id')
                ->pluck('total', 'supplier_id')
            : collect();

        $paymentRange = $paymentBase->clone()
            ->when($dateFrom, fn ($q) => $q->whereDate('payment_date', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('payment_date', '<=', $dateTo))
            ->selectRaw('supplier_id, SUM(amount) as total')
            ->groupBy('supplier_id')
            ->pluck('total', 'supplier_id');

        $supplierIds = collect()
            ->merge($invoiceBefore->keys())
            ->merge($invoiceRange->keys())
            ->merge($paymentBefore->keys())
            ->merge($paymentRange->keys())
            ->unique()
            ->values();

        $suppliers = Supplier::whereIn('id', $supplierIds)->get()->keyBy('id');

        return $supplierIds->map(function ($supplierId) use ($suppliers, $invoiceBefore, $invoiceRange, $paymentBefore, $paymentRange) {
            $opening = (float) ($invoiceBefore[$supplierId] ?? 0) - (float) ($paymentBefore[$supplierId] ?? 0);
            $invoices = (float) ($invoiceRange[$supplierId] ?? 0);
            $payments = (float) ($paymentRange[$supplierId] ?? 0);
            $closing = $opening + $invoices - $payments;

            return [
                'supplier_id' => (int) $supplierId,
                'supplier_name' => $suppliers[$supplierId]->name ?? '—',
                'opening' => $opening,
                'invoices' => $invoices,
                'payments' => $payments,
                'closing' => $closing,
            ];
        });
    }

    public function print(Request $request)
    {
        $rows = $this->query($request);
        $filters = $request->only(['date_from', 'date_to']);

        return view('reports.suppliers-statement-print', [
            'rows' => $rows,
            'filters' => $filters,
            'generatedAt' => now(),
            'formatMoney' => fn ($a) => $this->formatMoney($a),
        ]);
    }

    public function csv(Request $request): StreamedResponse
    {
        $rows = $this->query($request);
        $headers = [__('Supplier'), __('Opening'), __('Invoices'), __('Payments'), __('Closing')];
        $data = $rows->map(fn ($row) => [
            $row['supplier_name'],
            $this->formatMoney($row['opening']),
            $this->formatMoney($row['invoices']),
            $this->formatMoney($row['payments']),
            $this->formatMoney($row['closing']),
        ]);

        return CsvExport::stream($headers, $data, 'suppliers-statement.csv');
    }

    public function pdf(Request $request)
    {
        $rows = $this->query($request);
        $filters = $request->only(['date_from', 'date_to']);

        return PdfExport::download('reports.suppliers-statement-print', [
            'rows' => $rows,
            'filters' => $filters,
            'generatedAt' => now(),
            'formatMoney' => fn ($a) => $this->formatMoney($a),
        ], 'suppliers-statement.pdf');
    }
}
