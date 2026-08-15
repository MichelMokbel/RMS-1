<?php

use App\Models\AccountingCompany;
use App\Models\Branch;
use App\Models\Department;
use App\Models\HrDocumentType;
use App\Models\HrEmployee;
use App\Models\HrLeaveLedgerEntry;
use App\Models\HrLeavePolicy;
use App\Models\HrLeaveRequestEvent;
use App\Models\HrLeaveType;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Accounting\AccountingContextService;
use App\Services\Accounting\JournalEntryService;
use App\Services\Accounting\LedgerAccountMappingService;
use App\Services\HR\CompensationService;
use App\Services\HR\Documents\DocumentScanner;
use App\Services\HR\Documents\DocumentScanResult;
use App\Services\HR\EmployeeDocumentService;
use App\Services\HR\EmployeeService;
use App\Services\HR\HrAccessService;
use App\Services\HR\HrAuditLogService;
use App\Services\HR\HrNumberService;
use App\Services\HR\LeaveService;
use App\Services\HR\PayrollCalculationService;
use App\Services\HR\PayrollPaymentService;
use App\Services\HR\PayrollPostingService;
use App\Services\HR\PayrollRunService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/** @return array{company:AccountingCompany,branch:Branch,department:Department,admin:User,preparer:User,approver:User,finance:User} */
function hrWorkflowContext(string $code): array
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $company = AccountingCompany::query()->create([
        'name' => 'HR Workflow '.$code,
        'code' => $code,
        'base_currency' => 'QAR',
        'is_active' => true,
        'is_default' => false,
    ]);
    $branch = Branch::query()->create([
        'company_id' => $company->id,
        'name' => 'Main Branch',
        'code' => $code.'-BR',
        'is_active' => true,
    ]);
    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Kitchen',
        'code' => $code.'-KITCHEN',
        'is_active' => true,
    ]);

    $admin = User::factory()->create(['status' => 'active']);
    $preparer = User::factory()->create(['status' => 'active']);
    $approver = User::factory()->create(['status' => 'active']);
    $finance = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');
    $preparer->assignRole('hr');
    $approver->assignRole('hr');
    $finance->assignRole('accounting');
    $preparer->branches()->attach($branch->id);
    $approver->branches()->attach($branch->id);
    $finance->branches()->attach($branch->id);

    return compact('company', 'branch', 'department', 'admin', 'preparer', 'approver', 'finance');
}

