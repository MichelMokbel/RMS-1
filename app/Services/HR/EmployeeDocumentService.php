<?php

namespace App\Services\HR;

use App\Models\HrDocument;
use App\Models\HrDocumentType;
use App\Models\HrDocumentVersion;
use App\Models\HrEmployee;
use App\Models\User;
use App\Services\HR\Documents\ConfiguredDocumentScanner;
use App\Services\HR\Documents\DocumentScanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class EmployeeDocumentService
{
    public function __construct(
        protected HrAuditLogService $audit,
        protected HrAccessService $access,
        protected HrNumberService $numbers,
        protected ?DocumentScanner $scanner = null,
    ) {}

    /** @param array<string, mixed> $metadata */
    public function store(
        HrEmployee $employee,
        HrDocumentType $type,
        UploadedFile $file,
        array $metadata,
        User $actor,
    ): HrDocument {
        $this->access->assertEmployee($actor, $employee, 'hr.documents.manage');
        $this->assertTypeScope($employee, $type);

        $document = DB::transaction(function () use ($employee, $type, $metadata): HrDocument {
            unset($metadata['document_number']);

            return HrDocument::query()->create([
                ...$metadata,
                'company_id' => $employee->company_id,
                'employee_id' => $employee->id,
                'document_type_id' => $type->id,
                'document_number' => $this->numbers->document((int) $employee->company_id),
                'status' => 'pending',
            ]);
        });

        $this->addVersion($document, $file, $actor);

        return $document->fresh(['currentVersion', 'documentType']);
    }

    public function addVersion(HrDocument $document, UploadedFile $file, User $actor): HrDocumentVersion
    {
        $this->access->assertDocument($actor, $document, 'hr.documents.manage');
        [$absolutePath, $mime, $size, $sha256] = $this->inspectUpload($file);
        try {
            $scan = ($this->scanner ?? new ConfiguredDocumentScanner)->scan($absolutePath);
        } catch (Throwable) {
            $document->forceFill(['status' => 'rejected'])->save();
            $this->audit->log('hr.document.upload_rejected', (int) $actor->id, $document, [
                'reason' => 'scanner_unavailable',
            ], (int) $document->company_id);

            throw ValidationException::withMessages([
                'file' => __('The document security scanner is unavailable. The file was not stored.'),
            ]);
        }
        $disk = (string) config('hr.documents.disk', 'local');
        $root = 'hr/'.(int) $document->company_id.'/documents/'.(int) $document->id;
        $extension = $this->extensionForMime($mime);
        $objectKey = $root.'/'.Str::uuid().'.'.$extension;
        $this->assertSafeObjectKey($objectKey, $root);

        if (! $scan->clean) {
            $quarantine = 'hr-quarantine/'.Str::uuid();
            Storage::disk($disk)->putFileAs(dirname($quarantine), $file, basename($quarantine), ['visibility' => 'private']);
            $document->forceFill(['status' => 'rejected'])->save();
            $this->audit->log('hr.document.upload_rejected', (int) $actor->id, $document, [
                'reason' => 'malware_detected', 'scanner' => $scan->engine,
            ], (int) $document->company_id);
            throw ValidationException::withMessages(['file' => __('The file failed the security scan and was quarantined.')]);
        }

        $stored = Storage::disk($disk)->putFileAs($root, $file, basename($objectKey), ['visibility' => 'private']);
        if (! $stored || $stored !== $objectKey) {
            throw ValidationException::withMessages(['file' => __('The document could not be stored securely.')]);
        }

        try {
            return DB::transaction(function () use ($document, $file, $actor, $disk, $objectKey, $mime, $size, $sha256, $scan): HrDocumentVersion {
                $document = HrDocument::query()->lockForUpdate()->findOrFail($document->id);
                $versionNumber = (int) HrDocumentVersion::query()->where('document_id', $document->id)->max('version_number') + 1;

                $version = HrDocumentVersion::query()->create([
                    'company_id' => $document->company_id,
                    'document_id' => $document->id,
                    'version_number' => $versionNumber,
                    'storage_disk' => $disk,
                    'object_key' => $objectKey,
                    'original_name' => Str::limit(
                        preg_replace('/[^\pL\pN._ -]+/u', '_', basename($file->getClientOriginalName())) ?: 'document.'.$this->extensionForMime($mime),
                        255,
                        '',
                    ),
                    'mime_type' => $mime,
                    'size_bytes' => $size,
                    'sha256' => $sha256,
                    'scan_status' => 'clean',
                    'scan_metadata' => ['engine' => $scan->engine],
                    'uploaded_by' => $actor->id,
                ]);

                $document->forceFill([
                    'current_version_id' => $version->id,
                    'status' => $this->documentStatus($document),
                ])->save();

                $this->audit->log('hr.document.version_uploaded', (int) $actor->id, $document, [
                    'version_id' => (int) $version->id,
                    'version_number' => $versionNumber,
                    'mime_type' => $mime,
                    'size_bytes' => $size,
                ], (int) $document->company_id);

                return $version;
            });
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($objectKey);
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    public function metadata(HrDocument $document, User $actor): array
    {
        $this->access->assertDocument($actor, $document, 'hr.documents.view');
        $document->loadMissing(['documentType', 'currentVersion']);
        $number = (string) ($document->document_number ?? '');

        $this->audit->log('hr.document.metadata_viewed', (int) $actor->id, $document, [], (int) $document->company_id);

        return [
            'id' => (int) $document->id,
            'employee_id' => (int) $document->employee_id,
            'type' => $document->documentType?->name,
            'document_number_masked' => $number === '' ? null : str_repeat('*', max(strlen($number) - 4, 4)).substr($number, -4),
            'issue_date' => optional($document->issue_date)->toDateString(),
            'expiry_date' => optional($document->expiry_date)->toDateString(),
            'status' => is_object($document->status) ? $document->status->value : $document->status,
            'version' => $document->currentVersion ? [
                'id' => (int) $document->currentVersion->id,
                'number' => (int) $document->currentVersion->version_number,
                'name' => $document->currentVersion->original_name,
                'mime_type' => $document->currentVersion->mime_type,
                'size_bytes' => (int) $document->currentVersion->size_bytes,
            ] : null,
        ];
    }

    /** @return array{disk:string,path:string,name:string,mime_type:string} */
    public function download(HrDocumentVersion $version, User $actor): array
    {
        $version->loadMissing('document');
        $document = $version->document;
        $this->access->assertDocument($actor, $document, 'hr.documents.download');
        $root = 'hr/'.(int) $document->company_id.'/documents/'.(int) $document->id;
        $this->assertSafeObjectKey((string) $version->object_key, $root);

        if ($version->scan_status !== 'clean' || ! Storage::disk($version->storage_disk)->exists($version->object_key)) {
            throw ValidationException::withMessages(['document' => __('The requested document is unavailable.')]);
        }
        if (! $this->checksumMatches($version)) {
            $this->audit->log('hr.document.integrity_failed', (int) $actor->id, $document, ['version_id' => (int) $version->id], (int) $document->company_id);
            throw ValidationException::withMessages(['document' => __('The document failed its integrity check.')]);
        }

        $this->audit->log('hr.document.downloaded', (int) $actor->id, $document, [
            'version_id' => (int) $version->id,
        ], (int) $document->company_id);

        return [
            'disk' => (string) $version->storage_disk,
            'path' => (string) $version->object_key,
            'name' => (string) $version->original_name,
            'mime_type' => (string) $version->mime_type,
        ];
    }

    /** @return array{string,string,int,string} */
    private function inspectUpload(UploadedFile $file): array
    {
        if (! $file->isValid() || ! is_file($file->getRealPath()) || is_link($file->getRealPath())) {
            throw ValidationException::withMessages(['file' => __('The uploaded document is invalid.')]);
        }
        $size = (int) filesize($file->getRealPath());
        $max = max((int) config('hr.documents.max_size_kb', 10_240), 1) * 1024;
        if ($size <= 0 || $size > $max) {
            throw ValidationException::withMessages(['file' => __('The document exceeds the allowed size.')]);
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        $allowed = config('hr.documents.allowed_mime_types', ['application/pdf', 'image/jpeg', 'image/png']);
        if (! in_array($mime, $allowed, true)) {
            throw ValidationException::withMessages(['file' => __('This document type is not allowed.')]);
        }

        return [$file->getRealPath(), $mime, $size, hash_file('sha256', $file->getRealPath())];
    }

    private function assertTypeScope(HrEmployee $employee, HrDocumentType $type): void
    {
        if ((int) $type->company_id !== (int) $employee->company_id || ! $type->is_active) {
            throw ValidationException::withMessages(['document_type_id' => __('The document type is not available for this company.')]);
        }
    }

    private function assertSafeObjectKey(string $key, string $root): void
    {
        if (! str_starts_with($key, $root.'/') || str_contains($key, '..') || str_contains($key, '\\') || str_starts_with($key, '/')) {
            throw ValidationException::withMessages(['document' => __('The stored document path is invalid.')]);
        }
    }

    private function extensionForMime(string $mime): string
    {
        return match ($mime) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => 'bin',
        };
    }

    private function documentStatus(HrDocument $document): string
    {
        return $document->expiry_date && now()->startOfDay()->gt($document->expiry_date) ? 'expired' : 'valid';
    }

    private function checksumMatches(HrDocumentVersion $version): bool
    {
        $stream = Storage::disk($version->storage_disk)->readStream($version->object_key);
        if (! is_resource($stream)) {
            return false;
        }
        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);

            return hash_equals((string) $version->sha256, hash_final($context));
        } finally {
            fclose($stream);
        }
    }
}
