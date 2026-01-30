<?php

namespace App\Support\Reports;

use Symfony\Component\HttpFoundation\StreamedResponse;

class CsvExport
{
    /**
     * Stream a CSV response. Headers and rows should be arrays of scalar values.
     *
     * @param  array<int, string>  $headers
     * @param  iterable<int, array<int, string|int|float|null>>  $rows
     * @param  string  $filename
     * @return StreamedResponse
     */
    public static function stream(array $headers, iterable $rows, string $filename = 'report.csv'): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.addslashes($filename).'"',
        ]);

        return $response;
    }
}
