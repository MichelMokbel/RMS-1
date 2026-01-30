<?php

namespace App\Support\Reports;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\View\View;

class PdfExport
{
    /**
     * Generate a PDF download from a view and data.
     *
     * @param  string  $view  Blade view name
     * @param  array  $data  View data
     * @param  string  $filename  Download filename
     * @param  string  $paper  Paper size (e.g. A4, letter)
     * @param  string  $orientation  portrait|landscape
     * @return Response
     */
    public static function download(string $view, array $data, string $filename = 'report.pdf', string $paper = 'a4', string $orientation = 'portrait'): Response
    {
        $pdf = Pdf::loadView($view, $data);
        $pdf->setPaper($paper, $orientation);

        return $pdf->download($filename);
    }

    /**
     * Generate PDF binary from a view and data (e.g. for streaming or storage).
     *
     * @param  string  $view  Blade view name
     * @param  array  $data  View data
     * @param  string  $paper  Paper size
     * @param  string  $orientation  portrait|landscape
     * @return string
     */
    public static function output(string $view, array $data, string $paper = 'a4', string $orientation = 'portrait'): string
    {
        $pdf = Pdf::loadView($view, $data);
        $pdf->setPaper($paper, $orientation);

        return $pdf->output();
    }
}
