<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\ArInvoice;
use App\Support\Reports\CsvExport;
use App\Support\Reports\PdfExport;
use App\Support\Money\MinorUnits;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReceivablesReportController extends Controller
{
    private function formatCents(?int $cents): string
    {
        return MinorUnits::format((int) ($cents ?? 0));
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
     * @return Collection<int, ArInvoice>
     */
    private function query(Request $request): Collection
    {
        [$from, $to] = $this->resolvedRange($request);

        $paymentType = $request->filled('payment_type') && $request->payment_type !== 'all'
            ? (string) $request->payment_type
            : null;

        return ArInvoice::query()
            ->with(['customer:id,name,customer_code', 'paymentAllocations.payment'])
            ->where('type', 'invoice')
            ->whereNull('voided_at')
            ->where('balance_cents', '>', 0)
            ->when($request->filled('branch_id') && $request->integer('branch_id') > 0, fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('status') && $request->status !== 'all', fn ($q) => $q->where('status', $request->status))
            ->when($paymentType !== null, fn (Builder $q) => $this->applyPaymentTypeFilter($q, $paymentType))
            ->whereDate('issue_date', '>=', $from->toDateString())
            ->whereDate('issue_date', '<=', $to->toDateString())
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return Collection<int, array{customer_id:int,customer_name:string,customer_code:?string,open_invoices:int,last_invoice_date:?string,receivable_cents:int}>
     */
    private function customerReceivables(Request $request): Collection
    {
        return $this->query($request)
            ->groupBy(fn (ArInvoice $invoice) => (int) $invoice->customer_id)
            ->map(function (Collection $group, int $customerId): array {
                /** @var ArInvoice|null $first */
                $first = $group->first();
                $customer = $first?->customer;
                $latestIssueDate = $group
                    ->pluck('issue_date')
                    ->filter()
                    ->sortDesc()
                    ->first();

                return [
                    'customer_id' => $customerId,
                    'customer_name' => $customer?->name ?? '—',
                    'customer_code' => $customer?->customer_code,
                    'open_invoices' => $group->count(),
                    'last_invoice_date' => $latestIssueDate?->format('Y-m-d'),
                    'receivable_cents' => (int) $group->sum('balance_cents'),
                ];
            })
            ->sort(function (array $a, array $b): int {
                $amountCmp = ($b['receivable_cents'] <=> $a['receivable_cents']);
                if ($amountCmp !== 0) {
                    return $amountCmp;
                }

                $nameCmp = strnatcasecmp((string) $a['customer_name'], (string) $b['customer_name']);
                if ($nameCmp !== 0) {
                    return $nameCmp;
                }

                return ((int) $a['customer_id']) <=> ((int) $b['customer_id']);
            })
            ->values();
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

    public function print(Request $request)
    {
        $receivables = $this->customerReceivables($request);
        [$from, $to] = $this->resolvedRange($request);
        $filters = array_merge(
            $request->only(['branch_id', 'status', 'payment_type']),
            ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()]
        );

        return view('reports.receivables-print', [
            'receivables' => $receivables,
            'filters' => $filters,
            'generatedAt' => now(),
            'formatCents' => fn ($c) => $this->formatCents($c),
        ]);
    }

    public function csv(Request $request): StreamedResponse
    {
        $receivables = $this->customerReceivables($request);
        $headers = [__('Customer Code'), __('Customer'), __('Open Invoices'), __('Last Invoice Date'), __('Receivable')];
        $rows = $receivables->map(fn ($row) => [
            $row['customer_code'] ?? '',
            $row['customer_name'],
            $row['open_invoices'],
            $row['last_invoice_date'],
            $this->formatCents($row['receivable_cents']),
        ]);

        return CsvExport::stream($headers, $rows, 'receivables-report.csv');
    }

    public function pdf(Request $request)
    {
        $receivables = $this->customerReceivables($request);
        [$from, $to] = $this->resolvedRange($request);
        $filters = array_merge(
            $request->only(['branch_id', 'status', 'payment_type']),
            ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()]
        );

        return PdfExport::download('reports.receivables-print', [
            'receivables' => $receivables,
            'filters' => $filters,
            'generatedAt' => now(),
            'formatCents' => fn ($c) => $this->formatCents($c),
        ], 'receivables-report.pdf');
    }
}
