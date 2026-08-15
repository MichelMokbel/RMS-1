<?php

namespace App\Services\HR\Imports;

use App\Models\HrImportBatch;
use App\Models\HrImportRow;
use App\Models\User;
use App\Services\HR\HrAccessService;
use App\Services\HR\HrAuditLogService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FullHistoryImportService
{
    public function __construct(
        protected SafeSpreadsheetReader $reader,
        protected FullHistoryValidator $validator,
        protected FullHistoryCommitter $committer,
        protected HrAccessService $access,
        protected HrAuditLogService $audit,
    ) {}

    public function stage(UploadedFile $manifest, int $companyId, User $actor): HrImportBatch
    {
        $this->access->assertCompany($actor, $companyId, 'hr.imports.manage');
        $hash = hash_file('sha256', $manifest->getRealPath());
        $existing = HrImportBatch::query()->where('company_id', $companyId)->where('type', 'full_history')->where('sha256', $hash)->first();
        if ($existing) {
            return $existing->load('rows');
        }

        $workbook = $this->reader->workbook($manifest->getRealPath());
        $sheets = $workbook['sheets'];
        $validated = $this->validator->validate($sheets, $companyId, $workbook['headers']);
        $disk = (string) config('hr.documents.disk', 'local');
        $root = "hr/{$companyId}/imports";
        $objectKey = $root.'/'.Str::uuid().'.xlsx';
        $stored = Storage::disk($disk)->putFileAs($root, $manifest, basename($objectKey), ['visibility' => 'private']);
        if ($stored !== $objectKey) {
            throw ValidationException::withMessages(['manifest' => __('The consolidated workbook could not be stored securely.')]);
        }

        try {
            return DB::transaction(function () use ($manifest, $companyId, $actor, $hash, $disk, $objectKey, $validated, $sheets): HrImportBatch {
                $invalid = (int) $validated['stats']['invalid'];
                $batch = HrImportBatch::query()->create([
                    'company_id' => $companyId, 'type' => 'full_history', 'status' => 'validating',
                    'source_name' => Str::limit(basename($manifest->getClientOriginalName()), 255, ''),
                    'storage_disk' => $disk, 'object_key' => $objectKey, 'sha256' => $hash,
                    'options' => ['data_sheets' => array_keys(FullHistorySchema::HEADERS), 'workbook_sheets' => array_keys($sheets), 'atomic' => true],
                    'stats' => $validated['stats'], 'initiated_by' => $actor->id, 'initiated_at' => now(),
                ]);
                foreach ($validated['rows'] as $offset => $entry) {
                    HrImportRow::query()->create([
                        'import_batch_id' => $batch->id, 'row_number' => $offset + 1,
                        'source_identifier' => Str::limit((string) ($entry['identifier'] ?? ''), 191, ''),
                        'status' => $entry['errors'] === [] ? 'valid' : 'invalid',
                        'payload' => $entry['payload'], 'errors' => $entry['errors'],
                        'row_hash' => hash('sha256', json_encode($entry['payload'], JSON_THROW_ON_ERROR)),
                    ]);
                }
                $batch->forceFill([
                    'status' => $invalid === 0 ? 'ready' : 'failed',
                    'failed_at' => $invalid === 0 ? null : now(),
                    'failure_reason' => $invalid === 0 ? null : 'Consolidated workbook validation failed.',
                ])->save();
                $this->audit->log('hr.import.full_history_staged', (int) $actor->id, $batch, [
                    'rows' => $validated['stats']['rows'], 'invalid' => $invalid, 'sheet_count' => count(FullHistorySchema::HEADERS),
                ], $companyId);

                return $batch->fresh('rows');
            });
        } catch (\Throwable $exception) {
            Storage::disk($disk)->delete($objectKey);
            throw $exception;
        }
    }

    public function commit(HrImportBatch $batch, User $actor): HrImportBatch
    {
        return $this->committer->commit($batch, $actor);
    }
}
