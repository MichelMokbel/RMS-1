<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\ArInvoice;
use App\Models\Customer;
use App\Models\Payment;
use App\Support\Money\MinorUnits;
use App\Support\Reports\CsvExport;
use App\Support\Reports\PdfExport;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomersStatementReportController extends Controller
{
    private function formatCents(?int $cents): string
    {
        return MinorUnits::format((int) ($cents ?? 0));
    }

    /**
     * @return Collection<int, array{customer_id:int, customer_name:string, opening:int, invoices:int, payments:int, closing:int}>
     */
    private function query(Request $request): Collection
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

        $invoiceBefore = $dateFrom
            ? $invoiceBase->clone()
                ->whereDate('issue_date', '<', $dateFrom)
                ->selectRaw('customer_id, SUM(total_cents) as total')
                ->groupBy('customer_id')
                ->pluck('total', 'customer_id')
            : collect();

        $invoiceRange = $invoiceBase->clone()
            ->when($dateFrom, fn ($q) => $q->whereDate('issue_date', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('issue_date', '<=', $dateTo))
            ->selectRaw('customer_id, SUM(total_cents) as total')
            ->groupBy('customer_id')
            ->pluck('total', 'customer_id');

        $paymentBefore = $dateFrom
            ? $paymentBase->clone()
                ->whereDate('received_at', '<', $dateFrom)
                ->selectRaw('customer_id, SUM(amount_cents) as total')
                ->groupBy('customer_id')
                ->pluck('total', 'customer_id')
            : collect();

        $paymentRange = $paymentBase->clone()
            ->when($dateFrom, fn ($q) => $q->whereDate('received_at', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('received_at', '<=', $dateTo))
            ->selectRaw('customer_id, SUM(amount_cents) as total')
            ->groupBy('customer_id')
            ->pluck('total', 'customer_id');

        $customerIds = collect()
            ->merge($invoiceBefore->keys())
            ->merge($invoiceRange->keys())
            ->merge($paymentBefore->keys())
            ->merge($paymentRange->keys())
            ->unique()
            ->values();

        $customers = Customer::whereIn('id', $customerIds)->get()->keyBy('id');

        return $customerIds->map(function ($customerId) use ($customers, $invoiceBefore, $invoiceRange, $paymentBefore, $paymentRange) {
            $opening = (int) ($invoiceBefore[$customerId] ?? 0) - (int) ($paymentBefore[$customerId] ?? 0);
            $invoices = (int) ($invoiceRange[$customerId] ?? 0);
            $payments = (int) ($paymentRange[$customerId] ?? 0);
            $closing = $opening + $invoices - $payments;

            return [
                'customer_id' => (int) $customerId,
                'customer_name' => $customers[$customerId]->name ?? '—',
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
        $filters = $request->only(['branch_id', 'date_from', 'date_to']);

        return view('reports.customers-statement-print', [
            'rows' => $rows,
            'filters' => $filters,
            'generatedAt' => now(),
            'formatCents' => fn ($c) => $this->formatCents($c),
        ]);
    }

    public function csv(Request $request): StreamedResponse
    {
        $rows = $this->query($request);
        $headers = [__('Customer'), __('Opening'), __('Invoices'), __('Payments'), __('Closing')];
        $data = $rows->map(fn ($row) => [
            $row['customer_name'],
            $this->formatCents($row['opening']),
            $this->formatCents($row['invoices']),
            $this->formatCents($row['payments']),
            $this->formatCents($row['closing']),
        ]);

        return CsvExport::stream($headers, $data, 'customers-statement.csv');
    }

    public function pdf(Request $request)
    {
        $rows = $this->query($request);
        $filters = $request->only(['branch_id', 'date_from', 'date_to']);

        return PdfExport::download('reports.customers-statement-print', [
            'rows' => $rows,
            'filters' => $filters,
            'generatedAt' => now(),
            'formatCents' => fn ($c) => $this->formatCents($c),
        ], 'customers-statement.pdf');
    }
}
