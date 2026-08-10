<?php

namespace App\Services\Quotations\Rendering;

use RuntimeException;

final class QuotationPdfRenderer
{
    public function __construct(
        private readonly QuotationSnapshot $snapshot,
        private readonly QuotationHtmlRenderer $html,
    ) {}

    public function render(array $snapshot): RenderedDocument
    {
        if (! class_exists(\Mpdf\Mpdf::class)) {
            throw new RuntimeException('PDF generation requires mpdf/mpdf ^8.2.');
        }

        $document = $this->snapshot->normalize($snapshot);
        $margins = $document['settings']['margins'];
        $tempDir = storage_path('framework/cache/mpdf');
        if (! is_dir($tempDir) && ! mkdir($tempDir, 0750, true) && ! is_dir($tempDir)) {
            throw new RuntimeException('Unable to create the mPDF temporary directory.');
        }

        $pdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'orientation' => 'P',
            'margin_top' => $margins['top'],
            'margin_right' => $margins['right'],
            'margin_bottom' => $margins['bottom'],
            'margin_left' => $margins['left'],
            'default_font' => 'dejavusans',
            'tempDir' => $tempDir,
        ]);
        $pdf->autoScriptToLang = true;
        $pdf->autoLangToFont = true;
        $pdf->SetTitle((string) ($document['quotation']['number'] ?? __('Quotation')));
        $pdf->WriteHTML($this->html->render($snapshot));

        return new RenderedDocument(
            format: 'pdf',
            mimeType: 'application/pdf',
            extension: 'pdf',
            contents: $pdf->Output('', \Mpdf\Output\Destination::STRING_RETURN),
        );
    }
}
