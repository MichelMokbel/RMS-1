<?php

namespace App\Http\Controllers\HR;

use App\Enums\HR\ImportType;
use App\Http\Controllers\Controller;
use App\Models\AccountingCompany;
use App\Models\Branch;
use App\Models\Department;
use App\Models\HrLeaveType;
use App\Services\HR\HrAccessService;
use App\Services\HR\HrAuditLogService;
use App\Services\HR\Imports\FullHistoryTemplateBuilder;
use App\Support\Reports\XlsxExport;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class HrImportTemplateController extends Controller
{
    public function __invoke(
        string $type,
        Request $request,
        HrAccessService $access,
        HrAuditLogService $audit,
        FullHistoryTemplateBuilder $fullHistory,
    ): BinaryFileResponse {
        $data = $request->validate(['company_id' => ['required', 'integer', 'exists:accounting_companies,id']]);
        $companyId = (int) $data['company_id'];
        $access->assertCompany($request->user(), $companyId, 'hr.imports.manage');

        if ($type === ImportType::FullHistory->value) {
            $company = AccountingCompany::query()->findOrFail($companyId);
            $identifier = fn (mixed $row): string => filled($row->code) ? trim((string) $row->code) : 'ID:'.$row->id;
            $branches = Branch::query()->where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get()
                ->map(fn (Branch $row): array => ['identifier' => $identifier($row), 'name' => (string) $row->name])->all();
            $departments = Department::query()->where('company_id', $companyId)->orderBy('name')->get()
                ->map(fn (Department $row): array => ['identifier' => $identifier($row), 'name' => (string) $row->name])->all();
            $leaveTypes = HrLeaveType::query()->where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get()
                ->map(fn (HrLeaveType $row): array => ['identifier' => (string) $row->code, 'name' => (string) $row->name])->all();
            $path = $fullHistory->build((string) $company->name, $branches, $departments, $leaveTypes);
            $audit->log('hr.import.template_downloaded', (int) $request->user()->id, null, ['type' => $type], $companyId);
            $companyLabel = Str::slug((string) ($company->code ?: $company->name));

            return response()->download($path, "hr-full-history-{$companyLabel}-template.xlsx", [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
        }

        $headers = match ($type) {
            'employees' => ['legacy_employee_number', 'legal_first_name', 'legal_middle_name', 'legal_last_name', 'display_name', 'hire_date', 'current_branch_id', 'current_department_id', 'job_title', 'employment_status', 'work_email', 'personal_phone'],
            'assignments' => ['employee_number', 'branch_id', 'department_id', 'manager_id', 'job_title', 'employment_type', 'effective_from', 'effective_to'],
            'documents' => ['employee_number', 'document_type_code', 'file', 'issue_date', 'expiry_date', 'issuing_authority', 'notes'],
            'leave' => ['employee_number', 'leave_type_code', 'start_date', 'end_date', 'start_portion', 'end_portion', 'requested_days', 'status', 'reason'],
            'payroll' => ['employee_number', 'pay_period_start', 'pay_period_end', 'currency', 'gross_minor', 'net_minor', 'file'],
        };
        $audit->log('hr.import.template_downloaded', (int) $request->user()->id, null, ['type' => $type], $companyId);

        return XlsxExport::download($headers, [], "hr-{$type}-template.xlsx", 'HR Import');
    }
}
