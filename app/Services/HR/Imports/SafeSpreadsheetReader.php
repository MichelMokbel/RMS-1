<?php

namespace App\Services\HR\Imports;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

class SafeSpreadsheetReader
{
    private const MAX_ARCHIVE_ENTRIES = 5000;

    private const MAX_EXPANDED_BYTES = 104_857_600;

    private const MAX_ROWS_PER_SHEET = 50_001;

    private const MAX_COLUMNS_PER_SHEET = 256;

    private const MAX_TOTAL_CELLS = 2_000_000;

    /** @return array<int, array<string, mixed>> */
    public function rows(string $path): array
    {
        $sheets = $this->sheets($path);

        return $sheets === [] ? [] : array_values($sheets)[0];
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    public function sheets(string $path): array
    {
        return $this->workbook($path)['sheets'];
    }

    /** @return array<string, array<int, string>> */
    public function headers(string $path): array
    {
        return $this->workbook($path)['headers'];
    }

    /** @return array{sheets:array<string,array<int,array<string,mixed>>>,headers:array<string,array<int,string>>} */
    public function workbook(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('The XLSX file cannot be opened.');
        }

        try {
            $this->assertSafeEntries($zip);
            $this->assertSafeRelationships($zip);
            $shared = $this->sharedStrings($zip);
            $dateStyles = $this->dateStyles($zip);
            [$worksheets, $date1904] = $this->worksheets($zip);
            $result = [];
            $headers = [];
            $totalCells = 0;

            foreach ($worksheets as $worksheet) {
                $key = $this->normalize((string) $worksheet['name']);
                if ($key === '' || array_key_exists($key, $result)) {
                    throw new RuntimeException('Worksheet names must be unique after normalization.');
                }
                $content = $zip->getFromName((string) $worksheet['path']);
                if ($content === false) {
                    throw new RuntimeException("Worksheet [{$worksheet['name']}] is missing from the XLSX archive.");
                }
                $parsed = $this->sheetRows($content, $shared, $dateStyles, $date1904, $totalCells);
                $result[$key] = $parsed['rows'];
                $headers[$key] = $parsed['headers'];
            }

            return ['sheets' => $result, 'headers' => $headers];
        } finally {
            $zip->close();
        }
    }

    /**
     * @param  array<int, string>  $shared
     * @param  array<int, true>  $dateStyles
     * @return array{headers:array<int,string>,rows:array<int,array<string,mixed>>}
     */
    private function sheetRows(string $content, array $shared, array $dateStyles, bool $date1904, int &$totalCells): array
    {
        $xml = $this->spreadsheetChildren($this->xml($content));
        $matrix = [];
        $rowCount = 0;
        $sheetData = $this->spreadsheetChildren($xml->sheetData);

        foreach ($sheetData->row as $xmlRow) {
            if (++$rowCount > self::MAX_ROWS_PER_SHEET) {
                throw new RuntimeException('An XLSX worksheet contains too many rows.');
            }
            $values = [];
            foreach ($this->spreadsheetChildren($xmlRow)->c as $cell) {
                $cellBody = $this->spreadsheetChildren($cell);
                $attributes = $cell->attributes();
                if (isset($cellBody->f)) {
                    throw new RuntimeException('Formulas are not allowed in HR import workbooks.');
                }
                $reference = strtoupper((string) $attributes['r']);
                if (! preg_match('/^([A-Z]+)[1-9][0-9]*$/', $reference, $match)) {
                    throw new RuntimeException('An XLSX cell has an invalid reference.');
                }
                $index = $this->columnIndex($match[1]);
                if ($index >= self::MAX_COLUMNS_PER_SHEET) {
                    throw new RuntimeException('An XLSX worksheet contains too many columns.');
                }
                if (++$totalCells > self::MAX_TOTAL_CELLS) {
                    throw new RuntimeException('The XLSX workbook contains too many cells.');
                }
                $values[$index] = $this->cellValue($cell, $cellBody, $shared, $dateStyles, $date1904);
            }
            if ($values !== []) {
                ksort($values);
                $matrix[] = $values;
            }
        }

        if ($matrix === []) {
            return ['headers' => [], 'rows' => []];
        }
        $headerValues = array_shift($matrix);
        $headers = [];
        foreach ($headerValues as $index => $value) {
            $header = $this->normalize((string) $value);
            if ($header !== '' && in_array($header, $headers, true)) {
                throw new RuntimeException("The XLSX worksheet contains a duplicate header [{$header}].");
            }
            $headers[$index] = $header;
        }

        $rows = [];
        foreach ($matrix as $values) {
            $row = [];
            foreach ($headers as $index => $header) {
                if ($header !== '') {
                    $row[$header] = $values[$index] ?? null;
                }
            }
            if (array_filter($row, fn (mixed $value): bool => $value !== null && $value !== '') !== []) {
                $rows[] = $row;
            }
        }

        return ['headers' => array_values(array_filter($headers, fn (string $header): bool => $header !== '')), 'rows' => $rows];
    }

