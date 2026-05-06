<?php

namespace App\Support\Reports;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class XlsxExport
{
    /**
     * @param  array<int, string>  $headers
     * @param  iterable<int, array<int, string|int|float|null>>  $rows
     */
    public static function download(array $headers, iterable $rows, string $filename = 'report.xlsx', string $sheetName = 'Report'): BinaryFileResponse
    {
        $allRows = [array_values($headers)];

        foreach ($rows as $row) {
            $allRows[] = array_values($row);
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'xlsx-report-');
        if ($tmpPath === false) {
            abort(500, 'Unable to create Excel export.');
        }

        $zip = new ZipArchive();
        if ($zip->open($tmpPath, ZipArchive::OVERWRITE) !== true) {
            @unlink($tmpPath);
            abort(500, 'Unable to build Excel export.');
        }

        $zip->addFromString('[Content_Types].xml', self::contentTypesXml());
        $zip->addFromString('_rels/.rels', self::rootRelsXml());
        $zip->addFromString('xl/workbook.xml', self::workbookXml($sheetName));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRelsXml());
        $zip->addFromString('xl/styles.xml', self::stylesXml());
        $zip->addFromString('xl/worksheets/sheet1.xml', self::sheetXml($allRows));
        $zip->close();

        return response()->download(
            $tmpPath,
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        )->deleteFileAfterSend(true);
    }

    private static function contentTypesXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>
XML;
    }

    private static function rootRelsXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>
XML;
    }

    private static function workbookXml(string $sheetName): string
    {
        $name = self::escapeXml(self::sheetName($sheetName));

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="{$name}" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>
XML;
    }

    private static function workbookRelsXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>
XML;
    }

    private static function stylesXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="1">
    <font>
      <sz val="11"/>
      <name val="Calibri"/>
    </font>
  </fonts>
  <fills count="2">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
  </fills>
  <borders count="1">
    <border>
      <left/><right/><top/><bottom/><diagonal/>
    </border>
  </borders>
  <cellStyleXfs count="1">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>
  </cellStyleXfs>
  <cellXfs count="1">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
  </cellXfs>
  <cellStyles count="1">
    <cellStyle name="Normal" xfId="0" builtinId="0"/>
  </cellStyles>
</styleSheet>
XML;
    }

    /**
     * @param  array<int, array<int, string|int|float|null>>  $rows
     */
    private static function sheetXml(array $rows): string
    {
        $xmlRows = [];

        foreach ($rows as $rowIndex => $row) {
            $cells = [];

            foreach ($row as $colIndex => $value) {
                $ref = self::columnName($colIndex + 1).($rowIndex + 1);
                $cells[] = self::cellXml($ref, $value);
            }

            $xmlRows[] = '<row r="'.($rowIndex + 1).'">'.implode('', $cells).'</row>';
        }

        $dimension = 'A1:'.self::columnName(max(1, count($rows[0] ?? []))).max(1, count($rows));

        return sprintf(<<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <dimension ref="{$dimension}"/>
  <sheetData>
    %s
  </sheetData>
</worksheet>
XML, implode('', $xmlRows));
    }

    private static function cellXml(string $ref, string|int|float|null $value): string
    {
        if ($value === null) {
            return '<c r="'.$ref.'"/>';
        }

        if (is_int($value) || is_float($value)) {
            return '<c r="'.$ref.'"><v>'.$value.'</v></c>';
        }

        $escaped = self::escapeXml($value);

        return '<c r="'.$ref.'" t="inlineStr"><is><t>'.$escaped.'</t></is></c>';
    }

    private static function columnName(int $index): string
    {
        $name = '';

        while ($index > 0) {
            $index--;
            $name = chr(65 + ($index % 26)).$name;
            $index = intdiv($index, 26);
        }

        return $name;
    }

    private static function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function sheetName(string $sheetName): string
    {
        $name = trim($sheetName);
        $name = preg_replace('/[\\\\\\/*?:\\[\\]]+/', ' ', $name) ?: 'Report';

        return mb_substr($name !== '' ? $name : 'Report', 0, 31);
    }
}
