<?php

namespace App\Services\HR;

use App\Models\AccountingCompany;
use App\Models\HrDocumentType;
use App\Models\HrEmployee;
use App\Models\HrImportBatch;
use App\Models\HrImportRow;
use App\Models\HrLeaveLedgerEntry;
use App\Models\HrLeaveRequest;
use App\Models\HrLeaveType;
use App\Models\HrPayrollResult;
use App\Models\HrPayrollRun;
use App\Models\User;
use App\Services\HR\Imports\FullHistoryImportService;
use App\Services\HR\Imports\SafeSpreadsheetReader;
use App\Services\HR\Imports\SafeZipArchive;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class HrImportService
{
    public function __construct(
        protected SafeSpreadsheetReader $reader,
        protected SafeZipArchive $archives,
        protected EmployeeService $employees,
        protected EmployeeDocumentService $documents,
        protected HrAuditLogService $audit,
        protected HrAccessService $access,
        protected HrNumberService $numbers,
        protected FullHistoryImportService $fullHistory,
    ) {}

    public function stage(UploadedFile $manifest, string $type, int $companyId, User $actor, ?UploadedFile $archive = null): HrImportBatch
    {
        $this->access->assertCompany($actor, $companyId, 'hr.imports.manage');
        $type = $type === 'payroll_history' ? 'payroll' : $type;
        if (! in_array($type, ['employees', 'assignments', 'documents', 'leave', 'payroll', 'full_history'], true)) {
            throw ValidationException::withMessages(['type' => __('Unsupported HR import type.')]);
        }
        $this->assertUpload($manifest, ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip']);
        if ($type === 'full_history') {
            if ($archive) {
                throw ValidationException::withMessages(['archive' => __('Consolidated history imports do not accept ZIP archives or documents.')]);
            }

            return $this->fullHistory->stage($manifest, $companyId, $actor);
        }
        $hash = hash_file('sha256', $manifest->getRealPath());
        $existing = HrImportBatch::query()->where('company_id', $companyId)->where('type', $type)->where('sha256', $hash)->first();
        if ($existing) {
            return $existing;
        }

        $archiveNames = [];
        if ($archive) {
            $this->assertUpload($archive, ['application/zip', 'application/x-zip-compressed']);
            $archiveNames = $this->archives->validate($archive->getRealPath());
        }
        if ($type === 'documents' && ! $archive) {
            throw ValidationException::withMessages(['archive' => __('A document ZIP archive is required for document imports.')]);
        }

        $rows = $this->reader->rows($manifest->getRealPath());
        $disk = (string) config('hr.documents.disk', 'local');
        $root = "hr/{$companyId}/imports";
        $manifestKey = $root.'/'.Str::uuid().'.xlsx';
        Storage::disk($disk)->putFileAs($root, $manifest, basename($manifestKey), ['visibility' => 'private']);
        $archiveKey = null;
        if ($archive) {
            $archiveKey = $root.'/'.Str::uuid().'.zip';
            Storage::disk($disk)->putFileAs($root, $archive, basename($archiveKey), ['visibility' => 'private']);
        }

        return DB::transaction(function () use ($manifest, $type, $companyId, $actor, $hash, $disk, $manifestKey, $archiveKey, $archiveNames, $rows): HrImportBatch {
            $batch = HrImportBatch::query()->create([
                'company_id' => $companyId, 'type' => $type, 'status' => 'validating',
                'source_name' => Str::limit(basename($manifest->getClientOriginalName()), 255, ''),
                'storage_disk' => $disk, 'object_key' => $manifestKey, 'archive_object_key' => $archiveKey,
                'sha256' => $hash, 'options' => ['archive_entries' => count($archiveNames)],
                'initiated_by' => $actor->id, 'initiated_at' => now(),
            ]);
            $invalid = 0;
            $seenIdentifiers = [];
            foreach ($rows as $offset => $payload) {
                $errors = $this->validateRow($type, $payload, $companyId, $archiveNames);
                $identifier = $this->sourceIdentifier($type, $payload);
                if ($identifier !== null && in_array($identifier, $seenIdentifiers, true)) {
                    $errors['source_identifier'] = 'Duplicate source identifier in this manifest.';
                }
                if ($identifier !== null) {
                    $seenIdentifiers[] = $identifier;
                }
                if ($errors !== []) {
                    $invalid++;
                }
                HrImportRow::query()->create([
                    'import_batch_id' => $batch->id, 'row_number' => $offset + 2,
                    'source_identifier' => $identifier,
                    'status' => $errors === [] ? 'valid' : 'invalid', 'payload' => $payload, 'errors' => $errors,
                    'row_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
                ]);
            }
            $batch->forceFill(['status' => $invalid === 0 && $rows !== [] ? 'ready' : 'failed',
                'stats' => ['rows' => count($rows), 'valid' => count($rows) - $invalid, 'invalid' => $invalid, 'errors' => $invalid],
                'failed_at' => $invalid > 0 || $rows === [] ? now() : null,
                'failure_reason' => $rows === [] ? 'The manifest contains no data rows.' : ($invalid > 0 ? 'Manifest validation failed.' : null)])->save();
            $this->audit->log('hr.import.staged', (int) $actor->id, $batch, ['type' => $type, 'rows' => count($rows), 'invalid' => $invalid], $companyId);

            return $batch->fresh('rows');
        });
    }

    public function commit(HrImportBatch $batch, User $actor): HrImportBatch
    {
        $this->access->assertCompany($actor, (int) $batch->company_id, 'hr.imports.manage');
        if ($this->enumValue($batch->type) === 'full_history') {
            return $this->fullHistory->commit($batch, $actor);
        }
        $batch = DB::transaction(function () use ($batch): HrImportBatch {
            $claimed = HrImportBatch::query()->lockForUpdate()->findOrFail($batch->id);
            $status = $this->enumValue($claimed->status);
            if ($status === 'completed') {
                return $claimed;
            }
            if ($status !== 'ready') {
                throw ValidationException::withMessages(['import' => __('Only a validated import can be committed.')]);
            }
            $claimed->forceFill(['status' => 'committing', 'failed_at' => null, 'failure_reason' => null])->save();

            return $claimed;
        });
        if ($this->enumValue($batch->status) === 'completed') {
            return $batch;
        }
        $type = $this->enumValue($batch->type);
        $archivePath = null;

        try {
            if ($batch->archive_object_key) {
                $archivePath = $this->localCopy($batch->storage_disk, $batch->archive_object_key);
            }
            foreach ($batch->rows()->where('status', 'valid')->orderBy('row_number')->get() as $row) {
                DB::transaction(function () use ($batch, $row, $type, $actor, $archivePath): void {
                    $row = HrImportRow::query()->lockForUpdate()->findOrFail($row->id);
                    if ($row->status === 'committed') {
                        return;
                    }
                    [$targetType, $targetId] = $this->commitRow($batch, $row, $type, $actor, $archivePath);
                    $row->forceFill(['status' => 'committed', 'target_type' => $targetType, 'target_id' => $targetId])->save();
                });
            }
            $batch->forceFill(['status' => 'completed', 'committed_by' => $actor->id, 'committed_at' => now()])->save();
            $this->audit->log('hr.import.committed', (int) $actor->id, $batch, ['type' => $type, 'rows' => $batch->rows()->count()], (int) $batch->company_id);
        } catch (Throwable $exception) {
            $batch->forceFill(['status' => 'ready', 'failed_at' => now(), 'failure_reason' => Str::limit($exception->getMessage(), 1000, '')])->save();
            throw $exception;
        } finally {
            if ($archivePath && is_file($archivePath)) {
                @unlink($archivePath);
            }
        }

        return $batch->fresh('rows');
    }

    /** @return array{string,int} */
    private function commitRow(HrImportBatch $batch, HrImportRow $row, string $type, User $actor, ?string $archivePath): array
    {
        $payload = $row->payload;

        return match ($type) {
            'employees' => $this->commitEmployee($batch, $payload, $actor),
            'assignments' => $this->commitAssignment($batch, $payload, $actor),
            'documents' => $this->commitDocument($batch, $payload, $actor, $archivePath),
            'leave' => $this->commitLeave($batch, $payload, $actor),
            'payroll' => $this->commitPayroll($batch, $payload, $actor, $archivePath),
            default => throw new \LogicException('Unsupported import type.'),
        };
    }

    private function commitEmployee(HrImportBatch $batch, array $payload, User $actor): array
    {
        $legacyNumber = trim((string) ($payload['legacy_employee_number'] ?? ''));
        AccountingCompany::query()->lockForUpdate()->findOrFail($batch->company_id);
        $data = [...collect($payload)->except(['employee_number', 'legacy_employee_number'])->all(),
            'company_id' => $batch->company_id, 'created_by' => $actor->id, 'updated_by' => $actor->id];
        $employee = $legacyNumber === '' ? null : HrEmployee::query()
            ->where('company_id', $batch->company_id)
            ->where('metadata->legacy_employee_number', $legacyNumber)
            ->lockForUpdate()
            ->first();
        $employee = $employee
            ? $this->employees->update($employee, $data, (int) $actor->id)
            : ($legacyNumber === ''
                ? $this->employees->create($data, (int) $actor->id)
                : $this->employees->createImportedLegacy($data, $legacyNumber, (int) $actor->id));

        return [HrEmployee::class, (int) $employee->id];
    }

    private function commitAssignment(HrImportBatch $batch, array $payload, User $actor): array
    {
        $employee = $this->employee($batch, $payload);
        $assignment = $this->employees->assign($employee, $payload, (int) $actor->id);

        return [$assignment::class, (int) $assignment->id];
    }

    private function commitDocument(HrImportBatch $batch, array $payload, User $actor, ?string $archivePath): array
    {
        if (! $archivePath) {
            throw new \RuntimeException('Document archive is unavailable.');
        }
        $employee = $this->employee($batch, $payload);
        $type = HrDocumentType::query()->where('company_id', $batch->company_id)->where('code', $payload['document_type_code'])->firstOrFail();
        $temp = tempnam(sys_get_temp_dir(), 'hr-doc-');
        try {
            $this->archives->extractFile($archivePath, $payload['file'], $temp);
            $upload = new UploadedFile($temp, basename($payload['file']), null, null, true);
            $document = $this->documents->store($employee, $type, $upload, collect($payload)
                ->except(['employee_number', 'document_type_code', 'document_number', 'file'])->all(), $actor);

            return [$document::class, (int) $document->id];
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
    }

    private function commitLeave(HrImportBatch $batch, array $payload, User $actor): array
    {
        $employee = $this->employee($batch, $payload);
        $type = HrLeaveType::query()->where('company_id', $batch->company_id)->where('code', $payload['leave_type_code'])->firstOrFail();
        $entryType = strtolower((string) ($payload['entry_type'] ?? ''));
        if (in_array($entryType, ['opening', 'adjustment'], true)) {
            $days = (float) $payload['days'];
            $balance = (float) HrLeaveLedgerEntry::query()->where('employee_id', $employee->id)
                ->where('leave_type_id', $type->id)->lockForUpdate()->get(['days'])->sum('days') + $days;
            $entry = HrLeaveLedgerEntry::query()->create(['company_id' => $batch->company_id, 'employee_id' => $employee->id,
                'leave_type_id' => $type->id, 'entry_type' => $entryType, 'days' => $days,
                'effective_date' => $payload['effective_date'], 'source_type' => HrImportBatch::class, 'source_id' => $batch->id,
                'idempotency_key' => "import:{$batch->id}:leave-ledger:".hash('sha256', json_encode($payload)),
                'balance_after_days' => $balance, 'notes' => $payload['notes'] ?? 'Imported leave balance', 'created_by' => $actor->id]);

            return [$entry::class, (int) $entry->id];
        }
        $request = HrLeaveRequest::query()->create([...$payload, 'company_id' => $batch->company_id,
            'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'manager_id' => $employee->manager_id,
            'requested_days' => (float) $payload['requested_days'], 'status' => $payload['status'] ?? 'approved',
            'submitted_at' => $payload['submitted_at'] ?? now(), 'created_by' => $actor->id]);
        if (($payload['status'] ?? 'approved') === 'approved') {
            $days = -(float) $payload['requested_days'];
            $balance = (float) HrLeaveLedgerEntry::query()->where('employee_id', $employee->id)
                ->where('leave_type_id', $type->id)->lockForUpdate()->get(['days'])->sum('days') + $days;
            HrLeaveLedgerEntry::query()->create(['company_id' => $batch->company_id, 'employee_id' => $employee->id,
                'leave_type_id' => $type->id, 'leave_request_id' => $request->id, 'entry_type' => 'usage',
                'days' => $days, 'effective_date' => $payload['start_date'],
                'source_type' => HrImportRow::class, 'source_id' => $request->id,
                'idempotency_key' => "import:{$batch->id}:leave:{$request->id}",
                'balance_after_days' => $balance, 'created_by' => $actor->id]);
        }

        return [$request::class, (int) $request->id];
    }

    private function commitPayroll(HrImportBatch $batch, array $payload, User $actor, ?string $archivePath): array
    {
        $employee = $this->employee($batch, $payload);
        $start = Carbon::parse($payload['pay_period_start'])->startOfMonth();
        $end = Carbon::parse($payload['pay_period_end'] ?? $start->copy()->endOfMonth());
        AccountingCompany::query()->lockForUpdate()->findOrFail($batch->company_id);
        $run = HrPayrollRun::query()->where('company_id', $batch->company_id)->where('origin', 'migrated')
            ->whereDate('pay_period_start', $start->toDateString())->lockForUpdate()->first();
        if (! $run) {
            $run = HrPayrollRun::query()->create([
                'company_id' => $batch->company_id,
                'run_number' => $this->numbers->payrollRun((int) $batch->company_id, $start),
                'pay_period_start' => $start, 'pay_period_end' => $end, 'currency' => $payload['currency'] ?? config('hr.currency', 'QAR'),
                'origin' => 'migrated', 'status' => 'paid', 'is_postable' => false, 'proration_divisor' => config('hr.proration_divisor', 30),
                'description' => 'Migrated payroll history', 'prepared_by' => $actor->id, 'paid_at' => now(), 'paid_by' => $actor->id,
            ]);
        }
        $existing = HrPayrollResult::query()->where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->first();
        if ($existing) {
            return [$existing::class, (int) $existing->id];
        }
        $payslipDocumentId = null;
        if (filled($payload['file'] ?? null)) {
            if (! $archivePath) {
                throw new \RuntimeException('The payroll payslip archive is unavailable.');
            }
            $documentType = HrDocumentType::query()->firstOrCreate(
                ['company_id' => $batch->company_id, 'code' => 'payslip'],
                ['name' => 'Payslip', 'is_required' => false, 'is_sensitive' => true, 'is_active' => true]
            );
            $temp = tempnam(sys_get_temp_dir(), 'hr-payslip-');
            try {
                $this->archives->extractFile($archivePath, $payload['file'], $temp);
                $upload = new UploadedFile($temp, basename($payload['file']), null, null, true);
                $document = $this->documents->store($employee, $documentType, $upload, ['issue_date' => $end->toDateString()], $actor);
                $payslipDocumentId = (int) $document->id;
            } finally {
                if (is_file($temp)) {
                    @unlink($temp);
                }
            }
        }
        $gross = (int) $payload['gross_minor'];
        $net = (int) $payload['net_minor'];
        $result = HrPayrollResult::query()->firstOrCreate(['payroll_run_id' => $run->id, 'employee_id' => $employee->id], [
            'company_id' => $batch->company_id, 'branch_id' => $employee->current_branch_id,
            'department_id' => $employee->current_department_id, 'currency' => $run->currency,
            'basic_minor' => 0, 'gross_minor' => $gross, 'earnings_minor' => $gross,
            'deductions_minor' => max($gross - $net, 0), 'net_minor' => $net,
            'calendar_days' => $start->diffInDays($end) + 1, 'worked_days' => 0, 'unpaid_leave_days' => 0,
            'proration_divisor' => config('hr.proration_divisor', 30), 'snapshot' => ['import_batch_id' => $batch->id, 'component_detail_available' => false],
            'payslip_document_id' => $payslipDocumentId, 'is_postable' => false,
        ]);

        return [$result::class, (int) $result->id];
    }

    /** @return array<int, string> */
    private function validateRow(string $type, array $payload, int $companyId, array $archiveNames): array
    {
        $required = match ($type) {
            'employees' => ['legal_first_name', 'legal_last_name', 'display_name', 'hire_date', 'current_branch_id'],
            'assignments' => ['employee_number', 'branch_id', 'effective_from'],
            'documents' => ['employee_number', 'document_type_code', 'file'],
            'leave' => in_array(strtolower((string) ($payload['entry_type'] ?? '')), ['opening', 'adjustment'], true)
                ? ['employee_number', 'leave_type_code', 'entry_type', 'effective_date', 'days']
                : ['employee_number', 'leave_type_code', 'start_date', 'end_date', 'requested_days'],
            'payroll' => ['employee_number', 'pay_period_start', 'gross_minor', 'net_minor'],
        };
        $errors = [];
        foreach ($required as $field) {
            if (! filled($payload[$field] ?? null)) {
                $errors[$field] = "{$field} is required.";
            }
        }
        foreach (array_intersect(['hire_date', 'effective_from', 'effective_date', 'start_date', 'end_date', 'pay_period_start', 'pay_period_end'], array_keys($payload)) as $field) {
            if (filled($payload[$field]) && ! $this->isIsoDate((string) $payload[$field])) {
                $errors[$field] = "{$field} must use YYYY-MM-DD format.";
            }
        }
        foreach (array_intersect(['requested_days', 'days', 'gross_minor', 'net_minor'], array_keys($payload)) as $field) {
            if (filled($payload[$field]) && ! is_numeric($payload[$field])) {
                $errors[$field] = "{$field} must be numeric.";
            }
        }
        foreach (array_intersect(['gross_minor', 'net_minor'], array_keys($payload)) as $field) {
            if (filled($payload[$field]) && filter_var($payload[$field], FILTER_VALIDATE_INT) === false) {
                $errors[$field] = "{$field} must be an integer number of minor units.";
            }
        }
        $branchField = $type === 'employees' ? 'current_branch_id' : ($type === 'assignments' ? 'branch_id' : null);
        if ($branchField && filled($payload[$branchField] ?? null)
            && ! \App\Models\Branch::query()->whereKey((int) $payload[$branchField])->where('company_id', $companyId)->exists()) {
            $errors[$branchField] = "{$branchField} must identify a branch in this company.";
        }
        if ($type === 'documents' && filled($payload['file'] ?? null) && ! in_array($payload['file'], $archiveNames, true)) {
            $errors['file'] = 'Manifest file is missing from the archive.';
        }
        if ($type === 'payroll' && filled($payload['file'] ?? null) && ! in_array($payload['file'], $archiveNames, true)) {
            $errors['file'] = 'Payslip file is missing from the archive.';
        }
        if ($type === 'documents' && filled($payload['document_type_code'] ?? null)
            && ! HrDocumentType::query()->where('company_id', $companyId)->where('code', $payload['document_type_code'])->where('is_active', true)->exists()) {
            $errors['document_type_code'] = 'Document type does not exist in this company.';
        }
        if ($type === 'leave' && filled($payload['leave_type_code'] ?? null)
            && ! HrLeaveType::query()->where('company_id', $companyId)->where('code', $payload['leave_type_code'])->exists()) {
            $errors['leave_type_code'] = 'Leave type does not exist in this company.';
        }
        if ($type === 'leave' && is_numeric($payload['requested_days'] ?? null) && (float) $payload['requested_days'] <= 0) {
            $errors['requested_days'] = 'Requested days must be positive.';
        }
        if ($type === 'leave' && strtolower((string) ($payload['entry_type'] ?? '')) === 'opening' && is_numeric($payload['days'] ?? null) && (float) $payload['days'] < 0) {
            $errors['days'] = 'Opening balance cannot be negative.';
        }
        if ($type === 'leave' && strtolower((string) ($payload['entry_type'] ?? '')) === 'adjustment' && is_numeric($payload['days'] ?? null) && abs((float) $payload['days']) < 0.001) {
            $errors['days'] = 'Adjustment cannot be zero.';
        }
        if ($type === 'leave' && $this->isIsoDate((string) ($payload['start_date'] ?? '')) && $this->isIsoDate((string) ($payload['end_date'] ?? ''))
            && $payload['end_date'] < $payload['start_date']) {
            $errors['end_date'] = 'Leave end date must not precede start date.';
        }
        if ($type === 'payroll' && is_numeric($payload['gross_minor'] ?? null) && is_numeric($payload['net_minor'] ?? null)
            && ((int) $payload['gross_minor'] < 0 || (int) $payload['net_minor'] < 0 || (int) $payload['net_minor'] > (int) $payload['gross_minor'])) {
            $errors['net_minor'] = 'Payroll totals must satisfy 0 <= net <= gross.';
        }
        if ($type !== 'employees' && filled($payload['employee_number'] ?? null)
            && ! HrEmployee::query()->where('company_id', $companyId)->where('employee_number', $payload['employee_number'])->exists()) {
            $errors['employee_number'] = 'Employee does not exist in this company.';
        }

        return $errors;
    }

    private function employee(HrImportBatch $batch, array $payload): HrEmployee
    {
        return HrEmployee::query()->where('company_id', $batch->company_id)->where('employee_number', $payload['employee_number'])->firstOrFail();
    }

    private function sourceIdentifier(string $type, array $payload): ?string
    {
        if (filled($payload['source_identifier'] ?? null)) {
            return Str::limit((string) $payload['source_identifier'], 191, '');
        }
        $employee = $type === 'employees'
            ? ($payload['legacy_employee_number'] ?? null)
            : ($payload['employee_number'] ?? null);
        if (! $employee) {
            if ($type !== 'employees') {
                return null;
            }

            $identity = collect($payload)->only([
                'work_email', 'legal_first_name', 'legal_middle_name', 'legal_last_name', 'hire_date',
            ])->all();

            return 'employee:'.hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
        }

        return Str::limit(match ($type) {
            'assignments' => $employee.':'.($payload['effective_from'] ?? ''),
            'documents' => $employee.':'.($payload['document_type_code'] ?? '').':'.($payload['file'] ?? ''),
            'leave' => $employee.':'.($payload['leave_type_code'] ?? '').':'.($payload['entry_type'] ?? 'request').':'.($payload['start_date'] ?? $payload['effective_date'] ?? ''),
            'payroll' => $employee.':'.($payload['pay_period_start'] ?? ''),
            default => (string) $employee,
        }, 191, '');
    }

    private function assertUpload(UploadedFile $file, array $mimes): void
    {
        if (! $file->isValid() || ! is_file($file->getRealPath())) {
            throw ValidationException::withMessages(['file' => __('The import upload is invalid.')]);
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        if (! in_array($mime, $mimes, true)) {
            throw ValidationException::withMessages(['file' => __('The import upload has an invalid file type.')]);
        }
    }

    private function localCopy(string $disk, string $key): string
    {
        $path = tempnam(sys_get_temp_dir(), 'hr-import-');
        $source = Storage::disk($disk)->readStream($key);
        $target = fopen($path, 'wb');
        if (! is_resource($source) || ! is_resource($target)) {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($target)) {
                fclose($target);
            }
            throw new \RuntimeException('The private import archive cannot be opened.');
        }
        try {
            if (stream_copy_to_stream($source, $target) === false) {
                throw new \RuntimeException('The private import archive cannot be copied.');
            }
        } finally {
            fclose($source);
            fclose($target);
        }

        return $path;
    }

    private function enumValue(mixed $value): string
    {
        return is_object($value) ? $value->value : (string) $value;
    }

    private function isIsoDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