    /** @param array<int, string> $shared @param array<int, true> $dateStyles */
    private function cellValue(SimpleXMLElement $cell, SimpleXMLElement $body, array $shared, array $dateStyles, bool $date1904): mixed
    {
        $attributes = $cell->attributes();
        $type = (string) $attributes['t'];
        $raw = (string) ($body->v ?? '');

        return match ($type) {
            's' => $shared[(int) $raw] ?? throw new RuntimeException('An XLSX cell references a missing shared string.'),
            'inlineStr' => $this->richText($body->is),
            'b' => $raw === '1',
            'd' => $this->isoDate($raw),
            'e' => throw new RuntimeException('Excel error cells are not allowed in HR import workbooks.'),
            default => $raw === '' ? null : $this->numericOrText($raw, (int) ($attributes['s'] ?? 0), $dateStyles, $date1904),
        };
    }

    /** @param array<int, true> $dateStyles */
    private function numericOrText(string $raw, int $style, array $dateStyles, bool $date1904): mixed
    {
        if (! is_numeric($raw)) {
            return $raw;
        }
        if (isset($dateStyles[$style])) {
            return $this->excelDate((float) $raw, $date1904);
        }

        return preg_match('/^-?[0-9]+$/', $raw) ? (int) $raw : (float) $raw;
    }

    private function excelDate(float $serial, bool $date1904): string
    {
        if ($serial < 0 || $serial > 2_958_465) {
            throw new RuntimeException('An XLSX date serial is outside the supported range.');
        }
        $base = new DateTimeImmutable($date1904 ? '1904-01-01 00:00:00' : '1899-12-30 00:00:00', new DateTimeZone('UTC'));
        $seconds = (int) round($serial * 86400);
        $date = $base->modify("+{$seconds} seconds");

        return $seconds % 86400 === 0 ? $date->format('Y-m-d') : $date->format('Y-m-d\TH:i:s');
    }

