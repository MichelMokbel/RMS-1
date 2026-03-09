<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\ArInvoice;
use App\Models\Branch;
use App\Support\Reports\CsvExport;
use App\Support\Reports\PdfExport;
use App\Support\Money\MinorUnits;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalesReportController extends Controller
{
    private function formatCents(?int $cents): string
    {
        return MinorUnits::format((int) ($cents ?? 0));
    }

    private function formatInvoiceDateTime(ArInvoice $invoice): ?string
    {
        if (! $invoice->issue_date) {
            return null;
        }

        $dateTime = $invoice->issue_date->copy();
        if ($invoice->created_at) {
            $dateTime->setTimeFrom($invoice->created_at);
        }

        return $dateTime->format('Y-m-d H:i:s');
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

    private function query(Request $request, int $limit = 500)
    {
        [$from, $to] = $this->resolvedRange($request);

        return ArInvoice::query()
            ->with(['customer:id,name', 'paymentAllocations.payment'])
            ->where('type', 'invoice')
            ->whereIn('status', ['issued', 'partially_paid', 'paid', 'voided'])
            ->when($request->filled('branch_id') && $request->integer('branch_id') > 0, fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('status') && $request->status !== 'all', fn ($q) => $q->where('status', $request->status))
            ->whereDate('issue_date', '>=', $from->toDateString())
            ->whereDate('issue_date', '<=', $to->toDateString())
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    private function branchNames($sales): array
    {
        return Branch::query()
            ->whereIn('id', $sales->pluck('branch_id')->filter()->unique()->values())
            ->pluck('name', 'id')
            ->all();
    }

    private function paymentTypeLabel(ArInvoice $invoice): string
    {
        $methods = collect($invoice->paymentAllocations)
            ->map(fn ($allocation) => strtolower((string) ($allocation->payment?->method ?? '')))
            ->filter()
            ->unique()
            ->values();

        if ($methods->count() > 1) {
            return 'Mixed';
        }

        if ($methods->count() === 1) {
            return $this->formatPaymentMethod((string) $methods->first());
        }

        return $this->formatPaymentMethod((string) ($invoice->payment_type ?: 'credit'));
    }

    private function formatPaymentMethod(string $method): string
    {
        $normalized = strtolower(trim($method));
        if ($normalized === '') {
            return 'Credit';
        }

        return match ($normalized) {
            'cash' => 'Cash',
            'card' => 'Card',
            'credit' => 'Credit',
            'bank_transfer', 'bank' => 'Bank Transfer',
            'cheque' => 'Cheque',
            'voucher' => 'Voucher',
            default => ucwords(str_replace(['_', '-'], ' ', $normalized)),
        };
    }

    public function print(Request $request)
    {
        [$from, $to] = $this->resolvedRange($request);
        $sales = $this->query($request);
        $branchNames = $this->branchNames($sales);
        $filters = array_merge(
            $request->only(['branch_id', 'status']),
            ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()]
        );

        return view('reports.sales-print', [
            'sales' => $sales,
            'branchNames' => $branchNames,
            'filters' => $filters,
            'generatedAt' => now(),
            'formatCents' => fn ($c) => $this->formatCents($c),
            'paymentTypeLabel' => fn (ArInvoice $invoice) => $this->paymentTypeLabel($invoice),
        ]);
    }

    public function csv(Request $request): StreamedResponse
    {
        $sales = $this->query($request, 2000);
        $branchNames = $this->branchNames($sales);
        $headers = [__('S.I'), __('Date & Time'), __('Branch'), __('Invoice #'), __('POS REF'), __('Customer'), __('Status'), __('Payment Type'), __('Total')];
        $rows = $sales->values()->map(fn ($s, $index) => [
            $index + 1,
            $this->formatInvoiceDateTime($s),
            (string) ($branchNames[(int) $s->branch_id] ?? 'Branch '.$s->branch_id),
            $s->invoice_number ?: ('#'.$s->id),
            $s->pos_reference ?? '',
            $s->customer?->name ?? '',
            $s->status,
            $this->paymentTypeLabel($s),
            $this->formatCents($s->total_cents),
        ]);

        return CsvExport::stream($headers, $rows, 'sales-report.csv');
    }

    public function pdf(Request $request)
    {
        [$from, $to] = $this->resolvedRange($request);
        $sales = $this->query($request);
        $branchNames = $this->branchNames($sales);
        $filters = array_merge(
            $request->only(['branch_id', 'status']),
            ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()]
        );

        return PdfExport::download('reports.sales-print', [
            'sales' => $sales,
            'branchNames' => $branchNames,
            'filters' => $filters,
            'generatedAt' => now(),
            'formatCents' => fn ($c) => $this->formatCents($c),
            'paymentTypeLabel' => fn (ArInvoice $invoice) => $this->paymentTypeLabel($invoice),
        ], 'sales-report.pdf');
    }
}
