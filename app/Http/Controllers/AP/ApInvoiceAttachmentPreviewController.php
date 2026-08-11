<?php

namespace App\Http\Controllers\AP;

use App\Http\Controllers\Controller;
use App\Models\ApInvoice;
use App\Models\ApInvoiceAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ApInvoiceAttachmentPreviewController extends Controller
{
    public function __invoke(
        Request $request,
        ApInvoice $invoice,
        ApInvoiceAttachment $attachment
    ): StreamedResponse {
        abort_unless((int) $attachment->invoice_id === (int) $invoice->id, 404);

        $disk = Storage::disk('s3');
        abort_unless($attachment->file_path && $disk->exists($attachment->file_path), 404);

        $extension = strtolower(pathinfo($attachment->file_path, PATHINFO_EXTENSION));
        abort_unless(in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true), 415);

        $mimeType = match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
        };

        return $disk->response(
            $attachment->file_path,
            $attachment->original_name ?: basename($attachment->file_path),
            [
                'Content-Type' => $mimeType,
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
            'inline'
        );
    }
}
