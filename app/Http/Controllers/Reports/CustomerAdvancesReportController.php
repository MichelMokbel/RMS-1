<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Support\Money\MinorUnits;
use App\Support\Reports\CsvExport;
use App\Support\Reports\PdfExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerAdvancesReportController extends Controller
{
    private function formatCents(?int $cents): string
    {
        return MinorUnits::format((int) ($cents ?? 0));
    }

    private function query(Request $request, int $limit = 500)
    {
        return Payment::query()
            ->where('source', 'ar')
            ->whereNull('voided_at')
            ->with(['customer'])
            ->withSum('allocations as allocated_sum', 'amount_cents')
            ->when($request->filled('branch_id') && $request->integer('branch_id') > 0, fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('customer_id') && $request->integer('customer_id') > 0, fn ($q) => $q->where('customer_id', $request->integer('customer_id')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('received_at', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('received_at', '<=', $request->date_to))
            ->whereRaw('amount_cents > (SELECT COALESCE(SUM(amount_cents), 0) FROM payment_allocations WHERE payment_allocations.payment_id = payments.id AND payment_allocations.voided_at IS NULL)')
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function print(Request $request)
    {
        $payments = $this->query($request);
        $filters = $request->only(['branch_id', 'customer_id', 'date_from', 'date_to']);

        return view('reports.customer-advances-print', [
            'payments' => $payments,
            'filters' => $filters,
            'generatedAt' => now(),
            'formatCents' => fn ($c) => $this->formatCents($c),
        ]);
    }

    public function csv(Request $request): StreamedResponse
    {
        $payments = $this->query($request, 2000);
        $headers = [__('Payment #'), __('Customer'), __('Date'), __('Method'), __('Amount'), __('Allocated'), __('Unallocated')];
        $rows = $payments->map(function ($pay) {
            $allocated = (int) ($pay->allocated_sum ?? 0);
            $remaining = (int) $pay->amount_cents - $allocated;
            return [
                $pay->id,
                $pay->customer?->name ?? '',
                $pay->received_at?->format('Y-m-d'),
                $pay->method ?? '',
                $this->formatCents($pay->amount_cents),
                $this->formatCents($allocated),
                $this->formatCents($remaining),
            ];
        });

        return CsvExport::stream($headers, $rows, 'customer-advances-report.csv');
    }

    public function pdf(Request $request)
    {
        $payments = $this->query($request);
        $filters = $request->only(['branch_id', 'customer_id', 'date_from', 'date_to']);

        return PdfExport::download('reports.customer-advances-print', [
            'payments' => $payments,
            'filters' => $filters,
            'generatedAt' => now(),
            'formatCents' => fn ($c) => $this->formatCents($c),
        ], 'customer-advances-report.pdf');
    }
}
