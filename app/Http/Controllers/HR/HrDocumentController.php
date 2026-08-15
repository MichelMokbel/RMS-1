<?php

namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Models\HrDocument;
use App\Services\HR\EmployeeDocumentService;
use App\Services\HR\HrAuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HrDocumentController extends Controller
{
    public function preview(Request $request, HrDocument $document, EmployeeDocumentService $documents, HrAuditLogService $audit): StreamedResponse
    {
        $version = $document->currentVersion;
        abort_unless($version, 404);
        $file = $documents->download($version, $request->user());
        $audit->log('hr.document.previewed', (int) $request->user()->id, $document, ['version_id' => (int) $version->id], (int) $document->company_id);

        return Storage::disk($file['disk'])->response($file['path'], $file['name'], $this->headers($file['mime_type']), 'inline');
    }

    public function download(Request $request, HrDocument $document, EmployeeDocumentService $documents): StreamedResponse
    {
        $version = $document->currentVersion;
        abort_unless($version, 404);
        $file = $documents->download($version, $request->user());

        return Storage::disk($file['disk'])->download($file['path'], $file['name'], $this->headers($file['mime_type']));
    }

    /** @return array<string, string> */
    private function headers(string $mime): array
    {
        return [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox",
        ];
    }
}
