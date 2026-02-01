<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\ApInvoice;
use App\Support\Reports\CsvExport;
use App\Support\Reports\PdfExport;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupplierAgingSummaryReportController extends Controller
{
    private function formatMoney(?float $amount): string
    {
        return number_format((float) ($amount ?? 0), 2, '.', '');
    }

    /**
     * @return Collection<int, array{supplier_id:int, supplier_name:string, current:float, bucket_1_30:float, bucket_31_60:float, bucket_61_90:float, bucket_90_plus:float, total:float}>
     */
    private function query(Request $request): Collection
    {
        $asOf = $request->filled('date_to') ? now()->parse($request->date_to) : now();

        $invoices = ApInvoice::query()
            ->with(['supplier'])
            ->whereIn('status', ['posted', 'partially_paid', 'paid'])
            ->when($request->filled('supplier_id') && $request->integer('supplier_id') > 0, fn ($q) => $q->where('supplier_id', $request->integer('supplier_id')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('invoice_date', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('invoice_date', '<=', $request->date_to))
            ->get()
            ->filter(fn ($inv) => $inv->outstandingAmount() > 0);

        $bucketed = [];
        foreach ($invoices as $inv) {
            $supplierId = (int) $inv->supplier_id;
            $name = $inv->supplier?->name ?? '—';
            $balance = (float) $inv->outstandingAmount();

            if (! isset($bucketed[$supplierId])) {
                $bucketed[$supplierId] = [
                    'supplier_id' => $supplierId,
                    'supplier_name' => $name,
                    'current' => 0.0,
                    'bucket_1_30' => 0.0,
                    'bucket_31_60' => 0.0,
                    'bucket_61_90' => 0.0,
                    'bucket_90_plus' => 0.0,
                    'total' => 0.0,
                ];
            }

            $days = $inv->due_date ? $inv->due_date->diffInDays($asOf, false) : 0;
            if ($days <= 0) {
                $bucketed[$supplierId]['current'] += $balance;
            } elseif ($days <= 30) {
                $bucketed[$supplierId]['bucket_1_30'] += $balance;
            } elseif ($days <= 60) {
                $bucketed[$supplierId]['bucket_31_60'] += $balance;
            } elseif ($days <= 90) {
                $bucketed[$supplierId]['bucket_61_90'] += $balance;
            } else {
                $bucketed[$supplierId]['bucket_90_plus'] += $balance;
            }

            $bucketed[$supplierId]['total'] += $balance;
        }

        return collect(array_values($bucketed));
    }

    public function print(Request $request)
    {
        $rows = $this->query($request);
        $filters = $request->only(['supplier_id', 'date_from', 'date_to']);

        return view('reports.supplier-aging-summary-print', [
            'rows' => $rows,
            'filters' => $filters,
            'generatedAt' => now(),
            'formatMoney' => fn ($a) => $this->formatMoney($a),
        ]);
    }

    public function csv(Request $request): StreamedResponse
    {
        $rows = $this->query($request);
        $headers = [__('Supplier'), __('Current'), __('1-30'), __('31-60'), __('61-90'), __('90+'), __('Total')];
        $data = $rows->map(fn ($row) => [
            $row['supplier_name'],
            $this->formatMoney($row['current']),
            $this->formatMoney($row['bucket_1_30']),
            $this->formatMoney($row['bucket_31_60']),
            $this->formatMoney($row['bucket_61_90']),
            $this->formatMoney($row['bucket_90_plus']),
            $this->formatMoney($row['total']),
        ]);

        return CsvExport::stream($headers, $data, 'supplier-aging-summary.csv');
    }

    public function pdf(Request $request)
    {
        $rows = $this->query($request);
        $filters = $request->only(['supplier_id', 'date_from', 'date_to']);

        return PdfExport::download('reports.supplier-aging-summary-print', [
            'rows' => $rows,
            'filters' => $filters,
            'generatedAt' => now(),
            'formatMoney' => fn ($a) => $this->formatMoney($a),
        ], 'supplier-aging-summary.pdf');
    }
}
