<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ApReportRequest;
use App\Models\ApDailyJournalReport;
use App\Models\Branch;
use App\Services\Reports\ApReportService;
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
            array_splice($data['headers'], 1, 0, [__('Daily document number')]);
            $data['rows'] = array_map(function ($row) use ($numbers) {
                array_splice($row, 1, 0, [$numbers->get($row[1], '')]);

                return $row;
            }, $data['rows']);
            $data['totals'] = array_map(function ($row) {
                array_splice($row, 1, 0, ['']);

                return $row;
            }, $data['totals']);
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

        return view('reports.ap-report', $data + ['companies' => $this->reports->companies($request->user()), 'branches' => $branchQuery->get()]);
    }

    private function csvRow(array $row): array
    {
        return array_map(fn ($cell) => is_string($cell) && preg_match('/^[\s]*[=+@-]/u', $cell) ? "'".$cell : $cell, $row);
    }
}
