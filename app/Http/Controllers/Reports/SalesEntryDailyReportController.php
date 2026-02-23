<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\ArInvoice;
use App\Models\Branch;
use App\Support\Money\MinorUnits;
use App\Support\Reports\CsvExport;
use App\Support\Reports\PdfExport;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalesEntryDailyReportController extends Controller
{
    private function formatCents(?int $cents): string
    {
        return MinorUnits::format((int) ($cents ?? 0));
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolvedDailyRange(Request $request): array
    {
        $today = now();
        $from = $request->filled('date_from')
            ? Carbon::parse((string) $request->input('date_from'))->startOfDay()
            : $today->copy()->startOfDay();
        $to = $request->filled('date_to')
            ? Carbon::parse((string) $request->input('date_to'))->endOfDay()
            : $today->copy()->endOfDay();

        return [$from, $to];
    }

    private function query(Request $request, Carbon $from, Carbon $to, int $limit = 500): Collection
    {
        return ArInvoice::query()
            ->with(['customer', 'salesPerson', 'paymentAllocations.payment'])
            ->where('type', 'invoice')
            ->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->when($request->filled('branch_id') && $request->integer('branch_id') > 0, fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('customer_id') && $request->integer('customer_id') > 0, fn ($q) => $q->where('customer_id', $request->integer('customer_id')))
            ->whereDate('issue_date', '>=', $from->toDateString())
            ->whereDate('issue_date', '<=', $to->toDateString())
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  Collection<int, ArInvoice>  $invoices
     * @return Collection<int, array<string, int|string|null>>
     */
    private function buildRows(Collection $invoices): Collection
    {
        return $invoices->values()->map(function (ArInvoice $inv, int $index): array {
            $tradeRevenue = (int) ($inv->subtotal_cents ?? 0);
            $discount = (int) ($inv->discount_total_cents ?? 0);
            $netAmount = (int) ($inv->total_cents ?? 0);
            ['cash_cents' => $cash, 'card_cents' => $card, 'credit_cents' => $credit] = $this->paymentBreakdownCents($inv, $netAmount);
            $totalCollection = $cash + $card + $credit;

            return [
                'si' => $index + 1,
                'date' => $inv->issue_date?->format('Y-m-d'),
                'invoice_number' => $inv->invoice_number ?: ('#'.$inv->id),
                'pos_ref' => $inv->pos_reference,
                'customer' => $inv->customer?->name,
                'sales_person' => $inv->salesPerson?->username ?: ($inv->salesPerson?->name ?: null),
                'trade_revenue_cents' => $tradeRevenue,
                'discount_cents' => $discount,
                'net_amount_cents' => $netAmount,
                'cash_cents' => $cash,
                'card_cents' => $card,
                'credit_cents' => $credit,
                'total_collection_cents' => $totalCollection,
            ];
        });
    }

    /**
     * @return array{cash_cents:int,card_cents:int,credit_cents:int}
     */
    private function paymentBreakdownCents(ArInvoice $invoice, int $netAmount): array
    {
        $paymentType = strtolower((string) ($invoice->payment_type ?? 'credit'));
        $cash = 0;
        $card = 0;
        $hasAllocations = false;

        foreach ($invoice->paymentAllocations as $allocation) {
            $amount = max(0, (int) ($allocation->amount_cents ?? 0));
            if ($amount <= 0) {
                continue;
            }

            $hasAllocations = true;
            $method = strtolower((string) ($allocation->payment?->method ?? ''));
            if ($method === 'cash') {
                $cash += $amount;
                continue;
            }

            // Non-cash settled methods are grouped under the report's card column.
            $card += $amount;
        }

        if ($paymentType === 'credit') {
            return [
                'cash_cents' => 0,
                'card_cents' => 0,
                'credit_cents' => $netAmount,
            ];
        }

        if (! $hasAllocations) {
            return [
                'cash_cents' => $paymentType === 'cash' ? $netAmount : 0,
                'card_cents' => in_array($paymentType, ['card', 'mixed'], true) ? $netAmount : 0,
                'credit_cents' => 0,
            ];
        }

        return [
            'cash_cents' => $cash,
            'card_cents' => $card,
            'credit_cents' => max(0, $netAmount - $cash - $card),
        ];
    }

    /**
     * @param  Collection<int, array<string, int|string|null>>  $rows
     * @return array<string, int>
     */
    private function buildTotals(Collection $rows): array
    {
        return [
            'trade_revenue_cents' => (int) $rows->sum('trade_revenue_cents'),
            'discount_cents' => (int) $rows->sum('discount_cents'),
            'net_amount_cents' => (int) $rows->sum('net_amount_cents'),
            'cash_cents' => (int) $rows->sum('cash_cents'),
            'card_cents' => (int) $rows->sum('card_cents'),
            'credit_cents' => (int) $rows->sum('credit_cents'),
            'total_collection_cents' => (int) $rows->sum('total_collection_cents'),
        ];
    }

    private function warehouseName(?int $branchId): string
    {
        if (! $branchId) {
            return 'All Branches';
        }

        return (string) (Branch::query()->whereKey($branchId)->value('name') ?: $branchId);
    }

    public function print(Request $request)
    {
        [$from, $to] = $this->resolvedDailyRange($request);
        $invoices = $this->query($request, $from, $to);
        $rows = $this->buildRows($invoices);
        $totals = $this->buildTotals($rows);
        $filters = array_merge(
            $request->only(['branch_id', 'customer_id']),
            ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()]
        );
        $generatedBy = (string) ($request->user()?->username ?: $request->user()?->name ?: '-');
        $warehouse = $this->warehouseName($request->integer('branch_id') ?: null);

        return view('reports.sales-entry-daily-print', [
            'invoices' => $invoices,
            'rows' => $rows,
            'totals' => $totals,
            'filters' => $filters,
            'generatedAt' => now(),
            'generatedBy' => $generatedBy,
            'warehouse' => $warehouse,
            'salesPerson' => '-',
            'startAt' => $from,
            'endAt' => $to,
            'formatCents' => fn ($c) => $this->formatCents($c),
        ]);
    }

    public function csv(Request $request): StreamedResponse
    {
        [$from, $to] = $this->resolvedDailyRange($request);
        $rows = $this->buildRows($this->query($request, $from, $to, 2000));
        $headers = [
            __('S.I'),
            __('Date'),
            __('Invoice Number'),
            __('POS Ref'),
            __('Customer'),
            __('Sales Person'),
            __('Total Trade Revenue'),
            __('Discount'),
            __('Net Amount'),
            __('Cash'),
            __('Card'),
            __('Credit'),
            __('Total Collection'),
        ];
        $data = $rows->map(fn ($row) => [
            $row['si'],
            $row['date'],
            $row['invoice_number'],
            $row['pos_ref'] ?? '',
            $row['customer'] ?? '',
            $row['sales_person'] ?? '',
            $this->formatCents((int) ($row['trade_revenue_cents'] ?? 0)),
            $this->formatCents((int) ($row['discount_cents'] ?? 0)),
            $this->formatCents((int) ($row['net_amount_cents'] ?? 0)),
            $this->formatCents((int) ($row['cash_cents'] ?? 0)),
            $this->formatCents((int) ($row['card_cents'] ?? 0)),
            $this->formatCents((int) ($row['credit_cents'] ?? 0)),
            $this->formatCents((int) ($row['total_collection_cents'] ?? 0)),
        ]);

        return CsvExport::stream($headers, $data, 'sales-entry-daily.csv');
    }

    public function pdf(Request $request)
    {
        [$from, $to] = $this->resolvedDailyRange($request);
        $invoices = $this->query($request, $from, $to);
        $rows = $this->buildRows($invoices);
        $totals = $this->buildTotals($rows);
        $filters = array_merge(
            $request->only(['branch_id', 'customer_id']),
            ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()]
        );
        $generatedBy = (string) ($request->user()?->username ?: $request->user()?->name ?: '-');
        $warehouse = $this->warehouseName($request->integer('branch_id') ?: null);

        return PdfExport::download('reports.sales-entry-daily-print', [
            'invoices' => $invoices,
            'rows' => $rows,
            'totals' => $totals,
            'filters' => $filters,
            'generatedAt' => now(),
            'generatedBy' => $generatedBy,
            'warehouse' => $warehouse,
            'salesPerson' => '-',
            'startAt' => $from,
            'endAt' => $to,
            'formatCents' => fn ($c) => $this->formatCents($c),
        ], 'sales-entry-daily.pdf');
    }
}
