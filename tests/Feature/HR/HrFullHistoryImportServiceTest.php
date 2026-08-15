<?php

use App\Models\AccountingCompany;
use App\Models\Branch;
use App\Models\Department;
use App\Models\HrCompensationPackage;
use App\Models\HrEmployee;
use App\Models\HrEmployeeAssignment;
use App\Models\HrImportBatch;
use App\Models\HrImportRow;
use App\Models\HrLeaveLedgerEntry;
use App\Models\HrLeaveRequest;
use App\Models\HrLeaveType;
use App\Models\HrPayrollResult;
use App\Models\HrPayrollResultComponent;
use App\Models\HrPayrollRun;
use App\Models\User;
use App\Services\HR\HrImportService;
use App\Services\HR\Imports\FullHistoryTemplateBuilder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/** @return array{company:AccountingCompany,branch:Branch,department:Department,leaveType:HrLeaveType,admin:User} */
function hrFullHistoryContext(string $suffix): array
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $company = AccountingCompany::query()->create([
        'name' => 'Full History '.$suffix, 'code' => 'FH-'.$suffix, 'base_currency' => 'QAR', 'is_active' => true,
    ]);
    $branch = Branch::query()->create([
        'company_id' => $company->id, 'name' => 'Main '.$suffix, 'code' => 'MAIN-'.$suffix, 'is_active' => true,
    ]);
    $department = Department::query()->create([
        'company_id' => $company->id, 'name' => 'Kitchen '.$suffix, 'code' => 'KIT-'.$suffix, 'is_active' => true,
    ]);
    $leaveType = HrLeaveType::query()->create([
        'company_id' => $company->id, 'code' => 'annual', 'name' => 'Annual Leave', 'is_paid' => true, 'is_active' => true,
    ]);
    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');

    return compact('company', 'branch', 'department', 'leaveType', 'admin');
}

/** @return array<string,array<int,array<string,mixed>>> */
function hrFullHistoryRows(array $context): array
{
    return [
        'Employees' => [[
            'employee_ref' => 'LOCAL-001', 'legacy_employee_number' => 'LEGACY-001', 'legal_first_name' => 'Mariam',
            'legal_last_name' => 'Tester', 'display_name' => 'Mariam Tester', 'work_email' => 'mariam@example.test',
            'hire_date' => '2026-01-01', 'employment_type' => 'full_time', 'employment_status' => 'active',
        ]],
        'Assignments' => [[
            'employee_ref' => 'LOCAL-001', 'branch_code' => strtolower($context['branch']->code),
            'department_code' => 'ID:'.$context['department']->id, 'job_title' => 'Chef', 'employment_type' => 'full_time',
            'effective_from' => '2026-01-01',
        ]],
        'Compensation' => [[
            'employee_ref' => 'LOCAL-001', 'package_ref' => 'PKG-001', 'effective_from' => '2026-01-01',
            'currency' => 'QAR', 'pay_frequency' => 'monthly', 'proration_divisor' => '30',
            'bank_name' => 'Test Bank', 'beneficiary_name' => 'Mariam Tester', 'iban' => 'QA00TEST0001',
        ]],
        'Compensation Components' => [
            ['employee_ref' => 'LOCAL-001', 'package_ref' => 'PKG-001', 'component_code' => 'BASIC', 'component_name' => 'Basic', 'category' => 'basic', 'amount_qar' => '3000.00', 'is_taxable' => 'FALSE'],
            ['employee_ref' => 'LOCAL-001', 'package_ref' => 'PKG-001', 'component_code' => 'ALLOW', 'component_name' => 'Allowance', 'category' => 'allowance', 'amount_qar' => '500.00', 'is_taxable' => 'FALSE'],
        ],
        'Leave Balances' => [[
            'employee_ref' => 'LOCAL-001', 'leave_type_code' => 'ANNUAL', 'effective_date' => '2026-01-01', 'days' => '20.00', 'notes' => 'Opening',
        ]],
        'Leave History' => [
            [
                'employee_ref' => 'LOCAL-001', 'leave_type_code' => 'annual', 'start_date' => '2026-03-01',
                'end_date' => '2026-03-01', 'start_portion' => 'full', 'end_portion' => 'full',
                'requested_days' => '1.00', 'status' => 'approved', 'reason' => 'Later historical leave entered first',
            ],
            [
                'employee_ref' => 'LOCAL-001', 'leave_type_code' => 'annual', 'start_date' => '2026-02-01',
                'end_date' => '2026-02-02', 'start_portion' => 'full', 'end_portion' => 'full',
                'requested_days' => '2.00', 'status' => 'approved', 'reason' => 'Earlier historical leave entered second',
            ],
        ],
        'Payroll History' => [[
            'payroll_ref' => 'PAY-AUG-001', 'employee_ref' => 'LOCAL-001', 'pay_period_start' => '2026-08-01',
            'pay_period_end' => '2026-08-31', 'currency' => 'QAR', 'basic_qar' => '3000.00',
            'gross_qar' => '3500.00', 'earnings_qar' => '500.00', 'deductions_qar' => '100.00', 'net_qar' => '3400.00',
            'calendar_days' => '31', 'worked_days' => '29', 'unpaid_leave_days' => '0', 'paid_at' => '2026-08-31',
            'description' => 'August migrated payroll',
        ]],
        'Payroll Components' => [
            ['payroll_ref' => 'PAY-AUG-001', 'employee_ref' => 'LOCAL-001', 'component_code' => 'BASIC', 'component_name' => 'Basic', 'category' => 'basic', 'amount_qar' => '3000.00', 'is_taxable' => 'FALSE'],
            ['payroll_ref' => 'PAY-AUG-001', 'employee_ref' => 'LOCAL-001', 'component_code' => 'ALLOW', 'component_name' => 'Allowance', 'category' => 'allowance', 'amount_qar' => '500.00', 'is_taxable' => 'FALSE'],
            ['payroll_ref' => 'PAY-AUG-001', 'employee_ref' => 'LOCAL-001', 'component_code' => 'DEDUCT', 'component_name' => 'Deduction', 'category' => 'deduction', 'amount_qar' => '100.00', 'is_taxable' => 'FALSE'],
        ],
    ];
}

