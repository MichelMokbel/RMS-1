<?php

namespace App\Services\AP;

use App\Models\ApInvoice;
use App\Models\ApInvoiceAttachment;
use App\Services\Accounting\AccountingAuditLogService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ApInvoiceAttachmentService
{
    public function __construct(
        protected AccountingAuditLogService $auditLog
    ) {
    }

    public function upload(ApInvoice $invoice, UploadedFile $file, ?int $userId = null): ApInvoiceAttachment
    {
        $invoice->loadMissing('period');
        $this->assertCanMutateAttachments($invoice);

        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
        if (! in_array(strtolower($file->getClientOriginalExtension()), $allowed, true)) {
            throw ValidationException::withMessages(['file' => __('Only images or PDF allowed.')]);
        }

        $maxKb = (int) config('expenses.max_attachment_kb', 7096);
        if ($file->getSize() / 1024 > $maxKb) {
            throw ValidationException::withMessages(['file' => __('File too large.')]);
        }

        $path = $file->storeAs(
            'ap-invoices/'.$invoice->id,
            Str::uuid().'.'.$file->getClientOriginalExtension(),
            'public'
        );

        $attachment = ApInvoiceAttachment::create([
            'invoice_id' => $invoice->id,
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'uploaded_by' => $userId,
        ]);

        if (! $invoice->isDraft()) {
            $this->auditLog->log('ap_invoice.attachment_uploaded', $userId, $invoice, [
                'attachment_id' => (int) $attachment->id,
                'original_name' => $attachment->original_name,
            ], (int) ($invoice->company_id ?? 0) ?: null);
        }

        return $attachment;
    }

    public function delete(ApInvoiceAttachment $attachment, ?int $userId = null): void
    {
        $attachment->loadMissing('invoice.period');
        $invoice = $attachment->invoice;

        if ($invoice) {
            $this->assertCanMutateAttachments($invoice);
        }

        $payload = [
            'attachment_id' => (int) $attachment->id,
            'original_name' => $attachment->original_name,
        ];

        if ($attachment->file_path && Storage::disk('public')->exists($attachment->file_path)) {
            Storage::disk('public')->delete($attachment->file_path);
        }

        $attachment->delete();

        if ($invoice && ! $invoice->isDraft()) {
            $this->auditLog->log('ap_invoice.attachment_deleted', $userId, $invoice, $payload, (int) ($invoice->company_id ?? 0) ?: null);
        }
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