it('runs employee, compensation, leave, and three-actor payroll through balanced idempotent posting', function () {
    $context = hrWorkflowContext('HR-WORKFLOW');
    ['company' => $company, 'branch' => $branch, 'department' => $department] = $context;
    ['admin' => $admin, 'preparer' => $preparer, 'approver' => $approver, 'finance' => $finance] = $context;

    $employee = app(EmployeeService::class)->create([
        'company_id' => $company->id,
        'legal_first_name' => 'Layla',
        'legal_last_name' => 'Employee',
        'display_name' => 'Layla Employee',
        'employment_status' => 'active',
        'hire_date' => '2026-01-01',
        'current_branch_id' => $branch->id,
        'current_department_id' => $department->id,
        'job_title' => 'Chef',
    ], (int) $preparer->id);

    expect(fn () => app(EmployeeService::class)->create([
        'company_id' => $company->id,
        'employee_number' => 'CALLER-SUPPLIED',
    ], (int) $preparer->id))->toThrow(ValidationException::class);

    $importedEmployee = app(EmployeeService::class)->createImportedLegacy([
        'company_id' => $company->id,
        'legal_first_name' => 'Legacy',
        'legal_last_name' => 'Employee',
        'display_name' => 'Legacy Employee',
        'hire_date' => '2026-01-01',
        'current_branch_id' => $branch->id,
    ], 'LEGACY-44', (int) $preparer->id);

    expect($importedEmployee->employee_number)->toMatch('/^EMP-\d{4}-\d{6}$/')
        ->and($importedEmployee->employee_number)->not->toBe('LEGACY-44')
        ->and($importedEmployee->metadata['legacy_employee_number'])->toBe('LEGACY-44');

    $package = app(CompensationService::class)->createPackage($employee, [
        'effective_from' => '2026-01-01',
        'currency' => 'QAR',
        'proration_divisor' => 30,
        'bank_name' => 'Test Bank',
        'bank_account_number' => '1234567890',
        'iban' => 'QA00TEST1234567890',
    ], [
        ['code' => 'basic', 'name' => 'Basic salary', 'category' => 'basic', 'amount_minor' => 300000],
        ['code' => 'housing', 'name' => 'Housing allowance', 'category' => 'allowance', 'amount_minor' => 100000],
    ], $preparer);

    expect($employee->assignments)->toHaveCount(1)
        ->and($employee->employee_number)->toMatch('/^EMP-\d{4}-\d{6}$/')
        ->and($package->components)->toHaveCount(2);

    $leaveType = HrLeaveType::query()->create([
        'company_id' => $company->id,
        'code' => 'annual',
        'name' => 'Annual Leave',
        'is_paid' => true,
        'is_active' => true,
    ]);
    $policy = HrLeavePolicy::query()->create([
        'company_id' => $company->id,
        'leave_type_id' => $leaveType->id,
        'name' => 'Annual Calendar Days',
        'effective_from' => '2026-01-01',
        'entitlement_days' => 21,
        'allow_negative' => false,
        'counts_calendar_days' => true,
        'is_active' => true,
    ]);
    HrLeaveLedgerEntry::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'policy_id' => $policy->id,
        'entry_type' => 'opening',
        'days' => 10,
        'effective_date' => '2026-01-01',
    ]);

    $leaveService = app(LeaveService::class);
    $leave = $leaveService->create($employee, [
        'leave_type_id' => $leaveType->id,
        'policy_id' => $policy->id,
        'start_date' => '2026-08-05',
        'end_date' => '2026-08-07',
        'reason' => 'Family leave',
    ], $admin);
    $leave = $leaveService->approve($leave, $admin, 'HR verified admin override');

    expect((float) $leave->requested_days)->toBe(3.0)
        ->and($leave->status->value)->toBe('approved')
        ->and($leaveService->balance($employee->id, $leaveType->id))->toBe(7.0)
        ->and(HrLeaveRequestEvent::query()
            ->where('leave_request_id', $leave->id)
            ->where('action', 'admin_override_approved')
            ->exists())->toBeTrue();

    $capturedPayload = null;
    $mappingService = Mockery::mock(LedgerAccountMappingService::class);
    $mappingService->shouldReceive('resolveAccountId')->andReturnUsing(fn (string $key): int => match ($key) {
        'payroll.salary_expense' => 101,
        'payroll.allowance_expense' => 102,
        'payroll.payable' => 201,
        default => 999,
    });
    $journalService = Mockery::mock(JournalEntryService::class);
    $journalService->shouldReceive('saveDraft')->once()->andReturnUsing(
        function (array $payload, int $actorId) use (&$capturedPayload): JournalEntry {
            $capturedPayload = $payload;

            return JournalEntry::query()->create([
                ...$payload,
                'entry_number' => 'TEST-HR-PAYROLL-001',
                'status' => 'draft',
                'created_by' => $actorId,
            ]);
        }
    );
    $journalService->shouldReceive('post')->once()->andReturnUsing(function (JournalEntry $journal, int $actorId): JournalEntry {
        $journal->forceFill(['status' => 'posted', 'posted_at' => now(), 'posted_by' => $actorId])->save();

        return $journal->fresh();
    });

    $posting = new PayrollPostingService($mappingService, $journalService);
    $runService = new PayrollRunService(
        app(PayrollCalculationService::class),
        $posting,
        Mockery::mock(PayrollPaymentService::class),
        app(HrAuditLogService::class),
        app(HrAccessService::class),
        app(AccountingContextService::class),
        app(HrNumberService::class),
    );
    $run = $runService->create([
        'company_id' => $company->id,
        'pay_period_start' => '2026-08-01',
        'pay_period_end' => '2026-08-31',
    ], $preparer);
    $run = $runService->calculate($run, $preparer);

    expect($run->run_number)->toMatch('/^PAY-\d{6}-\d{4}$/')
        ->and($run->results)->toHaveCount(1)
        ->and((int) $run->results->first()->gross_minor)->toBe(400000)
        ->and((int) $run->results->first()->net_minor)->toBe(400000);
    expect(fn () => $runService->approve($run, $preparer))->toThrow(ValidationException::class);

    $run = $runService->approve($run->fresh(), $approver, 'Checked against contracts');
    $approver->givePermissionTo('hr.payroll.post');
    expect(fn () => $runService->post($run, $approver))->toThrow(ValidationException::class);

    $run = $runService->post($run->fresh(), $finance);
    $sameRun = $runService->post($run->fresh(), $finance);
    $debits = round((float) collect($capturedPayload['lines'])->sum('debit'), 2);
    $credits = round((float) collect($capturedPayload['lines'])->sum('credit'), 2);

    expect($run->status->value)->toBe('posted')
        ->and($sameRun->id)->toBe($run->id)
        ->and($debits)->toBe($credits)
        ->and($debits)->toBe(4000.0)
        ->and(JournalEntry::query()
            ->where('source_type', $run::class)
            ->where('source_id', $run->id)
            ->count())->toBe(1);
});