function hrFullHistoryWorkbook(array $context, ?callable $mutate = null): string
{
    $path = (new FullHistoryTemplateBuilder)->build($context['company']->name,
        [['identifier' => $context['branch']->code, 'name' => $context['branch']->name]],
        [['identifier' => 'ID:'.$context['department']->id, 'name' => $context['department']->name]],
        [['identifier' => $context['leaveType']->code, 'name' => $context['leaveType']->name]]);
    $rowsBySheet = hrFullHistoryRows($context);
    if ($mutate) {
        $mutate($rowsBySheet);
    }
    $zip = new ZipArchive;
    $zip->open($path);
    foreach (array_keys(FullHistoryTemplateBuilder::DATA_SHEETS) as $offset => $sheetName) {
        $sheetNumber = $offset + 2;
        $xml = (string) $zip->getFromName("xl/worksheets/sheet{$sheetNumber}.xml");
        $rows = '';
        foreach ($rowsBySheet[$sheetName] ?? [] as $rowIndex => $values) {
            $cells = '';
            foreach (FullHistoryTemplateBuilder::DATA_SHEETS[$sheetName] as $column => $header) {
                $value = (string) ($values[$header] ?? '');
                $reference = hrFullHistoryColumn($column + 1).($rowIndex + 2);
                $cells .= '<c r="'.$reference.'" t="inlineStr"><is><t xml:space="preserve">'.htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8').'</t></is></c>';
            }
            $rows .= '<row r="'.($rowIndex + 2).'">'.$cells.'</row>';
        }
        $zip->addFromString("xl/worksheets/sheet{$sheetNumber}.xml", str_replace('</sheetData>', $rows.'</sheetData>', $xml));
    }
    $zip->close();

    return $path;
}

function hrFullHistoryColumn(int $index): string
{
    $name = '';
    while ($index > 0) {
        $index--;
        $name = chr(65 + ($index % 26)).$name;
        $index = intdiv($index, 26);
    }

    return $name;
}

