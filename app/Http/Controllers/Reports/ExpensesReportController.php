<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Support\Reports\CsvExport;
use App\Support\Reports\PdfExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExpensesReportController extends Controller
{
    private function query(Request $request, int $limit = 500)
    {
        return Expense::query()
            ->with(['supplier', 'category'])
            ->withSum('payments as paid_sum', 'amount')
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($sub) => $sub->where('description', 'like', '%'.$request->search.'%')->orWhere('reference', 'like', '%'.$request->search.'%')))
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->integer('supplier_id')))
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->integer('category_id')))
            ->when($request->filled('payment_status') && $request->payment_status !== 'all', fn ($q) => $q->where('payment_status', $request->payment_status))
            ->when($request->filled('payment_method') && $request->payment_method !== 'all', fn ($q) => $q->where('payment_method', $request->payment_method))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('expense_date', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('expense_date', '<=', $request->date_to))
            ->orderByDesc('expense_date')
            ->limit($limit)
            ->get();
    }

    public function print(Request $request)
    {
        $expenses = $this->query($request);
        $filters = $request->only(['search', 'supplier_id', 'category_id', 'payment_status', 'payment_method', 'date_from', 'date_to']);

        return view('reports.expenses-print', ['expenses' => $expenses, 'filters' => $filters, 'generatedAt' => now()]);
    }

    public function csv(Request $request): StreamedResponse
    {
        $expenses = $this->query($request, 2000);
        $headers = [__('Date'), __('Reference'), __('Description'), __('Supplier'), __('Category'), __('Status'), __('Amount')];
        $rows = $expenses->map(fn ($e) => [
            $e->expense_date?->format('Y-m-d'),
            $e->reference ?? '',
            $e->description ?? '',
            $e->supplier?->name ?? '',
            $e->category?->name ?? '',
            $e->payment_status ?? '',
            number_format((float) $e->total_amount, 3, '.', ''),
        ]);

        return CsvExport::stream($headers, $rows, 'expenses-report.csv');
    }

    public function pdf(Request $request)
    {
        $expenses = $this->query($request);
        $filters = $request->only(['search', 'supplier_id', 'category_id', 'payment_status', 'payment_method', 'date_from', 'date_to']);

        return PdfExport::download('reports.expenses-print', ['expenses' => $expenses, 'filters' => $filters, 'generatedAt' => now()], 'expenses-report.pdf');
    }
}
