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
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerStatementReportController extends Controller
{
    private function formatCents(?int $cents): string
    {
        return MinorUnits::format((int) ($cents ?? 0));
    }

    private function onlyUnpaid(Request $request): bool
    {
        return $request->boolean('only_unpaid');
    }

    private function agingAsOf(Carbon $date): Carbon
    {
        $today = now()->startOfDay();
        $candidate = $date->copy()->startOfDay();

        return $candidate->greaterThan($today) ? $today : $candidate;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolvedRange(Request $request): array
    {
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();

        $from = $request->filled('date_from')
            ? Carbon::parse((string) $request->input('date_from'))->startOfDay()
            : $monthStart->copy()->startOfDay();
        $to = $request->filled('date_to')
            ? Carbon::parse((string) $request->input('date_to'))->endOfDay()
            : $monthEnd->copy()->endOfDay();

        return [$from, $to];
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
        [$dateFrom, $dateTo] = $this->resolvedRange($request);

        $invoiceBase = ArInvoice::query()
            ->where('customer_id', $customerId)
            ->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->whereIn('type', ['invoice', 'credit_note'])
            ->when($branchId > 0, fn ($q) => $q->where('branch_id', $branchId));

        $paymentBase = Payment::query()
            ->where('source', 'ar')
            ->where('customer_id', $customerId)
            ->when($branchId > 0, fn ($q) => $q->where('branch_id', $branchId));

        $openingInvoices = (int) $invoiceBase->clone()->whereDate('issue_date', '<', $dateFrom)->sum('total_cents');
        $openingPayments = (int) $paymentBase->clone()->whereDate('received_at', '<', $dateFrom)->sum('amount_cents');
        $opening = $openingInvoices - $openingPayments;

        $entries = collect();

        $invoiceRange = $invoiceBase->clone()
            ->whereDate('issue_date', '>=', $dateFrom)
            ->whereDate('issue_date', '<=', $dateTo)
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
            ->whereDate('received_at', '>=', $dateFrom)
            ->whereDate('received_at', '<=', $dateTo)
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

        $branchId    = $request->integer('branch_id') ?? 0;
        [$dateFrom, $dateTo] = $this->resolvedRange($request);
        $asOf        = $this->agingAsOf($dateTo);
        $onlyUnpaid  = $this->onlyUnpaid($request);

        // ── Invoices ──────────────────────────────────────────────────────────
        $invoices = ArInvoice::query()
            ->where('customer_id', $customerId)
            ->where('type', 'invoice')
            ->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->when($onlyUnpaid, fn ($q) => $q->where('balance_cents', '>', 0))
            ->when($branchId > 0, fn ($q) => $q->where('branch_id', $branchId))
            ->whereDate('issue_date', '>=', $dateFrom)
            ->whereDate('issue_date', '<=', $dateTo)
            ->orderBy('issue_date')
            ->orderBy('id')
            ->get();

        // ── Payment receipts ──────────────────────────────────────────────────
        $payments = Payment::query()
            ->where('customer_id', $customerId)
            ->where('source', 'ar')
            ->whereNull('voided_at')
            ->when($branchId > 0, fn ($q) => $q->where('branch_id', $branchId))
            ->where('received_at', '>=', $dateFrom)
            ->where('received_at', '<=', $dateTo)
            ->orderBy('received_at')
            ->orderBy('id')
            ->get();

        // ── Branch name lookup ────────────────────────────────────────────────
        $allBranchIds = $invoices->pluck('branch_id')
            ->merge($payments->pluck('branch_id'))
            ->filter()->unique()->values();

        $branchNames = Branch::query()
            ->whereIn('id', $allBranchIds)
            ->pluck('name', 'id');

        // ── Map invoice rows ──────────────────────────────────────────────────
        $invoiceRows = $invoices->map(function (ArInvoice $invoice) use ($branchNames, $asOf): array {
            $dueDate      = $invoice->due_date ?: $invoice->issue_date;
            $days         = $dueDate ? (int) floor((float) $dueDate->diffInDays($asOf, false)) : 0;
            $agingLabel   = $days <= 0 ? __('Not Due') : $days . ' ' . __('Days');

            // balance_cents is authoritative — updated by every payment path.
            // Derive "paid" from it so the row is consistent for old invoices
            // that have no records in the payments table.
            $totalCents   = (int) ($invoice->total_cents ?? 0);
            $balanceCents = max(0, (int) ($invoice->balance_cents ?? 0));
            $paidCents    = max(0, $totalCents - $balanceCents);

            return [
                'row_type'      => 'invoice',
                'sort_date'     => $invoice->issue_date?->timestamp ?? 0,
                'line_no'       => 0,
                'document_no'   => $invoice->invoice_number ?: (string) $invoice->id,
                'document_type' => 'AR Invoice',
                'location'      => (string) ($branchNames[(int) $invoice->branch_id] ?? ('Branch '.$invoice->branch_id)),
                'type'          => strtolower((string) $invoice->payment_type) === 'credit'
                    ? 'On Credit'
                    : ucfirst((string) ($invoice->payment_type ?: 'Credit')),
                'date'          => $invoice->issue_date?->format('d-M-Y') ?? '-',
                'due_date'      => $dueDate?->format('d-M-Y') ?? '-',
                'reference_no'  => $invoice->lpo_reference ?: ($invoice->pos_reference ?: '-'),
                'amount_cents'  => $totalCents,
                'paid_cents'    => $paidCents,
                'balance_cents' => $balanceCents,
                'aging_label'   => $agingLabel,
                'payment_no'    => '-',
            ];
        });

        // ── Map payment rows ──────────────────────────────────────────────────
        $paymentRows = $payments->map(function (Payment $payment) use ($branchNames): array {
            $method = ucwords(str_replace('_', ' ', (string) ($payment->method ?? '')));

            return [
                'row_type'      => 'payment',
                'sort_date'     => $payment->received_at?->timestamp ?? 0,
                'line_no'       => 0,
                'document_no'   => $payment->reference ?: ('PMT-'.$payment->id),
                'document_type' => 'Payment Receipt',
                'location'      => (string) ($branchNames[(int) $payment->branch_id] ?? ('Branch '.$payment->branch_id)),
                'type'          => $method ?: 'Payment',
                'date'          => $payment->received_at?->format('d-M-Y') ?? '-',
                'due_date'      => '-',
                'reference_no'  => $payment->reference ?: '-',
                'amount_cents'  => 0,
                'paid_cents'    => (int) ($payment->amount_cents ?? 0),
                'balance_cents' => 0,
                'aging_label'   => '-',
                'payment_no'    => $payment->reference ?: ('PMT-'.$payment->id),
            ];
        });

        return $invoiceRows->merge($paymentRows)
            ->sortBy([['sort_date', 'asc'], ['row_type', 'asc']])
            ->values()
            ->map(function (array $row, int $index): array {
                $row['line_no'] = $index + 1;
                return $row;
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
        [, $asOf] = $this->resolvedRange($request);
        $asOf = $this->agingAsOf($asOf);

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
            $days = $dueDate ? (int) floor((float) $dueDate->diffInDays($asOf, false)) : 0;

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
     * @return array{period_amount_cents:int,period_received_cents:int,period_balance_cents:int,previous_balance_cents:int,total_outstanding_cents:int}
     */
    private function buildPrintSummary(Request $request, Collection $rows): array
    {
        $customerId = (int) ($request->integer('customer_id') ?? 0);
        $branchId   = $request->integer('branch_id') ?? 0;
        [$dateFrom] = $this->resolvedRange($request);

        // Use balance_cents as the authoritative outstanding per invoice.
        // Derive "received" as total − balance so it captures all payment channels.
        $periodAmount   = (int) $rows->where('row_type', 'invoice')->sum('amount_cents');
        $periodBalance  = (int) $rows->where('row_type', 'invoice')->sum('balance_cents');
        $periodReceived = $periodAmount - $periodBalance;

        $previousInvoiceBalance = 0;
        $previousAdvance        = 0;

        if ($customerId > 0) {
            $previousInvoiceBalance = (int) ArInvoice::query()
                ->where('customer_id', $customerId)
                ->where('type', 'invoice')
                ->whereIn('status', ['issued', 'partially_paid', 'paid'])
                ->where('balance_cents', '>', 0)
                ->when($branchId > 0, fn ($q) => $q->where('branch_id', $branchId))
                ->whereDate('issue_date', '<', $dateFrom)
                ->sum('balance_cents');

            $prevPaidTotal = (int) \Illuminate\Support\Facades\DB::table('payments as p')
                ->where('p.customer_id', $customerId)
                ->where('p.source', 'ar')
                ->whereNull('p.voided_at')
                ->where('p.received_at', '<', $dateFrom)
                ->when($branchId > 0, fn ($q) => $q->where('p.branch_id', $branchId))
                ->sum('p.amount_cents');

            $prevAllocatedTotal = (int) \Illuminate\Support\Facades\DB::table('payment_allocations as pa')
                ->join('payments as p', 'p.id', '=', 'pa.payment_id')
                ->where('p.customer_id', $customerId)
                ->where('p.source', 'ar')
                ->whereNull('p.voided_at')
                ->whereNull('pa.voided_at')
                ->where('p.received_at', '<', $dateFrom)
                ->when($branchId > 0, fn ($q) => $q->where('p.branch_id', $branchId))
                ->sum('pa.amount_cents');

            $previousAdvance = max(0, $prevPaidTotal - $prevAllocatedTotal);
        }

        $previousBalance = max(0, $previousInvoiceBalance - $previousAdvance);

        return [
            'period_amount_cents'    => $periodAmount,
            'period_received_cents'  => $periodReceived,
            'period_balance_cents'   => $periodBalance,
            'previous_balance_cents' => $previousBalance,
            'total_outstanding_cents' => $previousBalance + $periodBalance,
        ];
    }

    public function print(Request $request)
    {
        $statement = $this->buildStatement($request);
        [$from, $to] = $this->resolvedRange($request);
        $filters = array_merge(
            $request->only(['branch_id', 'customer_id', 'only_unpaid']),
            ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()]
        );
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
        if ($this->onlyUnpaid($request)) {
            $headers = [
                __('No'),
                __('Document Type'),
                __('Location'),
                __('Type'),
                __('Date'),
                __('Due Date'),
                __('Reference No'),
                __('Amount'),
                __('Paid'),
                __('Balance'),
                __('Aging'),
                __('Payment No'),
            ];

            $rows = $this->buildPrintRows($request)->map(fn (array $row) => [
                $row['document_no'],
                $row['document_type'],
                $row['location'],
                $row['type'],
                $row['date'],
                $row['due_date'],
                $row['reference_no'],
                $this->formatCents((int) $row['amount_cents']),
                $this->formatCents((int) $row['paid_cents']),
                $this->formatCents((int) $row['balance_cents']),
                $row['aging_label'],
                $row['payment_no'],
            ]);

            return CsvExport::stream($headers, $rows, 'customer-statement.csv');
        }

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
        [$from, $to] = $this->resolvedRange($request);
        $filters = array_merge(
            $request->only(['branch_id', 'customer_id', 'only_unpaid']),
            ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()]
        );
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
