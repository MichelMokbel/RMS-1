<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Support\Money\MinorUnits;
use App\Support\Reports\CsvExport;
use App\Support\Reports\PdfExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CategorySalesSummaryReportController extends Controller
{
    private function formatCents(?int $cents): string
    {
        return MinorUnits::format((int) ($cents ?? 0));
    }

    private function query(Request $request, int $limit = 500)
    {
        return DB::table('ar_invoice_items as items')
            ->join('ar_invoices as inv', 'inv.id', '=', 'items.invoice_id')
            ->leftJoin('menu_items as mi', function ($join) {
                $join->on('mi.id', '=', 'items.sellable_id')
                    ->where('items.sellable_type', '=', MenuItem::class);
            })
            ->leftJoin('categories as cat', 'cat.id', '=', 'mi.category_id')
            ->select([
                'cat.id as category_id',
                'cat.name as category_name',
                DB::raw('SUM(items.line_total_cents) as total_cents'),
                DB::raw('SUM(items.qty) as qty_total'),
                DB::raw('COUNT(items.id) as line_count'),
            ])
            ->where('inv.type', 'invoice')
            ->whereIn('inv.status', ['issued', 'partially_paid', 'paid'])
            ->when($request->filled('branch_id') && $request->integer('branch_id') > 0, fn ($q) => $q->where('inv.branch_id', $request->integer('branch_id')))
            ->when($request->filled('category_id') && $request->integer('category_id') > 0, fn ($q) => $q->where('cat.id', $request->integer('category_id')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('inv.issue_date', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('inv.issue_date', '<=', $request->date_to))
            ->groupBy('cat.id', 'cat.name')
            ->orderByDesc('total_cents')
            ->limit($limit)
            ->get();
    }

    public function print(Request $request)
    {
        $rows = $this->query($request, 2000);
        $filters = $request->only(['branch_id', 'category_id', 'date_from', 'date_to']);

        return view('reports.category-sales-summary-print', [
            'rows' => $rows,
            'filters' => $filters,
            'generatedAt' => now(),
            'formatCents' => fn ($c) => $this->formatCents($c),
        ]);
    }

    public function csv(Request $request): StreamedResponse
    {
        $rows = $this->query($request, 2000);
        $headers = [__('Category'), __('Qty'), __('Lines'), __('Total')];
        $data = $rows->map(fn ($row) => [
            $row->category_name ?? __('Uncategorized'),
            (string) ($row->qty_total ?? 0),
            (int) ($row->line_count ?? 0),
            $this->formatCents((int) ($row->total_cents ?? 0)),
        ]);

        return CsvExport::stream($headers, $data, 'category-sales-summary.csv');
    }

    public function pdf(Request $request)
    {
        $rows = $this->query($request, 2000);
        $filters = $request->only(['branch_id', 'category_id', 'date_from', 'date_to']);

        return PdfExport::download('reports.category-sales-summary-print', [
            'rows' => $rows,
            'filters' => $filters,
            'generatedAt' => now(),
            'formatCents' => fn ($c) => $this->formatCents($c),
        ], 'category-sales-summary.pdf');
    }
}
