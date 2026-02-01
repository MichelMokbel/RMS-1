<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Support\Money\MinorUnits;
use App\Support\Reports\CsvExport;
use App\Support\Reports\PdfExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SessionBranchSalesReportController extends Controller
{
    private function formatCents(?int $cents): string
    {
        return MinorUnits::format((int) ($cents ?? 0));
    }

    private function query(Request $request, int $limit = 500)
    {
        return DB::table('pos_shifts as s')
            ->leftJoin('users as u', 'u.id', '=', 's.user_id')
            ->leftJoin('sales as sa', 'sa.pos_shift_id', '=', 's.id')
            ->leftJoin('ar_invoices as inv', function ($join) {
                $join->on('inv.source_sale_id', '=', 'sa.id')
                    ->where('inv.type', '=', 'invoice')
                    ->whereIn('inv.status', ['issued', 'partially_paid', 'paid']);
            })
            ->select([
                's.id',
                's.branch_id',
                's.user_id',
                'u.username as cashier_name',
                's.opened_at',
                's.closed_at',
                's.status',
                DB::raw('COUNT(DISTINCT inv.id) as invoice_count'),
                DB::raw('COALESCE(SUM(inv.total_cents), 0) as total_cents'),
            ])
            ->when($request->filled('branch_id') && $request->integer('branch_id') > 0, fn ($q) => $q->where('s.branch_id', $request->integer('branch_id')))
            ->when($request->filled('cashier_id') && $request->integer('cashier_id') > 0, fn ($q) => $q->where('s.user_id', $request->integer('cashier_id')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('s.opened_at', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('s.opened_at', '<=', $request->date_to))
            ->groupBy('s.id', 's.branch_id', 's.user_id', 'u.username', 's.opened_at', 's.closed_at', 's.status')
            ->orderByDesc('s.opened_at')
            ->limit($limit)
            ->get();
    }

    public function print(Request $request)
    {
        $rows = $this->query($request);
        $filters = $request->only(['branch_id', 'cashier_id', 'date_from', 'date_to']);

        return view('reports.session-branch-sales-print', [
            'rows' => $rows,
            'filters' => $filters,
            'generatedAt' => now(),
            'formatCents' => fn ($c) => $this->formatCents($c),
        ]);
    }

    public function csv(Request $request): StreamedResponse
    {
        $rows = $this->query($request, 2000);
        $headers = [__('Shift #'), __('Branch'), __('Cashier'), __('Opened'), __('Closed'), __('Invoices'), __('Total')];
        $data = $rows->map(fn ($row) => [
            $row->id,
            $row->branch_id,
            $row->cashier_name ?? '',
            optional($row->opened_at)->format('Y-m-d H:i'),
            optional($row->closed_at)->format('Y-m-d H:i'),
            (int) ($row->invoice_count ?? 0),
            $this->formatCents((int) ($row->total_cents ?? 0)),
        ]);

        return CsvExport::stream($headers, $data, 'session-branch-sales.csv');
    }

    public function pdf(Request $request)
    {
        $rows = $this->query($request);
        $filters = $request->only(['branch_id', 'cashier_id', 'date_from', 'date_to']);

        return PdfExport::download('reports.session-branch-sales-print', [
            'rows' => $rows,
            'filters' => $filters,
            'generatedAt' => now(),
            'formatCents' => fn ($c) => $this->formatCents($c),
        ], 'session-branch-sales.pdf');
    }
}
