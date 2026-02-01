<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\ArInvoice;
use App\Support\Money\MinorUnits;
use App\Support\Reports\CsvExport;
use App\Support\Reports\PdfExport;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerAgingSummaryReportController extends Controller
{
    private function formatCents(?int $cents): string
    {
        return MinorUnits::format((int) ($cents ?? 0));
    }

    /**
     * @return Collection<int, array{customer_id:int, customer_name:string, current:int, bucket_1_30:int, bucket_31_60:int, bucket_61_90:int, bucket_90_plus:int, total:int}>
     */
    private function query(Request $request): Collection
    {
        $asOf = $request->filled('date_to') ? now()->parse($request->date_to) : now();

        $invoices = ArInvoice::query()
            ->with(['customer'])
            ->where('type', 'invoice')
            ->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->where('balance_cents', '>', 0)
            ->when($request->filled('branch_id') && $request->integer('branch_id') > 0, fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('customer_id') && $request->integer('customer_id') > 0, fn ($q) => $q->where('customer_id', $request->integer('customer_id')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('issue_date', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('issue_date', '<=', $request->date_to))
            ->get();

        $bucketed = [];
        foreach ($invoices as $inv) {
            $customerId = (int) $inv->customer_id;
            $name = $inv->customer?->name ?? '—';
            $balance = (int) $inv->balance_cents;

            $key = $customerId;
            if (! isset($bucketed[$key])) {
                $bucketed[$key] = [
                    'customer_id' => $customerId,
                    'customer_name' => $name,
                    'current' => 0,
                    'bucket_1_30' => 0,
                    'bucket_31_60' => 0,
                    'bucket_61_90' => 0,
                    'bucket_90_plus' => 0,
                    'total' => 0,
                ];
            }

            $days = $inv->due_date ? $inv->due_date->diffInDays($asOf, false) : 0;
            if ($days <= 0) {
                $bucketed[$key]['current'] += $balance;
            } elseif ($days <= 30) {
                $bucketed[$key]['bucket_1_30'] += $balance;
            } elseif ($days <= 60) {
                $bucketed[$key]['bucket_31_60'] += $balance;
            } elseif ($days <= 90) {
                $bucketed[$key]['bucket_61_90'] += $balance;
            } else {
                $bucketed[$key]['bucket_90_plus'] += $balance;
            }

            $bucketed[$key]['total'] += $balance;
        }

        return collect(array_values($bucketed));
    }

    public function print(Request $request)
    {
        $rows = $this->query($request);
        $filters = $request->only(['branch_id', 'customer_id', 'date_from', 'date_to']);

        return view('reports.customer-aging-summary-print', [
            'rows' => $rows,
            'filters' => $filters,
            'generatedAt' => now(),
            'formatCents' => fn ($c) => $this->formatCents($c),
        ]);
    }

    public function csv(Request $request): StreamedResponse
    {
        $rows = $this->query($request);
        $headers = [__('Customer'), __('Current'), __('1-30'), __('31-60'), __('61-90'), __('90+'), __('Total')];
        $data = $rows->map(fn ($row) => [
            $row['customer_name'],
            $this->formatCents($row['current']),
            $this->formatCents($row['bucket_1_30']),
            $this->formatCents($row['bucket_31_60']),
            $this->formatCents($row['bucket_61_90']),
            $this->formatCents($row['bucket_90_plus']),
            $this->formatCents($row['total']),
        ]);

        return CsvExport::stream($headers, $data, 'customer-aging-summary.csv');
    }

    public function pdf(Request $request)
    {
        $rows = $this->query($request);
        $filters = $request->only(['branch_id', 'customer_id', 'date_from', 'date_to']);

        return PdfExport::download('reports.customer-aging-summary-print', [
            'rows' => $rows,
            'filters' => $filters,
            'generatedAt' => now(),
            'formatCents' => fn ($c) => $this->formatCents($c),
        ], 'customer-aging-summary.pdf');
    }
}
