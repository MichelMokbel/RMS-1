<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\ArInvoice;
use App\Models\Branch;
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

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function buildPrintRows(Request $request): Collection
    {
        $customerId = (int) ($request->integer('customer_id') ?? 0);
        if ($customerId <= 0) {
            return collect();
        }

        $branchId = $request->integer('branch_id') ?? 0;
        $dateFrom = $request->filled('date_from') ? now()->parse($request->date_from)->startOfDay() : null;
        $dateTo = $request->filled('date_to') ? now()->parse($request->date_to)->endOfDay() : null;
        $asOf = $dateTo ? $dateTo->copy() : now();

        $invoices = ArInvoice::query()
            ->where('customer_id', $customerId)
            ->where('type', 'invoice')
            ->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->when($branchId > 0, fn ($q) => $q->where('branch_id', $branchId))
            ->when($dateFrom, fn ($q) => $q->whereDate('issue_date', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('issue_date', '<=', $dateTo))
            ->orderBy('issue_date')
            ->orderBy('id')
            ->get();

        $branchNames = Branch::query()
            ->whereIn('id', $invoices->pluck('branch_id')->filter()->unique()->values())
            ->pluck('name', 'id');

        return $invoices->values()->map(function (ArInvoice $invoice, int $index) use ($branchNames, $asOf): array {
            $dueDate = $invoice->due_date ?: $invoice->issue_date;
            $days = $dueDate ? max(0, (int) floor((float) $dueDate->diffInDays($asOf, false))) : 0;

            return [
                'line_no' => $index + 1,
                'document_no' => $invoice->invoice_number ?: (string) $invoice->id,
                'document_type' => 'AR Invoice',
                'location' => (string) ($branchNames[(int) $invoice->branch_id] ?? ('Branch '.$invoice->branch_id)),
                'type' => strtolower((string) $invoice->payment_type) === 'credit'
                    ? 'On Credit'
                    : ucfirst((string) ($invoice->payment_type ?: 'Credit')),
                'date' => $invoice->issue_date?->format('d-M-Y') ?? '-',
                'due_date' => $dueDate?->format('d-M-Y') ?? '-',
                'reference_no' => $invoice->lpo_reference ?: ($invoice->pos_reference ?: '-'),
                'amount_cents' => (int) ($invoice->total_cents ?? 0),
                'paid_cents' => (int) ($invoice->paid_total_cents ?? 0),
                'balance_cents' => (int) ($invoice->balance_cents ?? 0),
                'aging_label' => $days.' Days',
                'payment_no' => '-',
            ];
        });
    }

    /**
     * @return array{not_due:int,bucket_1_30:int,bucket_31_60:int,bucket_61_90:int,bucket_over_90:int,total:int}
     */
    private function buildAgingSummary(Request $request): array
    {
        $customerId = (int) ($request->integer('customer_id') ?? 0);
        if ($customerId <= 0) {
            return [
                'not_due' => 0,
                'bucket_1_30' => 0,
                'bucket_31_60' => 0,
                'bucket_61_90' => 0,
                'bucket_over_90' => 0,
                'total' => 0,
            ];
        }

        $branchId = $request->integer('branch_id') ?? 0;
        $asOf = $request->filled('date_to') ? now()->parse($request->date_to)->endOfDay() : now();

        $invoices = ArInvoice::query()
            ->where('customer_id', $customerId)
            ->where('type', 'invoice')
            ->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->where('balance_cents', '>', 0)
            ->when($branchId > 0, fn ($q) => $q->where('branch_id', $branchId))
            ->whereDate('issue_date', '<=', $asOf)
            ->get();

        $aging = [
            'not_due' => 0,
            'bucket_1_30' => 0,
            'bucket_31_60' => 0,
            'bucket_61_90' => 0,
            'bucket_over_90' => 0,
            'total' => 0,
        ];

        foreach ($invoices as $invoice) {
            $balance = (int) ($invoice->balance_cents ?? 0);
            if ($balance <= 0) {
                continue;
            }

            $dueDate = $invoice->due_date ?: $invoice->issue_date;
            $days = $dueDate ? $dueDate->diffInDays($asOf, false) : 0;

            if ($days <= 0) {
                $aging['not_due'] += $balance;
            } elseif ($days <= 30) {
                $aging['bucket_1_30'] += $balance;
            } elseif ($days <= 60) {
                $aging['bucket_31_60'] += $balance;
            } elseif ($days <= 90) {
                $aging['bucket_61_90'] += $balance;
            } else {
                $aging['bucket_over_90'] += $balance;
            }

            $aging['total'] += $balance;
        }

        return $aging;
    }

    /**
     * @return array{period_amount_cents:int,period_paid_cents:int,period_balance_cents:int,previous_balance_cents:int,total_outstanding_cents:int}
     */
    private function buildPrintSummary(Request $request, Collection $rows): array
    {
        $customerId = (int) ($request->integer('customer_id') ?? 0);
        $branchId = $request->integer('branch_id') ?? 0;
        $dateFrom = $request->filled('date_from') ? now()->parse($request->date_from)->startOfDay() : null;

        $periodAmount = (int) $rows->sum('amount_cents');
        $periodPaid = (int) $rows->sum('paid_cents');
        $periodBalance = (int) $rows->sum('balance_cents');

        $previousBalance = 0;
        if ($customerId > 0 && $dateFrom) {
            $previousBalance = (int) ArInvoice::query()
                ->where('customer_id', $customerId)
                ->where('type', 'invoice')
                ->whereIn('status', ['issued', 'partially_paid', 'paid'])
                ->where('balance_cents', '>', 0)
                ->when($branchId > 0, fn ($q) => $q->where('branch_id', $branchId))
                ->whereDate('issue_date', '<', $dateFrom)
                ->sum('balance_cents');
        }

        return [
            'period_amount_cents' => $periodAmount,
            'period_paid_cents' => $periodPaid,
            'period_balance_cents' => $periodBalance,
            'previous_balance_cents' => $previousBalance,
            'total_outstanding_cents' => $previousBalance + $periodBalance,
        ];
    }

    public function print(Request $request)
    {
        $statement = $this->buildStatement($request);
        $filters = $request->only(['branch_id', 'customer_id', 'date_from', 'date_to']);
        $customer = $request->filled('customer_id') ? Customer::find($request->integer('customer_id')) : null;
        $rows = $this->buildPrintRows($request);
        $summary = $this->buildPrintSummary($request, $rows);
        $aging = $this->buildAgingSummary($request);
        $bankDetails = (array) config('reports.customer_statement.bank_details', []);

        return view('reports.customer-statement-print', [
            'statement' => $statement,
            'customer' => $customer,
            'filters' => $filters,
            'rows' => $rows,
            'summary' => $summary,
            'aging' => $aging,
            'bankDetails' => $bankDetails,
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
        $rows = $this->buildPrintRows($request);
        $summary = $this->buildPrintSummary($request, $rows);
        $aging = $this->buildAgingSummary($request);
        $bankDetails = (array) config('reports.customer_statement.bank_details', []);

        return PdfExport::download('reports.customer-statement-print', [
            'statement' => $statement,
            'customer' => $customer,
            'filters' => $filters,
            'rows' => $rows,
            'summary' => $summary,
            'aging' => $aging,
            'bankDetails' => $bankDetails,
            'generatedAt' => now(),
            'formatCents' => fn ($c) => $this->formatCents($c),
        ], 'customer-statement.pdf');
    }
}
