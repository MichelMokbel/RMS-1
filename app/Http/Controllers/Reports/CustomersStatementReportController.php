<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\ArInvoice;
use App\Models\Branch;
use App\Models\Payment;
use App\Support\Money\MinorUnits;
use App\Support\Reports\CsvExport;
use App\Support\Reports\PdfExport;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomersStatementReportController extends Controller
{
    private function formatCents(?int $cents): string
    {
        return MinorUnits::format((int) ($cents ?? 0));
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
     * @return Collection<int, array{customer_id:int,customer_name:string,customer_code:?string,rows:Collection<int,array<string,int|string>>,summary:array{period_amount_cents:int,period_received_cents:int,period_balance_cents:int}}>
     */
    private function querySections(Request $request): Collection
    {
        [$from, $to] = $this->resolvedRange($request);
        $branchId = $request->integer('branch_id') ?? 0;

        $invoices = ArInvoice::query()
            ->with(['customer:id,name,customer_code'])
            ->where('type', 'invoice')
            ->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->when($branchId > 0, fn ($q) => $q->where('branch_id', $branchId))
            ->whereDate('issue_date', '>=', $from->toDateString())
            ->whereDate('issue_date', '<=', $to->toDateString())
            ->orderBy('customer_id')
            ->orderBy('issue_date')
            ->orderBy('id')
            ->get();

        if ($invoices->isEmpty()) {
            return collect();
        }

        $customerIds = $invoices->pluck('customer_id')->filter()->unique()->values()->all();

        $paymentsByCustomer = Payment::query()
            ->where('source', 'ar')
            ->whereNull('voided_at')
            ->whereIn('customer_id', $customerIds)
            ->where('received_at', '>=', $from->toDateTimeString())
            ->where('received_at', '<=', $to->toDateTimeString())
            ->orderBy('received_at')
            ->orderBy('id')
            ->get()
            ->groupBy('customer_id');

        $branchNames = Branch::query()
            ->whereIn('id', $invoices->pluck('branch_id')->filter()->unique()->values())
            ->pluck('name', 'id');

        $asOf = $this->agingAsOf($to);

        return $invoices
            ->groupBy(fn (ArInvoice $invoice) => (int) $invoice->customer_id)
            ->map(function (Collection $group, int $customerId) use ($branchNames, $asOf, $paymentsByCustomer): array {
                /** @var ArInvoice|null $first */
                $first = $group->first();
                $customer = $first?->customer;

                $invoiceRows = $group->values()->map(function (ArInvoice $invoice) use ($branchNames, $asOf): array {
                    $dueDate = $invoice->due_date ?: $invoice->issue_date;
                    $days = $dueDate ? max(0, (int) floor((float) $dueDate->diffInDays($asOf, false))) : 0;
                    $paymentType = strtolower((string) ($invoice->payment_type ?? 'credit'));

                    return [
                        'row_type' => 'invoice',
                        'document_no' => $invoice->invoice_number ?: (string) $invoice->id,
                        'document_type' => 'AR Invoice',
                        'location' => (string) ($branchNames[(int) $invoice->branch_id] ?? ('Branch '.$invoice->branch_id)),
                        'type' => $paymentType === 'credit' ? 'On Credit' : ucfirst((string) ($invoice->payment_type ?: 'Credit')),
                        'date' => $invoice->issue_date?->format('d-M-Y') ?? '-',
                        'due_date' => $dueDate?->format('d-M-Y') ?? '-',
                        'reference_no' => $invoice->lpo_reference ?: ($invoice->pos_reference ?: '-'),
                        'amount_cents' => (int) ($invoice->total_cents ?? 0),
                        'paid_cents' => (int) ($invoice->paid_total_cents ?? 0),
                        'balance_cents' => (int) ($invoice->balance_cents ?? 0),
                        'aging_label' => $days.' Days',
                        'payment_no' => '-',
                        '_sort_date' => $invoice->issue_date?->toDateString() ?? '0000-00-00',
                        '_sort_id' => $invoice->id,
                    ];
                });

                $customerPayments = $paymentsByCustomer->get($customerId, collect());
                $paymentRows = $customerPayments->map(function (Payment $payment) use ($branchNames): array {
                    $method = ucwords(str_replace('_', ' ', (string) ($payment->method ?? '')));
                    $receivedAt = $payment->received_at;

                    return [
                        'row_type' => 'payment',
                        'document_no' => $payment->reference ?: ('PMT-'.$payment->id),
                        'document_type' => 'Payment Receipt',
                        'location' => (string) ($branchNames[(int) $payment->branch_id] ?? ('Branch '.$payment->branch_id)),
                        'type' => $method ?: 'Payment',
                        'date' => $receivedAt?->format('d-M-Y') ?? '-',
                        'due_date' => '-',
                        'reference_no' => $payment->reference ?: '-',
                        'amount_cents' => 0,
                        'paid_cents' => (int) ($payment->amount_cents ?? 0),
                        'balance_cents' => 0,
                        'aging_label' => '-',
                        'payment_no' => $payment->reference ?: ('PMT-'.$payment->id),
                        '_sort_date' => $receivedAt?->toDateString() ?? '0000-00-00',
                        '_sort_id' => $payment->id,
                    ];
                });

                $rows = $invoiceRows->concat($paymentRows)
                    ->sortBy([['_sort_date', 'asc'], ['_sort_id', 'asc']])
                    ->values()
                    ->map(function (array $row, int $index): array {
                        $row['line_no'] = $index + 1;

                        return $row;
                    });

                $periodAmountCents = (int) $rows->where('row_type', 'invoice')->sum('amount_cents');
                $periodReceivedCents = (int) $rows->where('row_type', 'payment')->sum('paid_cents');

                return [
                    'customer_id' => $customerId,
                    'customer_name' => (string) ($customer?->name ?? '—'),
                    'customer_code' => $customer?->customer_code,
                    'rows' => $rows,
                    'summary' => [
                        'period_amount_cents' => $periodAmountCents,
                        'period_received_cents' => $periodReceivedCents,
                        'period_balance_cents' => $periodAmountCents - $periodReceivedCents,
                    ],
                ];
            })
            ->sortBy('customer_name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    /**
     * @param  Collection<int, array{summary:array{period_amount_cents:int,period_received_cents:int,period_balance_cents:int}}>  $sections
     * @return array{period_amount_cents:int,period_received_cents:int,period_balance_cents:int}
     */
    private function grandTotals(Collection $sections): array
    {
        return [
            'period_amount_cents' => (int) $sections->sum(fn (array $section) => (int) ($section['summary']['period_amount_cents'] ?? 0)),
            'period_received_cents' => (int) $sections->sum(fn (array $section) => (int) ($section['summary']['period_received_cents'] ?? 0)),
            'period_balance_cents' => (int) $sections->sum(fn (array $section) => (int) ($section['summary']['period_balance_cents'] ?? 0)),
        ];
    }

    public function print(Request $request)
    {
        [$from, $to] = $this->resolvedRange($request);
        $sections = $this->querySections($request);
        $filters = array_merge($request->only(['branch_id']), ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()]);

        return view('reports.customers-statement-print', [
            'sections' => $sections,
            'grandTotals' => $this->grandTotals($sections),
            'filters' => $filters,
            'generatedAt' => now(),
            'formatCents' => fn ($c) => $this->formatCents($c),
        ]);
    }

    public function csv(Request $request): StreamedResponse
    {
        $sections = $this->querySections($request);
        $headers = [
            __('Customer'),
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

        $data = collect();
        foreach ($sections as $section) {
            foreach ($section['rows'] as $row) {
                $data->push([
                    $section['customer_name'],
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
            }
        }

        return CsvExport::stream($headers, $data, 'customers-statement.csv');
    }

    public function pdf(Request $request)
    {
        [$from, $to] = $this->resolvedRange($request);
        $sections = $this->querySections($request);
        $filters = array_merge($request->only(['branch_id']), ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()]);

        return PdfExport::download('reports.customers-statement-print', [
            'sections' => $sections,
            'grandTotals' => $this->grandTotals($sections),
            'filters' => $filters,
            'generatedAt' => now(),
            'formatCents' => fn ($c) => $this->formatCents($c),
        ], 'customers-statement.pdf');
    }
}