it('stores clean documents privately and rejects spoofed MIME types and unsafe paths', function () {
    $context = hrWorkflowContext('HR-DOCUMENT');
    ['company' => $company, 'branch' => $branch, 'admin' => $admin] = $context;
    $employee = HrEmployee::factory()->create([
        'company_id' => $company->id,
        'employee_number' => 'EMP-DOCUMENT-1',
        'current_branch_id' => $branch->id,
    ]);
    $type = HrDocumentType::query()->create([
        'company_id' => $company->id,
        'code' => 'qid',
        'name' => 'QID',
        'is_active' => true,
    ]);

    config(['hr.documents.disk' => 'hr-test']);
    Storage::fake('hr-test');
    $scanner = new class implements DocumentScanner
    {
        public function scan(string $absolutePath): DocumentScanResult
        {
            return new DocumentScanResult(is_file($absolutePath), 'fake-clean');
        }
    };
    $service = new EmployeeDocumentService(
        app(HrAuditLogService::class),
        app(HrAccessService::class),
        app(HrNumberService::class),
        $scanner,
    );

    $document = $service->store(
        $employee,
        $type,
        UploadedFile::fake()->createWithContent('qid.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF"),
        ['document_number' => 'caller-value-must-be-ignored'],
        $admin,
    );
    $version = $document->currentVersion;

    Storage::disk('hr-test')->assertExists($version->object_key);
    expect($version->object_key)->toStartWith("hr/{$company->id}/documents/{$document->id}/")
        ->and($document->document_number)->toMatch('/^HRD-\d{4}-\d{6}$/')
        ->and($document->document_number)->not->toBe('caller-value-must-be-ignored')
        ->and($version->object_key)->not->toContain('qid.pdf')
        ->and(Storage::disk('hr-test')->getVisibility($version->object_key))->toBe('private')
        ->and($service->download($version, $admin)['path'])->toBe($version->object_key);

    expect(fn () => $service->store(
        $employee,
        $type,
        UploadedFile::fake()->createWithContent('spoofed.pdf', 'plain text, not a PDF'),
        [],
        $admin,
    ))->toThrow(ValidationException::class);

    $unsafeVersion = $version->replicate();
    $unsafeVersion->object_key = '../outside.pdf';
    $unsafeVersion->setRelation('document', $document);
    expect(fn () => $service->download($unsafeVersion, $admin))->toThrow(ValidationException::class);
});
