<?php

use App\Models\AccountingCompany;
use App\Models\HrAuditLog;
use App\Models\HrCompensationPackage;
use App\Models\HrDocument;
use App\Models\HrDocumentType;
use App\Models\HrDocumentVersion;
use App\Models\HrEmployee;
use App\Models\HrImportBatch;
use App\Models\HrImportRow;
use App\Models\HrLeaveLedgerEntry;
use App\Models\HrLeaveRequest;
use App\Models\HrLeaveRequestEvent;
use App\Models\HrLeaveType;
use App\Models\HrPayrollAdjustment;
use App\Models\HrPayrollPaymentBatch;
use App\Models\HrPayrollPaymentBatchItem;
use App\Models\HrPayrollResult;
use App\Models\HrPayrollResultComponent;
use App\Models\HrPayrollRun;
use App\Models\HrPayrollStatusEvent;
use Illuminate\Support\Facades\DB;

function hrSecurityCompany(string $code = 'HR-SEC'): AccountingCompany
{
    return AccountingCompany::query()->create([
        'name' => 'HR Security Company '.$code,
        'code' => $code,
        'base_currency' => 'QAR',
        'is_active' => true,
        'is_default' => false,
    ]);
}

function hrSecurityEmployee(AccountingCompany $company, string $number = 'EMP-SEC-1'): HrEmployee
{
    return HrEmployee::factory()->create([
        'company_id' => $company->id,
        'employee_number' => $number,
    ]);
}

it('encrypts employee, bank, and document identifiers at rest', function () {
    $company = hrSecurityCompany();
    $employee = hrSecurityEmployee($company);

    $employee->update([
        'qid_number' => '28765432109',
        'passport_number' => 'P1234567',
    ]);

    $package = HrCompensationPackage::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'effective_from' => '2026-01-01',
        'beneficiary_name' => 'Layla Employee',
        'bank_account_number' => '001234567890',
        'iban' => 'QA58DOHB00001234567890',
        'swift_code' => 'DOHBQAQA',
    ]);

    $type = HrDocumentType::query()->create([
        'company_id' => $company->id,
        'code' => 'passport',
        'name' => 'Passport',
    ]);
    $document = HrDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $type->id,
        'document_number' => 'P1234567',
    ]);

    $run = HrPayrollRun::query()->create([
        'company_id' => $company->id,
        'run_number' => 'PAY-SEC-001',
        'pay_period_start' => '2026-01-01',
        'pay_period_end' => '2026-01-31',
    ]);
    $result = HrPayrollResult::query()->create([
        'company_id' => $company->id,
        'payroll_run_id' => $run->id,
        'employee_id' => $employee->id,
        'currency' => 'QAR',
        'net_minor' => 100000,
    ]);
    $batch = HrPayrollPaymentBatch::query()->create([
        'company_id' => $company->id,
        'payroll_run_id' => $run->id,
        'bank_account_id' => 1,
        'batch_number' => 'PAY-SEC-BATCH-001',
        'payment_date' => '2026-02-01',
    ]);
    $paymentItem = HrPayrollPaymentBatchItem::query()->create([
        'payment_batch_id' => $batch->id,
        'payroll_result_id' => $result->id,
        'employee_id' => $employee->id,
        'amount_minor' => 100000,
        'beneficiary_name' => 'Layla Employee',
        'bank_account_number' => '001234567890',
        'iban' => 'QA58DOHB00001234567890',
    ]);

    $rawEmployee = DB::table('hr_employees')->where('id', $employee->id)->first();
    $rawPackage = DB::table('hr_compensation_packages')->where('id', $package->id)->first();
    $rawDocument = DB::table('hr_documents')->where('id', $document->id)->first();
    $rawPaymentItem = DB::table('hr_payroll_payment_batch_items')->where('id', $paymentItem->id)->first();

    expect($rawEmployee->qid_number)->not->toBe('28765432109')
        ->and($rawEmployee->passport_number)->not->toBe('P1234567')
        ->and($rawPackage->beneficiary_name)->not->toBe('Layla Employee')
        ->and($rawPackage->bank_account_number)->not->toBe('001234567890')
        ->and($rawPackage->iban)->not->toBe('QA58DOHB00001234567890')
        ->and($rawPackage->swift_code)->not->toBe('DOHBQAQA')
        ->and($rawDocument->document_number)->not->toBe('P1234567')
        ->and($rawPaymentItem->beneficiary_name)->not->toBe('Layla Employee')
        ->and($rawPaymentItem->bank_account_number)->not->toBe('001234567890')
        ->and($rawPaymentItem->iban)->not->toBe('QA58DOHB00001234567890')
        ->and($employee->fresh()->qid_number)->toBe('28765432109')
        ->and($employee->fresh()->passport_number)->toBe('P1234567')
        ->and($package->fresh()->bank_account_number)->toBe('001234567890')
        ->and($package->fresh()->iban)->toBe('QA58DOHB00001234567890')
        ->and($document->fresh()->document_number)->toBe('P1234567')
        ->and($paymentItem->fresh()->bank_account_number)->toBe('001234567890')
        ->and($paymentItem->fresh()->iban)->toBe('QA58DOHB00001234567890');
});

