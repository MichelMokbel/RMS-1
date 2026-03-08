<?php

namespace App\Services\AP;

use App\Models\ApInvoice;
use App\Models\ApInvoiceAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ApInvoiceAttachmentService
{
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

        $path = $file->storeAs(
            'ap-invoices/'.$invoice->id,
            Str::uuid().'.'.$file->getClientOriginalExtension(),
            'public'
        );

        return ApInvoiceAttachment::create([
            'invoice_id' => $invoice->id,
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'uploaded_by' => $userId,
        ]);
    }

    public function delete(ApInvoiceAttachment $attachment): void
    {
        if ($attachment->file_path && Storage::disk('public')->exists($attachment->file_path)) {
            Storage::disk('public')->delete($attachment->file_path);
        }

        $attachment->delete();
    }
}
