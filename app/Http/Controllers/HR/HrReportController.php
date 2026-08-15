<?php

namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Models\AccountingCompany;
use App\Models\HrEmployee;
use App\Models\HrLeaveLedgerEntry;
use App\Models\HrPayrollResult;
use App\Models\HrPayrollRun;
use App\Services\HR\HrAccessService;
use App\Services\HR\HrAuditLogService;
use App\Support\Reports\XlsxExport;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class HrReportController extends Controller
{
    public function print(string $report, Request $request, HrAccessService $access, HrAuditLogService $audit): View
    {
        $data = $request->validate(['company_id' => ['required', 'integer', 'exists:accounting_companies,id'], 'as_of' => ['required', 'date']]);
        $permission = $report === 'payroll-register' ? 'hr.payroll.export' : 'hr.reports.export';
        $access->assertCompany($request->user(), (int) $data['company_id'], $permission);
        $company = AccountingCompany::query()->findOrFail($data['company_id']);
        $rows = match ($report) {
            'directory' => $this->directory((int) $company->id, $data['as_of'], $request),
            'leave-balances' => $this->leaveBalances((int) $company->id, $data['as_of'], $request),
            'payroll-register' => $this->payrollRegister((int) $company->id, $data['as_of'], $request),
            default => abort(404),
        };
        $audit->log('hr.report.exported', (int) $request->user()->id, null, ['report' => $report, 'as_of' => $data['as_of']], (int) $company->id);

        return view('hr.reports.print', ['report' => $report, 'company' => $company, 'asOf' => $data['as_of'], 'rows' => $rows]);
    }

    public function xlsx(string $report, Request $request, HrAccessService $access, HrAuditLogService $audit): BinaryFileResponse
    {
        $data = $request->validate(['company_id' => ['required', 'integer', 'exists:accounting_companies,id'], 'as_of' => ['required', 'date']]);
        $permission = $report === 'payroll-register' ? 'hr.payroll.export' : 'hr.reports.export';
        $access->assertCompany($request->user(), (int) $data['company_id'], $permission);
        $rows = match ($report) {
            'directory' => $this->directory((int) $data['company_id'], $data['as_of'], $request)->map(fn ($row) => [$row->employee_number, $row->display_name, $row->currentBranch?->name, $row->currentDepartment?->name, $row->job_title, $this->enum($row->employment_status)]),
            'leave-balances' => $this->leaveBalances((int) $data['company_id'], $data['as_of'], $request)->map(fn ($row) => [$row->employee?->employee_number, $row->employee?->display_name, $row->leaveType?->name, (float) $row->balance]),
            'payroll-register' => $this->payrollRegister((int) $data['company_id'], $data['as_of'], $request)->map(fn ($row) => [$row->employee?->employee_number, $row->employee?->display_name, $row->basic_minor / 100, $row->gross_minor / 100, $row->deductions_minor / 100, $row->net_minor / 100]),
            default => abort(404),
        };
        $headers = match ($report) {
            'directory' => ['Employee #', 'Employee', 'Branch', 'Department', 'Job Title', 'Status'],
            'leave-balances' => ['Employee #', 'Employee', 'Leave Type', 'Balance Days'],
            'payroll-register' => ['Employee #', 'Employee', 'Basic QAR', 'Gross QAR', 'Deductions QAR', 'Net QAR'],
        };
        $audit->log('hr.report.exported', (int) $request->user()->id, null, ['report' => $report, 'format' => 'xlsx', 'as_of' => $data['as_of']], (int) $data['company_id']);

        return XlsxExport::download($headers, $rows, $report.'-'.$data['as_of'].'.xlsx', str($report)->headline()->toString());
    }

    private function directory(int $companyId, string $asOf, Request $request)
    {
        return HrEmployee::query()->where('company_id', $companyId)->when(! $request->user()->isAdmin(), fn ($q) => $q->whereIn('current_branch_id', $request->user()->allowedBranchIds() ?: [-1]))->whereDate('hire_date', '<=', $asOf)->where(fn ($q) => $q->whereNull('exit_date')->orWhereDate('exit_date', '>', $asOf))->with(['currentBranch', 'currentDepartment'])->orderBy('display_name')->get();
    }

    private function leaveBalances(int $companyId, string $asOf, Request $request)
    {
        return HrLeaveLedgerEntry::query()->where('company_id', $companyId)->whereDate('effective_date', '<=', $asOf)->whereHas('employee', fn ($q) => $q->when(! $request->user()->isAdmin(), fn ($q) => $q->whereIn('current_branch_id', $request->user()->allowedBranchIds() ?: [-1])))->selectRaw('employee_id, leave_type_id, SUM(days) as balance')->groupBy('employee_id', 'leave_type_id')->with(['employee', 'leaveType'])->get();
    }

    private function payrollRegister(int $companyId, string $asOf, Request $request)
    {
        $run = HrPayrollRun::query()->where('company_id', $companyId)->whereDate('pay_period_end', '<=', $asOf)->whereIn('status', ['approved', 'posted', 'paid'])->latest('pay_period_end')->first();
        if (! $run) {
            return collect();
        }

        return HrPayrollResult::query()->where('payroll_run_id', $run->id)->when(! $request->user()->isAdmin(), fn ($q) => $q->whereIn('branch_id', $request->user()->allowedBranchIds() ?: [-1]))->with('employee')->orderBy('employee_id')->get();
    }

    private function enum(mixed $value): string
    {
        return is_object($value) && isset($value->value) ? (string) $value->value : (string) $value;
    }
}