it('encrypts staged import payloads and validation errors at rest', function () {
    $company = hrSecurityCompany('HR-IMPORT-ENC');
    $batch = HrImportBatch::query()->create([
        'company_id' => $company->id,
        'type' => 'employees',
        'source_name' => 'employees.xlsx',
        'storage_disk' => 's3',
        'object_key' => 'hr/imports/employees.xlsx',
        'sha256' => str_repeat('b', 64),
    ]);
    $row = HrImportRow::query()->create([
        'import_batch_id' => $batch->id,
        'row_number' => 2,
        'source_identifier' => 'EMP-IMPORT-1',
        'payload' => ['qid_number' => '28765432109', 'name' => 'Private Employee'],
        'errors' => ['qid_number' => ['The QID is already in use.']],
        'row_hash' => str_repeat('c', 64),
    ]);

    $raw = DB::table('hr_import_rows')->where('id', $row->id)->first();

    expect($raw->payload)->not->toContain('28765432109')
        ->and($raw->payload)->not->toContain('Private Employee')
        ->and($raw->errors)->not->toContain('QID is already in use')
        ->and($row->fresh()->payload['qid_number'])->toBe('28765432109')
        ->and($row->fresh()->errors['qid_number'][0])->toBe('The QID is already in use.');
});

it('keeps audit, event, ledger, version, and financial detail records append-only', function () {
    $company = hrSecurityCompany('HR-APPEND');
    $employee = hrSecurityEmployee($company, 'EMP-APPEND-1');

    $audit = HrAuditLog::query()->create([
        'company_id' => $company->id,
        'action' => 'hr.test.created',
    ]);

    $type = HrDocumentType::query()->create([
        'company_id' => $company->id,
        'code' => 'qid',
        'name' => 'QID',
    ]);
    $document = HrDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $type->id,
    ]);
    $version = HrDocumentVersion::query()->create([
        'company_id' => $company->id,
        'document_id' => $document->id,
        'version_number' => 1,
        'storage_disk' => 's3',
        'object_key' => "hr/{$company->id}/documents/{$document->id}/test.pdf",
        'original_name' => 'qid.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 128,
        'sha256' => str_repeat('a', 64),
        'scan_status' => 'clean',
    ]);

    $leaveType = HrLeaveType::query()->create([
        'company_id' => $company->id,
        'code' => 'annual',
        'name' => 'Annual Leave',
    ]);
    $leave = HrLeaveRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-01',
        'requested_days' => 1,
    ]);
    $leaveEvent = HrLeaveRequestEvent::query()->create([
        'company_id' => $company->id,
        'leave_request_id' => $leave->id,
        'to_status' => 'pending_manager',
        'action' => 'submitted',
    ]);
    $ledger = HrLeaveLedgerEntry::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'leave_request_id' => $leave->id,
        'entry_type' => 'reservation',
        'days' => -1,
        'effective_date' => '2026-08-01',
    ]);

    $run = HrPayrollRun::query()->create([
        'company_id' => $company->id,
        'run_number' => 'PAY-202608-001',
        'pay_period_start' => '2026-08-01',
        'pay_period_end' => '2026-08-31',
    ]);
    $result = HrPayrollResult::query()->create([
        'company_id' => $company->id,
        'payroll_run_id' => $run->id,
        'employee_id' => $employee->id,
        'currency' => 'QAR',
        'basic_minor' => 100000,
        'gross_minor' => 100000,
        'earnings_minor' => 0,
        'deductions_minor' => 0,
        'net_minor' => 100000,
    ]);
    $component = HrPayrollResultComponent::query()->create([
        'payroll_result_id' => $result->id,
        'code' => 'BASIC',
        'name' => 'Basic salary',
        'category' => 'basic',
        'amount_minor' => 100000,
    ]);
    $payrollEvent = HrPayrollStatusEvent::query()->create([
        'company_id' => $company->id,
        'payroll_run_id' => $run->id,
        'to_status' => 'draft',
    ]);
    $batch = HrPayrollPaymentBatch::query()->create([
        'company_id' => $company->id,
        'payroll_run_id' => $run->id,
        'bank_account_id' => 1,
        'batch_number' => 'PAY-PAY-202608-001',
        'payment_date' => '2026-09-01',
    ]);
    $paymentItem = HrPayrollPaymentBatchItem::query()->create([
        'payment_batch_id' => $batch->id,
        'payroll_result_id' => $result->id,
        'employee_id' => $employee->id,
        'amount_minor' => 100000,
        'status' => 'pending',
    ]);

    $records = [
        [$audit, 'action', 'hr.test.changed'],
        [$version, 'scan_status', 'rejected'],
        [$leaveEvent, 'reason', 'changed'],
        [$ledger, 'notes', 'changed'],
        [$component, 'name', 'Changed'],
        [$payrollEvent, 'reason', 'changed'],
        [$paymentItem, 'status', 'processed'],
    ];

    foreach ($records as [$record, $field, $value]) {
        expect(fn () => $record->update([$field => $value]))->toThrow(LogicException::class);
        expect(fn () => $record->delete())->toThrow(LogicException::class);
    }
});

