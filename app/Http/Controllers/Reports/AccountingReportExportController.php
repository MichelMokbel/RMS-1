<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Accounting\AccountingReportService;
use App\Support\Reports\CsvExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccountingReportExportController extends Controller
{
    public function csv(Request $request, string $report): StreamedResponse
    {
        $service = app(AccountingReportService::class);
        $companyId = $request->integer('company_id') ?: null;
        $dateTo = $request->input('date_to') ?: now()->toDateString();

        return match ($report) {
            'ap-aging' => $this->apAging($service, (int) $companyId, $request),
            'daily-general-ledger' => $this->dailyGeneralLedger($service, (int) $companyId, $request),
            'trial-balance' => $this->trialBalance($service, (int) $companyId, $dateTo),
            'inventory-valuation' => $this->inventoryValuation($service, (int) $companyId, $dateTo),
            'purchase-accruals' => $this->purchaseAccruals($service, (int) $companyId, $request),
            'multi-company-summary' => $this->multiCompanySummary($service, $dateTo),
            'ar-credit-exceptions' => $this->arCreditExceptions($service, (int) $companyId, $dateTo),
            'vendor-ledger' => $this->vendorLedger($service, (int) $companyId, $request),
            'expense-analysis' => $this->expenseAnalysis($service, (int) $companyId, $request),
            'job-profitability' => $this->jobProfitability($service, (int) $companyId, $dateTo),
            default => $this->genericSummary($service, $report, (int) $companyId, $dateTo),
        };
    }

    private function apAging(AccountingReportService $service, int $companyId, Request $request): StreamedResponse
    {
        $report = $service->apAging($companyId, [
            'supplier_id' => $request->integer('supplier_id') ?: null,
            'branch_id' => $request->integer('branch_id') ?: null,
            'department_id' => $request->integer('department_id') ?: null,
            'job_id' => $request->integer('job_id') ?: null,
            'date_to' => $request->input('date_to'),
        ]);

        return CsvExport::stream(
            ['Supplier', 'Documents', 'Current', '1-30', '31-60', '61-90', 'Over 90', 'Total'],
            collect($report['rows'])->map(fn ($row) => [
                $row['supplier_name'],
                $row['document_count'],
                number_format((float) $row['current'], 2, '.', ''),
                number_format((float) $row['1_30'], 2, '.', ''),
                number_format((float) $row['31_60'], 2, '.', ''),
                number_format((float) $row['61_90'], 2, '.', ''),
                number_format((float) $row['over_90'], 2, '.', ''),
                number_format((float) $row['total'], 2, '.', ''),
            ]),
            'ap-aging.csv'
        );
    }

    private function dailyGeneralLedger(AccountingReportService $service, int $companyId, Request $request): StreamedResponse
    {
        $report = $service->dailyGeneralLedger(
            $companyId,
            $request->input('date_from'),
            $request->input('date_to'),
            [
                'branch_id' => $request->integer('branch_id') ?: null,
                'department_id' => $request->integer('department_id') ?: null,
                'job_id' => $request->integer('job_id') ?: null,
            ]
        );

        $rows = collect($report['groups'] ?? [])
            ->flatMap(function ($day) {
                return collect($day['accounts'] ?? [])->flatMap(function ($account) use ($day) {
                    return collect($account['sources'] ?? [])->map(function ($source) use ($day, $account) {
                        return [
                            'entry_date' => $day['entry_date'],
                            'account_code' => $account['account_code'],
                            'account_name' => $account['account_name'],
                            'source_reference' => $source['source_reference'],
                            'event' => $source['event'],
                            'description' => $source['description'],
                            'debit_total' => $source['debit_total'],
                            'credit_total' => $source['credit_total'],
                            'dimensions' => collect($source['lines'] ?? [])
                                ->flatMap(fn ($line) => array_filter([
                                    $line['branch_name'] ? 'Branch: '.$line['branch_name'] : null,
                                    $line['department_name'] ? 'Department: '.$line['department_name'] : null,
                                    $line['job_name'] ? 'Job: '.$line['job_name'] : null,
                                ]))
                                ->unique()
                                ->implode(' | '),
                        ];
                    });
                });
            });

        return CsvExport::stream(
            ['Date', 'Account Code', 'Account Name', 'Source', 'Event', 'Description', 'Dimensions', 'Debit', 'Credit'],
            $rows->map(fn ($row) => [
                $row['entry_date'],
                $row['account_code'],
                $row['account_name'],
                $row['source_reference'],
                $row['event'],
                $row['description'],
                $row['dimensions'],
                number_format((float) $row['debit_total'], 2, '.', ''),
                number_format((float) $row['credit_total'], 2, '.', ''),
            ]),
            'daily-general-ledger.csv'
        );
    }

    private function trialBalance(AccountingReportService $service, int $companyId, string $dateTo): StreamedResponse
    {
        $report = $service->trialBalance($companyId, $dateTo);

        return CsvExport::stream(
            ['Account Code', 'Account Name', 'Debit Balance', 'Credit Balance'],
            collect($report['entries'])->map(fn ($entry) => [
                $entry['code'],
                $entry['name'],
                number_format((float) $entry['debit_balance'], 2, '.', ''),
                number_format((float) $entry['credit_balance'], 2, '.', ''),
            ]),
            'trial-balance.csv'
        );
    }

    private function inventoryValuation(AccountingReportService $service, int $companyId, string $dateTo): StreamedResponse
    {
        $report = $service->inventoryValuation($companyId, $dateTo);

        return CsvExport::stream(
            ['Item', 'Branch', 'Quantity', 'Unit Cost', 'Valuation'],
            collect($report['rows'])->map(fn ($row) => [
                $row['item_name'],
                $row['branch_name'],
                number_format((float) $row['quantity'], 3, '.', ''),
                number_format((float) $row['unit_cost'], 4, '.', ''),
                number_format((float) $row['valuation_amount'], 2, '.', ''),
            ]),
            'inventory-valuation.csv'
        );
    }

    private function purchaseAccruals(AccountingReportService $service, int $companyId, Request $request): StreamedResponse
    {
        $report = $service->purchaseAccruals($companyId, [
            'supplier_id' => $request->integer('supplier_id') ?: null,
            'date_to' => $request->input('date_to'),
        ]);

        return CsvExport::stream(
            ['PO', 'Supplier', 'Item', 'Received Qty', 'Matched Qty', 'Remaining Qty', 'Accrual Value'],
            collect($report['rows'])->map(fn ($row) => [
                $row['po_number'],
                $row['supplier_name'],
                $row['item_name'],
                number_format((float) $row['received_quantity'], 3, '.', ''),
                number_format((float) $row['matched_quantity'], 3, '.', ''),
                number_format((float) $row['remaining_quantity'], 3, '.', ''),
                number_format((float) $row['accrual_value'], 2, '.', ''),
            ]),
            'purchase-accruals.csv'
        );
    }

    private function multiCompanySummary(AccountingReportService $service, string $dateTo): StreamedResponse
    {
        $report = $service->multiCompanySummary($dateTo);

        return CsvExport::stream(
            ['Company', 'Revenue', 'Expenses', 'Net Income', 'Assets', 'Liabilities', 'Equity'],
            collect($report['rows'])->map(fn ($row) => [
                $row['company_name'],
                number_format((float) $row['revenue_total'], 2, '.', ''),
                number_format((float) $row['expense_total'], 2, '.', ''),
                number_format((float) $row['net_income'], 2, '.', ''),
                number_format((float) $row['asset_total'], 2, '.', ''),
                number_format((float) $row['liability_total'], 2, '.', ''),
                number_format((float) $row['equity_total'], 2, '.', ''),
            ]),
            'multi-company-summary.csv'
        );
    }

    private function arCreditExceptions(AccountingReportService $service, int $companyId, string $dateTo): StreamedResponse
    {
        $report = $service->arCreditBalanceExceptions($companyId, $dateTo);

        $rows = collect($report['rows'])
            ->flatMap(function ($sectionRows, $section) {
                return collect($sectionRows)->map(fn ($row) => [
                    'section' => $section,
                    'customer_name' => $row['customer_name'] ?? '',
                    'reference' => $row['reference'] ?? '',
                    'date' => $row['date'] ?? '',
                    'notes' => $row['notes'] ?? '',
                    'amount' => $row['amount'] ?? 0,
                ]);
            });

        return CsvExport::stream(
            ['Section', 'Customer', 'Reference', 'Date', 'Notes', 'Amount'],
            $rows->map(fn ($row) => [
                $row['section'],
                $row['customer_name'],
                $row['reference'],
                $row['date'],
                $row['notes'],
                number_format((float) $row['amount'], 2, '.', ''),
            ]),
            'ar-credit-exceptions.csv'
        );
    }

    private function vendorLedger(AccountingReportService $service, int $companyId, Request $request): StreamedResponse
    {
        $report = $service->vendorLedger(
            $companyId,
            $request->integer('supplier_id') ?: null,
            $request->input('date_from'),
            $request->input('date_to'),
            [
                'branch_id' => $request->integer('branch_id') ?: null,
                'department_id' => $request->integer('department_id') ?: null,
                'job_id' => $request->integer('job_id') ?: null,
            ]
        );

        return CsvExport::stream(
            ['Date', 'Supplier', 'Reference', 'Description', 'Debit', 'Credit'],
            collect($report['rows'])->map(fn ($row) => [
                $row['date'],
                $row['supplier_name'],
                $row['reference'],
                $row['description'],
                number_format((float) ($row['debit'] ?? 0), 2, '.', ''),
                number_format((float) ($row['credit'] ?? 0), 2, '.', ''),
            ]),
            'vendor-ledger.csv'
        );
    }

    private function expenseAnalysis(AccountingReportService $service, int $companyId, Request $request): StreamedResponse
    {
        $report = $service->expenseAnalysis($companyId, $request->only([
            'branch_id',
            'department_id',
            'job_id',
            'supplier_id',
            'date_from',
            'date_to',
        ]));

        return CsvExport::stream(
            ['Supplier', 'Branch', 'Department', 'Job', 'Documents', 'Amount'],
            collect($report['rows'])->map(fn ($row) => [
                $row['supplier_name'] ?? $row['supplier'] ?? '',
                $row['branch_name'],
                $row['department_name'],
                $row['job_name'],
                $row['count'],
                number_format((float) $row['amount'], 2, '.', ''),
            ]),
            'expense-analysis.csv'
        );
    }

    private function jobProfitability(AccountingReportService $service, int $companyId, string $dateTo): StreamedResponse
    {
        $rows = $service->summary($companyId, $dateTo)['job_profitability'];

        return CsvExport::stream(
            ['Job Code', 'Job Name', 'Status', 'Budget', 'Actual Cost', 'Actual Revenue', 'Margin'],
            collect($rows)->map(fn ($row) => [
                $row['job_code'],
                $row['job_name'],
                $row['status'],
                number_format((float) ($row['budget_total'] ?? 0), 2, '.', ''),
                number_format((float) ($row['actual_cost'] ?? 0), 2, '.', ''),
                number_format((float) ($row['actual_revenue'] ?? 0), 2, '.', ''),
                number_format((float) ($row['actual_margin'] ?? 0), 2, '.', ''),
            ]),
            'job-profitability.csv'
        );
    }

    private function genericSummary(AccountingReportService $service, string $report, int $companyId, string $dateTo): StreamedResponse
    {
        $summary = match ($report) {
            'profit-loss' => $service->profitAndLoss($companyId, $dateTo)['rows'],
            'balance-sheet' => $service->balanceSheet($companyId, $dateTo)['rows'],
            'cash-flow' => [['type' => 'net_cash_flow', 'amount' => $service->cashFlow($companyId, $dateTo)['net_cash_flow']]],
            'bank-reconciliation' => $service->bankReconciliationSummary($companyId)['runs'],
            'budget-variance' => ($service->summary($companyId, $dateTo)['budget_variance']['rows'] ?? []),
            default => [],
        };

        return CsvExport::stream(
            ['Data'],
            collect($summary)->map(fn ($row) => [json_encode($row)]),
            $report.'.csv'
        );
    }
}