function hrFullHistoryUpload(string $path): UploadedFile
{
    return new UploadedFile($path, 'full-history.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

beforeEach(function (): void {
    config(['hr.documents.disk' => 'hr-full-history-test']);
    Storage::fake('hr-full-history-test');
});

it('stages and atomically commits the exact consolidated template with idempotent results', function (): void {
    $context = hrFullHistoryContext('SUCCESS');
    $path = hrFullHistoryWorkbook($context);
    try {
        $service = app(HrImportService::class);
        $batch = $service->stage(hrFullHistoryUpload($path), 'full_history', $context['company']->id, $context['admin']);

        expect($batch->status->value)->toBe('ready')
            ->and($batch->stats['sheets']['employees'])->toBe(['rows' => 1, 'valid' => 1, 'errors' => 0])
            ->and($batch->stats['sheets']['payroll_components']['rows'])->toBe(3)
            ->and(DB::table('hr_import_rows')->where('import_batch_id', $batch->id)->value('payload'))->not->toContain('Mariam');

        $this->actingAs($context['admin'])
            ->get(route('hr.imports.show', ['batch' => $batch->id]))
            ->assertOk()
            ->assertSee('Staged row review')
            ->assertSee('Mariam Tester')
            ->assertSee('Confirm and commit')
            ->assertSee('No live HR records have been changed yet');

        $batch = $service->commit($batch, $context['admin']);
        $employee = HrEmployee::query()->where('company_id', $context['company']->id)->firstOrFail();
        $result = HrPayrollResult::query()->firstOrFail();
        expect($batch->status->value)->toBe('completed')
            ->and($employee->employee_number)->toMatch('/^EMP-\d{4}-\d{6}$/')
            ->and($employee->employee_number)->not->toBe('LEGACY-001')
            ->and($employee->metadata['full_history_employee_ref'])->toBe('LOCAL-001')
            ->and(HrEmployeeAssignment::query()->where('employee_id', $employee->id)->count())->toBe(1)
            ->and(HrCompensationPackage::query()->where('employee_id', $employee->id)->first()->components()->count())->toBe(2)
            ->and(HrLeaveRequest::query()->where('employee_id', $employee->id)->count())->toBe(2)
            ->and(HrLeaveLedgerEntry::query()->where('employee_id', $employee->id)->count())->toBe(3)
            ->and(HrLeaveLedgerEntry::query()->where('employee_id', $employee->id)->where('entry_type', 'usage')->orderBy('id')->pluck('effective_date')->map->format('Y-m-d')->all())->toBe(['2026-02-01', '2026-03-01'])
            ->and(HrPayrollRun::query()->first()->origin->value)->toBe('migrated')
            ->and(HrPayrollRun::query()->first()->is_postable)->toBeFalse()
            ->and($result->net_minor)->toBe(340000)
            ->and($result->is_postable)->toBeFalse()
            ->and(HrPayrollResultComponent::query()->where('payroll_result_id', $result->id)->count())->toBe(3)
            ->and(HrImportRow::query()->where('import_batch_id', $batch->id)->where('status', '!=', 'committed')->count())->toBe(0);

        $counts = [HrEmployee::count(), HrEmployeeAssignment::count(), HrCompensationPackage::count(), HrLeaveRequest::count(), HrPayrollRun::count(), HrPayrollResult::count()];
        $service->commit($batch->fresh(), $context['admin']);
        expect([HrEmployee::count(), HrEmployeeAssignment::count(), HrCompensationPackage::count(), HrLeaveRequest::count(), HrPayrollRun::count(), HrPayrollResult::count()])->toBe($counts);
    } finally {
        @unlink($path);
    }
});

it('stores cross-sheet validation failures without changing HR domain records', function (): void {
    $context = hrFullHistoryContext('INVALID');
    $path = hrFullHistoryWorkbook($context, function (array &$sheets): void {
        $sheets['Assignments'][0]['employee_ref'] = 'MISSING-EMPLOYEE';
    });
    try {
        $batch = app(HrImportService::class)->stage(hrFullHistoryUpload($path), 'full_history', $context['company']->id, $context['admin']);
        $invalid = $batch->rows->first(fn ($row) => $row->payload['_sheet'] === 'assignments');
        expect($batch->status->value)->toBe('failed')
            ->and($invalid->errors)->toHaveKey('employee_ref')
            ->and(HrEmployee::query()->where('company_id', $context['company']->id)->count())->toBe(0)
            ->and(DB::table('hr_import_rows')->where('id', $invalid->id)->value('errors'))->not->toContain('Employee reference');
    } finally {
        @unlink($path);
    }
});

it('accepts header-only optional payroll detail and records that detail is unavailable', function (): void {
    $context = hrFullHistoryContext('NO-DETAIL');
    $path = hrFullHistoryWorkbook($context, function (array &$sheets): void {
        $sheets['Payroll Components'] = [];
    });
    try {
        $service = app(HrImportService::class);
        $batch = $service->stage(hrFullHistoryUpload($path), 'full_history', $context['company']->id, $context['admin']);
        expect($batch->stats['sheets']['payroll_components'])->toBe(['rows' => 0, 'valid' => 0, 'errors' => 0]);

        $service->commit($batch, $context['admin']);
        expect(HrPayrollResult::query()->firstOrFail()->snapshot['component_detail_available'])->toBeFalse()
            ->and(HrPayrollResultComponent::query()->count())->toBe(0);
    } finally {
        @unlink($path);
    }
});

it('validates headers even when an optional data sheet has no rows', function (): void {
    $context = hrFullHistoryContext('HEADERS');
    $path = hrFullHistoryWorkbook($context, function (array &$sheets): void {
        $sheets['Payroll Components'] = [];
    });
    $zip = new ZipArchive;
    $zip->open($path);
    $xml = (string) $zip->getFromName('xl/worksheets/sheet9.xml');
    $zip->addFromString('xl/worksheets/sheet9.xml', str_replace('>component_code<', '>wrong_code<', $xml));
    $zip->close();
    try {
        expect(fn () => app(HrImportService::class)->stage(hrFullHistoryUpload($path), 'full_history', $context['company']->id, $context['admin']))
            ->toThrow(ValidationException::class, 'Header mismatch')
            ->and(HrImportBatch::query()->where('company_id', $context['company']->id)->count())->toBe(0);
    } finally {
        @unlink($path);
    }
});

it('rolls back every dependency when consolidated commit fails and remains retryable', function (): void {
    $context = hrFullHistoryContext('ROLLBACK');
    $path = hrFullHistoryWorkbook($context);
    try {
        $service = app(HrImportService::class);
        $batch = $service->stage(hrFullHistoryUpload($path), 'full_history', $context['company']->id, $context['admin']);
        $assignment = $batch->rows->first(fn ($row) => $row->payload['_sheet'] === 'assignments');
        $payload = $assignment->payload;
        $payload['_branch_id'] = 99999999;
        $assignment->forceFill(['payload' => $payload])->save();

        expect(fn () => $service->commit($batch, $context['admin']))->toThrow(ValidationException::class)
            ->and(HrEmployee::query()->where('company_id', $context['company']->id)->count())->toBe(0)
            ->and(HrCompensationPackage::query()->where('company_id', $context['company']->id)->count())->toBe(0)
            ->and(HrPayrollRun::query()->where('company_id', $context['company']->id)->count())->toBe(0)
            ->and($batch->fresh()->status->value)->toBe('ready')
            ->and($batch->fresh()->failure_reason)->not->toBeNull();
    } finally {
        @unlink($path);
    }
});

it('rejects formula-bearing consolidated workbooks before staging', function (): void {
    $context = hrFullHistoryContext('FORMULA');
    $path = hrFullHistoryWorkbook($context);
    $zip = new ZipArchive;
    $zip->open($path);
    $xml = (string) $zip->getFromName('xl/worksheets/sheet2.xml');
    $xml = preg_replace('/<c r="A2"[^>]*>.*?<\/c>/s', '<c r="A2"><f>1+1</f><v>2</v></c>', $xml, 1);
    $zip->addFromString('xl/worksheets/sheet2.xml', $xml);
    $zip->close();
    try {
        expect(fn () => app(HrImportService::class)->stage(hrFullHistoryUpload($path), 'full_history', $context['company']->id, $context['admin']))
            ->toThrow(RuntimeException::class, 'Formulas are not allowed')
            ->and(HrImportBatch::query()->where('company_id', $context['company']->id)->count())->toBe(0);
    } finally {
        @unlink($path);
    }
});
