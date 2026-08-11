<?php

namespace App\Services\AP;

use App\Models\ApInvoice;
use App\Models\ApInvoiceAttachment;
use App\Services\Accounting\AccountingAuditLogService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ApInvoiceAttachmentService
{
    private const DISK = 's3';

    public function __construct(
        protected AccountingAuditLogService $auditLog
    ) {}

    public function upload(ApInvoice $invoice, UploadedFile $file, ?int $userId = null): ApInvoiceAttachment
    {
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
        if (! in_array(strtolower($file->getClientOriginalExtension()), $allowed, true)) {
            throw ValidationException::withMessages(['file' => __('Only images or PDF allowed.')]);
        }

        $maxKb = (int) config('expenses.max_attachment_kb', 7096);
        if ($file->getSize() / 1024 > $maxKb) {
            throw ValidationException::withMessages(['file' => __('File too large.')]);
        }

        $storedPath = null;

        try {
            return DB::transaction(function () use ($invoice, $file, $userId, &$storedPath): ApInvoiceAttachment {
                $lockedInvoice = ApInvoice::query()
                    ->with('period')
                    ->whereKey($invoice->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $this->assertCanMutateAttachments($lockedInvoice);

                $storedPath = $file->storeAs(
                    'ap-invoices/'.$lockedInvoice->id,
                    Str::uuid().'.'.$file->getClientOriginalExtension(),
                    self::DISK
                );
                if (! is_string($storedPath) || $storedPath === '') {
                    throw ValidationException::withMessages([
                        'file' => __('The attachment could not be stored.'),
                    ]);
                }

                $attachment = ApInvoiceAttachment::query()->create([
                    'invoice_id' => $lockedInvoice->id,
                    'file_path' => $storedPath,
                    'original_name' => $file->getClientOriginalName(),
                    'uploaded_by' => $userId,
                ]);

                if (! $lockedInvoice->isDraft()) {
                    $this->auditLog->log('ap_invoice.attachment_uploaded', $userId, $lockedInvoice, [
                        'attachment_id' => (int) $attachment->id,
                        'original_name' => $attachment->original_name,
                    ], (int) ($lockedInvoice->company_id ?? 0) ?: null);
                }

                return $attachment;
            });
        } catch (Throwable $exception) {
            $this->cleanupCopiedFiles(array_filter([$storedPath]));

            throw $exception;
        }
    }

    public function delete(ApInvoiceAttachment $attachment, ?int $userId = null): void
    {
        $filePath = DB::transaction(function () use ($attachment, $userId): ?string {
            $invoiceId = (int) $attachment->invoice_id;
            $invoice = ApInvoice::query()
                ->with('period')
                ->whereKey($invoiceId)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertCanMutateAttachments($invoice);

            $lockedAttachment = ApInvoiceAttachment::query()
                ->whereKey($attachment->id)
                ->where('invoice_id', $invoiceId)
                ->lockForUpdate()
                ->firstOrFail();
            $payload = [
                'attachment_id' => (int) $lockedAttachment->id,
                'original_name' => $lockedAttachment->original_name,
            ];
            $path = $lockedAttachment->file_path;

            $lockedAttachment->delete();

            if (! $invoice->isDraft()) {
                $this->auditLog->log('ap_invoice.attachment_deleted', $userId, $invoice, $payload, (int) ($invoice->company_id ?? 0) ?: null);
            }

            return $path;
        });

        if ($filePath) {
            $this->cleanupCopiedFiles([$filePath]);
        }
    }

    public function assertRevisionSourcesAvailable(ApInvoice $source): void
    {
        $source->loadMissing('attachments');
        $disk = Storage::disk(self::DISK);

        foreach ($source->attachments as $attachment) {
            $sourcePath = $this->validatedRevisionSourcePath($source, $attachment);
            if (! $disk->exists($sourcePath)) {
                throw ValidationException::withMessages([
                    'attachment' => __('Attachment :name is missing from storage.', [
                        'name' => $attachment->original_name ?: basename($sourcePath),
                    ]),
                ]);
            }
        }
    }

    /**
     * Copy the source invoice's evidence into independent objects owned by a revision.
     *
     * @return Collection<int, ApInvoiceAttachment>
     */
    public function copyToRevision(ApInvoice $source, ApInvoice $revision, ?int $userId = null): Collection
    {
        $source->loadMissing('attachments');
        $copiedPaths = [];

        try {
            return DB::transaction(function () use ($source, $revision, $userId, &$copiedPaths): Collection {
                $copies = collect();
                $mapping = [];
                $disk = Storage::disk(self::DISK);

                foreach ($source->attachments as $attachment) {
                    $sourcePath = $this->validatedRevisionSourcePath($source, $attachment);
                    if (! $disk->exists($sourcePath)) {
                        throw ValidationException::withMessages([
                            'attachment' => __('Attachment :name is missing from storage.', [
                                'name' => $attachment->original_name ?: basename($sourcePath),
                            ]),
                        ]);
                    }

                    $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
                    $destinationPath = 'ap-invoices/'.$revision->id.'/'.Str::uuid().'.'.$extension;

                    if (! $disk->copy($sourcePath, $destinationPath)) {
                        throw ValidationException::withMessages([
                            'attachment' => __('Attachment :name could not be copied to the revision.', [
                                'name' => $attachment->original_name ?: basename($sourcePath),
                            ]),
                        ]);
                    }

                    $copiedPaths[] = $destinationPath;
                    $copy = ApInvoiceAttachment::query()->create([
                        'invoice_id' => $revision->id,
                        'file_path' => $destinationPath,
                        'original_name' => $attachment->original_name,
                        'uploaded_by' => $attachment->uploaded_by,
                    ]);
                    $copies->push($copy);
                    $mapping[] = [
                        'source_attachment_id' => (int) $attachment->id,
                        'revision_attachment_id' => (int) $copy->id,
                        'original_name' => $copy->original_name,
                    ];
                }

                if ($mapping !== []) {
                    $this->auditLog->log('ap_invoice.revision_attachments_copied', $userId, $revision, [
                        'source_invoice_id' => (int) $source->id,
                        'revision_invoice_id' => (int) $revision->id,
                        'attachments' => $mapping,
                    ], (int) ($revision->company_id ?? 0) ?: null);
                }

                return $copies;
            });
        } catch (Throwable $exception) {
            $this->cleanupCopiedFiles($copiedPaths);

            throw $exception;
        }
    }

    /**
     * @param  iterable<int, string>  $paths
     */
    public function cleanupCopiedFiles(iterable $paths): void
    {
        $disk = Storage::disk(self::DISK);

        foreach ($paths as $path) {
            try {
                if (is_string($path) && $path !== '' && $disk->exists($path)) {
                    $disk->delete($path);
                }
            } catch (Throwable $cleanupException) {
                report($cleanupException);
            }
        }
    }

    private function validatedRevisionSourcePath(ApInvoice $source, ApInvoiceAttachment $attachment): string
    {
        $path = trim((string) $attachment->file_path);
        $expectedPrefix = 'ap-invoices/'.$source->id.'/';
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($path === ''
            || ! Str::startsWith($path, $expectedPrefix)
            || Str::contains($path, ['../', '..\\'])
            || ! in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)) {
            throw ValidationException::withMessages([
                'attachment' => __('Attachment :name has an invalid storage path.', [
                    'name' => $attachment->original_name ?: __('Unknown attachment'),
                ]),
            ]);
        }

        return $path;
    }

    private function assertCanMutateAttachments(ApInvoice $invoice): void
    {
        if (! $invoice->canMutateAttachments()) {
            throw ValidationException::withMessages([
                'attachment' => $invoice->isVoid()
                    ? __('Attachments cannot be changed on void documents.')
                    : __('This document is finalized because its accounting period is closed.'),
            ]);
        }
    }
}
