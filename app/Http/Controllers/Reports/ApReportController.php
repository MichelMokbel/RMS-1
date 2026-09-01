<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ApReportRequest;
use App\Http\Requests\Reports\SendApJournalRangeRequest;
use App\Models\ApDailyJournalReport;
use App\Models\Branch;
use App\Models\FinanceSetting;
use App\Services\Reports\ApReportService;
use App\Services\Reports\DailyApJournalService;
use App\Services\Security\BranchAccessService;
use App\Support\Reports\CsvExport;
use App\Support\Reports\PdfExport;
use Illuminate\Pagination\LengthAwarePaginator;

class ApReportController extends Controller
{
    public function __construct(private readonly ApReportService $reports, private readonly BranchAccessService $branches) {}

    public function show(ApReportRequest $request, string $report, string $format = 'html')
    {
        $filters = $request->validated();
        $company = $this->reports->company($request->user(), $request->integer('company_id') ?: null);
        $filters['company_id'] = $company->id;
        $data = $report === 'ap-journal'
            ? $this->reports->journal($company->id, $filters, $request->user())
            : $this->reports->expensesByCategory($company->id, $filters, $request->user());
        $data += ['company' => $company->name, 'filters' => $filters, 'generatedAt' => now(), 'reportKey' => $report];

        if ($report === 'ap-journal') {
            // Only read identifiers here; opening/exporting a report never allocates a number or exposes a company-wide snapshot.
            $numbers = ApDailyJournalReport::query()->where('company_id', $company->id)
                ->whereBetween('report_date', [$filters['date_from'], $filters['date_to']])->pluck('document_number', 'report_date');
            $data['documentNumber'] = $filters['date_from'] === $filters['date_to'] ? $numbers->get($filters['date_from']) : null;
            if ($filters['date_from'] !== $filters['date_to']) {
                array_splice($data['headers'], 1, 0, [__('Daily document number')]);
                $data['rows'] = array_map(function ($row) use ($numbers) {
                    array_splice($row, 1, 0, [$numbers->get(substr($row[0], 0, 10), '')]);

                    return $row;
                }, $data['rows']);
                $data['totals'] = array_map(function ($row) {
                    array_splice($row, 1, 0, ['']);

                    return $row;
                }, $data['totals']);
            }
        }
        $filename = $data['documentNumber'] ?? $report;

        if ($format === 'csv') {
            return CsvExport::stream($data['headers'], array_map($this->csvRow(...), [...$data['rows'], ...$data['totals']]), $filename.'.csv');
        }
        if ($format === 'pdf') {
            return PdfExport::download('reports.ap-report-print', $data, $filename.'.pdf', 'a4', 'landscape');
        }
        if ($format === 'print') {
            return view('reports.ap-report-print', $data);
        }

        $branchQuery = Branch::query()->where('company_id', $company->id)->orderBy('name');
        $this->branches->applyBranchScope($branchQuery, $request->user(), 'id');
        $page = max(1, $request->integer('page', 1));
        $data['paginator'] = new LengthAwarePaginator(array_slice($data['rows'], ($page - 1) * 50, 50), count($data['rows']), 50, $page,
            ['path' => $request->url(), 'query' => $filters]);

        $data['apRangeEmailSettings'] = $report === 'ap-journal' && $request->user()->isAdmin()
            ? FinanceSetting::query()->find(1)
            : null;

        return view('reports.ap-report', $data + ['companies' => $this->reports->companies($request->user()), 'branches' => $branchQuery->get()]);
    }

    public function sendRange(SendApJournalRangeRequest $request, DailyApJournalService $service)
    {
        $filters = $request->validated();

        try {
            $result = $service->sendRange(
                companyId: (int) $filters['company_id'],
                dateFrom: $filters['date_from'],
                dateTo: $filters['date_to'],
                actorId: (int) $request->user()->id,
                retryFailed: (bool) ($filters['retry_failed'] ?? false),
            );
        } catch (\RuntimeException $exception) {
            return redirect()->route('reports.ap-journal', $this->rangeFilters($filters))
                ->with('error', $exception->getMessage())
                ->with('ap_range_retry_available', true);
        }

        $message = match ($result['status']) {
            'sent' => trans_choice(':count daily AP report was generated and emailed as one PDF to :recipient.|:count daily AP reports were generated and emailed as one PDF to :recipient.', $result['count'], [
                'count' => $result['count'],
                'recipient' => $result['recipient'],
            ]),
            'already-sent' => __('These daily report revisions were already emailed to :recipient.', ['recipient' => $result['recipient']]),
            'in-progress' => __('An email containing one or more selected daily reports is already being sent.'),
            'failed' => __('A previous delivery failed. Check email history and the mail provider, then use Retry failed email.'),
            default => __('The AP journal range could not be sent.'),
        };

        return redirect()->route('reports.ap-journal', $this->rangeFilters($filters))
            ->with($result['status'] === 'failed' ? 'error' : 'status', $message)
            ->with('ap_range_retry_available', $result['status'] === 'failed');
    }

    private function csvRow(array $row): array
    {
        return array_map(fn ($cell) => is_string($cell) && preg_match('/^[\s]*[=+@-]/u', $cell) ? "'".$cell : $cell, $row);
    }

    private function rangeFilters(array $filters): array
    {
        return [
            'company_id' => $filters['company_id'],
            'date_from' => $filters['date_from'],
            'date_to' => $filters['date_to'],
        ];
    }
}
