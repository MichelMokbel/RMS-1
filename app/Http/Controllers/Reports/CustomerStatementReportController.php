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

class CustomerStatementReportController extends Controller
{
    private function formatCents(?int $cents): string
    {
        return MinorUnits::format((int) ($cents ?? 0));
    }

    /**
     * @return array{opening_cents:int, entries:Collection<int, array>}
     */
    private function buildStatement(Request $request): array
    {
        $customerId = (int) ($request->integer('customer_id') ?? 0);
        if ($customerId <= 0) {
            return ['opening_cents' => 0, 'entries' => collect()];
        }

        $branchId = $request->integer('branch_id') ?? 0;
        $dateFrom = $request->filled('date_from') ? now()->parse($request->date_from)->startOfDay() : null;
        $dateTo = $request->filled('date_to') ? now()->parse($request->date_to)->endOfDay() : null;

        $invoiceBase = ArInvoice::query()
            ->where('customer_id', $customerId)
            ->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->whereIn('type', ['invoice', 'credit_note'])
            ->when($branchId > 0, fn ($q) => $q->where('branch_id', $branchId));

        $paymentBase = Payment::query()
            ->where('source', 'ar')
            ->where('customer_id', $customerId)
            ->when($branchId > 0, fn ($q) => $q->where('branch_id', $branchId));

        $openingInvoices = $dateFrom ? (int) $invoiceBase->clone()->whereDate('issue_date', '<', $dateFrom)->sum('total_cents') : 0;
        $openingPayments = $dateFrom ? (int) $paymentBase->clone()->whereDate('received_at', '<', $dateFrom)->sum('amount_cents') : 0;
        $opening = $openingInvoices - $openingPayments;

        $entries = collect();

        $invoiceRange = $invoiceBase->clone()
            ->when($dateFrom, fn ($q) => $q->whereDate('issue_date', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('issue_date', '<=', $dateTo))
            ->get()
            ->map(function (ArInvoice $inv) {
                $amount = (int) ($inv->total_cents ?? 0);
                $debit = $amount > 0 ? $amount : 0;
                $credit = $amount < 0 ? abs($amount) : 0;

                return [
                    'date' => $inv->issue_date?->format('Y-m-d') ?? '',
                    'description' => $inv->type === 'credit_note'
                        ? __('Credit Note :no', ['no' => $inv->invoice_number ?: '#'.$inv->id])
                        : __('Invoice :no', ['no' => $inv->invoice_number ?: '#'.$inv->id]),
                    'debit_cents' => $debit,
                    'credit_cents' => $credit,
                    'amount_cents' => $amount,
                ];
            });

        $paymentRange = $paymentBase->clone()
            ->when($dateFrom, fn ($q) => $q->whereDate('received_at', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('received_at', '<=', $dateTo))
            ->get()
            ->map(function (Payment $pay) {
                $amount = (int) ($pay->amount_cents ?? 0);
                return [
                    'date' => $pay->received_at?->format('Y-m-d') ?? '',
                    'description' => __('Payment #:id', ['id' => $pay->id]),
                    'debit_cents' => 0,
                    'credit_cents' => $amount,
                    'amount_cents' => -$amount,
                ];
            });

        $entries = $invoiceRange->merge($paymentRange)->sortBy('date')->values();

        return ['opening_cents' => $opening, 'entries' => $entries];
    }

    public function print(Request $request)
    {
        $statement = $this->buildStatement($request);
        $filters = $request->only(['branch_id', 'customer_id', 'date_from', 'date_to']);
        $customer = $request->filled('customer_id') ? Customer::find($request->integer('customer_id')) : null;

        return view('reports.customer-statement-print', [
            'statement' => $statement,
            'customer' => $customer,
            'filters' => $filters,
            'generatedAt' => now(),
            'formatCents' => fn ($c) => $this->formatCents($c),
        ]);
    }

    public function csv(Request $request): StreamedResponse
    {
        $statement = $this->buildStatement($request);
        $headers = [__('Date'), __('Description'), __('Debit'), __('Credit'), __('Balance')];

        $balance = $statement['opening_cents'];
        $rows = $statement['entries']->map(function ($entry) use (&$balance) {
            $balance += (int) $entry['debit_cents'] - (int) $entry['credit_cents'];
            return [
                $entry['date'],
                $entry['description'],
                $this->formatCents($entry['debit_cents']),
                $this->formatCents($entry['credit_cents']),
                $this->formatCents($balance),
            ];
        });

        return CsvExport::stream($headers, $rows, 'customer-statement.csv');
    }

    public function pdf(Request $request)
    {
        $statement = $this->buildStatement($request);
        $filters = $request->only(['branch_id', 'customer_id', 'date_from', 'date_to']);
        $customer = $request->filled('customer_id') ? Customer::find($request->integer('customer_id')) : null;

        return PdfExport::download('reports.customer-statement-print', [
            'statement' => $statement,
            'customer' => $customer,
            'filters' => $filters,
            'generatedAt' => now(),
            'formatCents' => fn ($c) => $this->formatCents($c),
        ], 'customer-statement.pdf');
    }
}
