<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Accounting\AccountingContextService;
use App\Support\Money\MinorUnits;
use App\Support\Reports\PdfExport;
use App\Support\Reports\XlsxExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class CustomerPaymentsReportController extends Controller
{
    public function __construct(protected AccountingContextService $accountingContext)
    {
    }

    private function formatCents(?int $cents): string
    {
        return MinorUnits::format((int) ($cents ?? 0));
    }

    private function query(Request $request, int $limit = 500)
    {
        $companyId = $request->integer('company_id') ?: $this->accountingContext->defaultCompanyId();

        return Payment::query()
            ->where('source', 'ar')
            ->whereNull('voided_at')
            ->with(['customer'])
            ->withSum('allocations as allocated_sum', 'amount_cents')
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($request->filled('branch_id') && $request->integer('branch_id') > 0, fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('customer_id') && $request->integer('customer_id') > 0, fn ($q) => $q->where('customer_id', $request->integer('customer_id')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('received_at', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('received_at', '<=', $request->date_to))
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    private function filters(Request $request): array
    {
        return $request->only(['company_id', 'branch_id', 'customer_id', 'date_from', 'date_to']);
    }

    public function print(Request $request)
    {
        $payments = $this->query($request);

        return view('reports.customer-payments-print', [
            'payments' => $payments,
            'filters' => $this->filters($request),
            'generatedAt' => now(),
            'formatCents' => fn ($c) => $this->formatCents($c),
        ]);
    }

    public function xlsx(Request $request): BinaryFileResponse
    {
        $payments = $this->query($request, 2000);
        $headers = [__('Payment #'), __('Customer'), __('Date'), __('Method'), __('Reference'), __('Amount'), __('Allocated'), __('Unallocated')];
        $rows = $payments->map(function (Payment $pay): array {
            $allocated = (int) ($pay->allocated_sum ?? 0);
            $remaining = (int) $pay->amount_cents - $allocated;

            return [
                (string) $pay->id,
                $pay->customer?->name ?? '',
                $pay->received_at?->format('Y-m-d') ?? '',
                strtoupper((string) ($pay->method ?? '')),
                (string) ($pay->reference ?? ''),
                $this->formatCents($pay->amount_cents),
                $this->formatCents($allocated),
                $this->formatCents($remaining),
            ];
        });

        return XlsxExport::download($headers, $rows, 'customer-payments-report.xlsx', 'Customer Payments');
    }

    public function pdf(Request $request): Response
    {
        $payments = $this->query($request);

        return PdfExport::download('reports.customer-payments-print', [
            'payments' => $payments,
            'filters' => $this->filters($request),
            'generatedAt' => now(),
            'formatCents' => fn ($c) => $this->formatCents($c),
        ], 'customer-payments-report.pdf');
    }
}
