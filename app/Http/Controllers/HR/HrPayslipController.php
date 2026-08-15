<?php

namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Models\HrPayrollResult;
use App\Services\HR\HrAccessService;
use App\Services\HR\HrAuditLogService;
use App\Support\Reports\PdfExport;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class HrPayslipController extends Controller
{
    public function __invoke(Request $request, HrPayrollResult $result, HrAccessService $access, HrAuditLogService $audit): Response
    {
        $result->loadMissing(['employee', 'payrollRun.company', 'components']);
        $access->assertPayroll($request->user(), $result->payrollRun, 'hr.payroll.view');
        if (! $request->user()->isAdmin() && $result->branch_id && ! in_array((int) $result->branch_id, $request->user()->allowedBranchIds(), true)) {
            abort(403);
        }

        $download = $request->boolean('download');
        $audit->log($download ? 'hr.payroll.payslip_downloaded' : 'hr.payroll.payslip_viewed', (int) $request->user()->id, $result, [], (int) $result->company_id);
        $filename = 'payslip-'.preg_replace('/[^A-Za-z0-9_-]/', '-', (string) $result->employee?->employee_number).'-'.$result->payrollRun?->pay_period_start?->format('Y-m').'.pdf';
        $pdf = PdfExport::output('hr.payroll.payslip', ['result' => $result]);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($download ? 'attachment' : 'inline').'; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