it('forces migrated payroll and its employee results to remain non-postable and read-only', function () {
    $company = hrSecurityCompany('HR-MIGRATED');
    $employee = hrSecurityEmployee($company, 'EMP-MIGRATED-1');

    $run = HrPayrollRun::query()->create([
        'company_id' => $company->id,
        'run_number' => 'MIG-202501',
        'pay_period_start' => '2025-01-01',
        'pay_period_end' => '2025-01-31',
        'origin' => 'migrated',
        'status' => 'paid',
        'is_postable' => true,
    ]);
    $result = HrPayrollResult::query()->create([
        'company_id' => $company->id,
        'payroll_run_id' => $run->id,
        'employee_id' => $employee->id,
        'currency' => 'QAR',
        'gross_minor' => 200000,
        'net_minor' => 180000,
        'is_postable' => true,
    ]);

    expect($run->is_postable)->toBeFalse()
        ->and($result->is_postable)->toBeFalse();

    expect(fn () => $run->update(['description' => 'changed']))->toThrow(LogicException::class);
    expect(fn () => $run->delete())->toThrow(LogicException::class);
    expect(fn () => $result->update(['net_minor' => 170000]))->toThrow(LogicException::class);
    expect(fn () => $result->delete())->toThrow(LogicException::class);
});

it('allows one-time adjustment approval without reopening calculated payroll details', function () {
    $company = hrSecurityCompany('HR-ADJUSTMENT');
    $employee = hrSecurityEmployee($company, 'EMP-ADJUSTMENT-1');
    $run = HrPayrollRun::query()->create([
        'company_id' => $company->id,
        'run_number' => 'PAY-ADJUSTMENT-1',
        'pay_period_start' => '2026-08-01',
        'pay_period_end' => '2026-08-31',
        'status' => 'calculated',
    ]);
    $adjustment = HrPayrollAdjustment::query()->create([
        'company_id' => $company->id,
        'payroll_run_id' => $run->id,
        'employee_id' => $employee->id,
        'type' => 'earning',
        'code' => 'BONUS',
        'description' => 'Performance bonus',
        'amount_minor' => 10000,
    ]);

    $adjustment->update(['approved_at' => now(), 'approved_by' => 10]);

    expect($adjustment->approved_by)->toBe(10);
    expect(fn () => $adjustment->update(['amount_minor' => 20000]))->toThrow(LogicException::class);
    expect(fn () => $adjustment->delete())->toThrow(LogicException::class);
});
