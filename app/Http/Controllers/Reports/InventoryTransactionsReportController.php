<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Reports\InventoryTransactionsReportQueryService;
use App\Support\Reports\CsvExport;
use App\Support\Reports\PdfExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventoryTransactionsReportController extends Controller
{
    private function filters(Request $request): array
    {
        return [
            'search' => $request->get('search', ''),
            'branch_id' => $request->integer('branch_id') ?: null,
            'item_id' => $request->integer('item_id') ?: null,
            'supplier_id' => $request->integer('supplier_id') ?: null,
            'category_id' => $request->integer('category_id') ?: null,
            'transaction_type' => $request->get('transaction_type', 'all'),
            'reference_type' => $request->get('reference_type', 'all'),
            'date_from' => $request->get('date_from'),
            'date_to' => $request->get('date_to'),
        ];
    }

    private function query(Request $request, InventoryTransactionsReportQueryService $service, int $limit = 2000)
    {
        return $service->query($this->filters($request))->limit($limit)->get();
    }

    public function print(Request $request, InventoryTransactionsReportQueryService $service)
    {
        $transactions = $this->query($request, $service);

        return view('reports.inventory-transactions-print', [
            'transactions' => $transactions,
            'filters' => $this->filters($request),
            'generatedAt' => now(),
        ]);
    }

    public function csv(Request $request, InventoryTransactionsReportQueryService $service): StreamedResponse
    {
        $transactions = $this->query($request, $service, 5000);
        $headers = [__('Date'), __('Code'), __('Item'), __('Category'), __('Type'), __('Reference'), __('Qty'), __('Unit Cost'), __('Total Cost'), __('User'), __('Notes')];
        $rows = $transactions->map(fn ($transaction) => [
            $transaction->transaction_date?->format('Y-m-d H:i'),
            $transaction->item?->item_code ?? '',
            $transaction->item?->name ?? '',
            $transaction->item?->categoryLabel() ?? '',
            $transaction->transaction_type,
            trim(($transaction->reference_type ?? '').' '.($transaction->reference_id ?? '')),
            number_format((float) $transaction->delta(), 3, '.', ''),
            number_format((float) ($transaction->unit_cost ?? 0), 4, '.', ''),
            number_format((float) ($transaction->total_cost ?? 0), 4, '.', ''),
            $transaction->user?->username ?? $transaction->user?->email ?? '',
            $transaction->notes ?? '',
        ]);

        return CsvExport::stream($headers, $rows, 'inventory-transactions-report.csv');
    }

    public function pdf(Request $request, InventoryTransactionsReportQueryService $service)
    {
        $transactions = $this->query($request, $service);

        return PdfExport::download('reports.inventory-transactions-print', [
            'transactions' => $transactions,
            'filters' => $this->filters($request),
            'generatedAt' => now(),
        ], 'inventory-transactions-report.pdf');
    }
}