    private function isoDate(string $value): string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?)?$/', $value)) {
            throw new RuntimeException('ISO date cells must use YYYY-MM-DD or an ISO-8601 datetime.');
        }

        return $value;
    }

    /** @return array<int, string> */
    private function sharedStrings(ZipArchive $zip): array
    {
        $content = $zip->getFromName('xl/sharedStrings.xml');
        if ($content === false) {
            return [];
        }

        $xml = $this->spreadsheetChildren($this->xml($content));

        return array_map(fn (SimpleXMLElement $item): string => $this->richText($item), iterator_to_array($xml->si));
    }

    private function richText(SimpleXMLElement $item): string
    {
        $body = $this->spreadsheetChildren($item);
        if (isset($body->t)) {
            return (string) $body->t;
        }
        $text = '';
        foreach ($body->r as $run) {
            $text .= (string) $this->spreadsheetChildren($run)->t;
        }

        return $text;
    }

    /** @return array<int, true> */
    private function dateStyles(ZipArchive $zip): array
    {
        $content = $zip->getFromName('xl/styles.xml');
        if ($content === false) {
            return [];
        }
        $xml = $this->spreadsheetChildren($this->xml($content));
        $custom = [];
        if (isset($xml->numFmts)) {
            foreach ($this->spreadsheetChildren($xml->numFmts)->numFmt as $format) {
                $attributes = $format->attributes();
                $code = preg_replace('/"[^"]*"|\[[^\]]*\]/', '', (string) $attributes['formatCode']);
                if (preg_match('/[ymdhis]/i', (string) $code)) {
                    $custom[(int) $attributes['numFmtId']] = true;
                }
            }
        }
        $builtIn = array_fill_keys(array_merge(range(14, 22), range(45, 47)), true);
        $styles = [];
        $styleIndex = 0;
        if (isset($xml->cellXfs)) {
            foreach ($this->spreadsheetChildren($xml->cellXfs)->xf as $xf) {
                $numberFormat = (int) $xf->attributes()['numFmtId'];
                if (isset($builtIn[$numberFormat]) || isset($custom[$numberFormat])) {
                    $styles[$styleIndex] = true;
                }
                $styleIndex++;
            }
        }

        return $styles;
    }

    /** @return array{array<int, array{name:string,path:string}>, bool} */
    private function worksheets(ZipArchive $zip): array
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $relationships = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbook === false || $relationships === false) {
            throw new RuntimeException('The XLSX workbook metadata is incomplete.');
        }
        $relationshipMap = [];
        foreach ($this->relationshipChildren($this->xml($relationships))->Relationship as $relationship) {
            $attributes = $relationship->attributes();
            if ((string) $attributes['TargetMode'] === 'External') {
                throw new RuntimeException('External workbook relationships are not allowed.');
            }
            if (str_ends_with((string) $attributes['Type'], '/worksheet')) {
                $relationshipMap[(string) $attributes['Id']] = $this->worksheetPath((string) $attributes['Target']);
            }
        }
        $root = $this->xml($workbook);
        $xml = $this->spreadsheetChildren($root);
        $namespaces = $root->getDocNamespaces(true);
        $relationshipNamespace = $namespaces['r'] ?? 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $sheets = [];
        foreach ($this->spreadsheetChildren($xml->sheets)->sheet as $sheet) {
            $attributes = $sheet->attributes();
            $id = (string) $sheet->attributes($relationshipNamespace)['id'];
            if (! isset($relationshipMap[$id])) {
                throw new RuntimeException('A worksheet relationship is missing or unsafe.');
            }
            $sheets[] = ['name' => (string) $attributes['name'], 'path' => $relationshipMap[$id]];
        }
        if ($sheets === []) {
            throw new RuntimeException('The XLSX file has no worksheets.');
        }

        $workbookAttributes = $xml->workbookPr->attributes();
        $date1904 = (string) ($workbookAttributes['date1904'] ?? 'false');

        return [$sheets, strtolower($date1904) === 'true' || $date1904 === '1'];
    }

    private function worksheetPath(string $target): string
    {
        if ($target === '' || preg_match('/^[a-z][a-z0-9+.-]*:/i', $target) || str_contains($target, '\\') || str_contains($target, "\0")) {
            throw new RuntimeException('An unsafe worksheet relationship was detected.');
        }
        $path = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
            } else {
                $segments[] = $segment;
            }
        }
        $normalized = implode('/', $segments);
        if (! str_starts_with($normalized, 'xl/worksheets/') || ! str_ends_with($normalized, '.xml')) {
            throw new RuntimeException('A worksheet relationship points outside the worksheet directory.');
        }

        return $normalized;
    }

    private function assertSafeRelationships(ZipArchive $zip): void
    {
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) ($zip->getNameIndex($index) ?: '');
            if ($name !== 'xl/_rels/workbook.xml.rels' && ! preg_match('#^xl/worksheets/_rels/[^/]+\.rels$#', $name)) {
                continue;
            }
            foreach ($this->relationshipChildren($this->xml((string) $zip->getFromIndex($index)))->Relationship as $relationship) {
                $attributes = $relationship->attributes();
                $target = (string) $attributes['Target'];
                if ((string) $attributes['TargetMode'] === 'External' || preg_match('/^[a-z][a-z0-9+.-]*:/i', $target)) {
                    throw new RuntimeException('External worksheet relationships are not allowed.');
                }
            }
        }
    }

    private function xml(string $content): SimpleXMLElement
    {
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $content)) {
            throw new RuntimeException('DTD and entity declarations are not allowed in XLSX XML.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($content, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
            if ($xml === false) {
                throw new RuntimeException('The XLSX XML is invalid.');
            }

            return $xml;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function spreadsheetChildren(SimpleXMLElement $xml): SimpleXMLElement
    {
        foreach ($xml->getDocNamespaces(true) as $namespace) {
            if ($namespace === 'http://schemas.openxmlformats.org/spreadsheetml/2006/main') {
                return $xml->children($namespace);
            }
        }

        return $xml;
    }

    private function relationshipChildren(SimpleXMLElement $xml): SimpleXMLElement
    {
        foreach ($xml->getDocNamespaces(true) as $namespace) {
            if ($namespace === 'http://schemas.openxmlformats.org/package/2006/relationships') {
                return $xml->children($namespace);
            }
        }

        return $xml;
    }

    private function assertSafeEntries(ZipArchive $zip): void
    {
        if ($zip->numFiles > self::MAX_ARCHIVE_ENTRIES) {
            throw new RuntimeException('The XLSX archive contains too many files.');
        }
        $total = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            $name = (string) ($stat['name'] ?? '');
            if ($name === '' || str_starts_with($name, '/') || str_contains($name, '..') || str_contains($name, '\\')) {
                throw new RuntimeException('The XLSX archive contains an unsafe path.');
            }
            $total += (int) ($stat['size'] ?? 0);
            if ($total > self::MAX_EXPANDED_BYTES) {
                throw new RuntimeException('The XLSX archive is too large when expanded.');
            }
        }
    }

    private function normalize(string $value): string
    {
        return strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '_', trim($value)), '_'));
    }

    private function columnIndex(string $letters): int
    {
        $value = 0;
        foreach (str_split($letters) as $letter) {
            $value = $value * 26 + ord($letter) - 64;
        }

        return max($value - 1, 0);
    }
}
