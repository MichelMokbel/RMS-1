<?php

namespace App\Services\PettyCash;

use RuntimeException;
use ZipArchive;

class PettyCashImportTemplateBuilder
{
    /** @var array<int, string> */
    public const HEADERS = [
        'entry_id',
        'supplier',
        'reference_number',
        'due_date',
        'category',
        'wallet',
        'paid',
        'description',
        'quantity',
        'unit_price',
        'tax_amount',
        'notes',
    ];

    /**
     * @param  array<int, string>  $suppliers
     * @param  array<int, string>  $categories
     * @param  array<int, string>  $wallets
     */
    public function build(array $suppliers, array $categories, array $wallets): string
    {
        $sheets = [
            ['name' => 'Instructions', 'kind' => 'instructions', 'rows' => $this->instructions()],
            ['name' => 'Petty Cash Expenses', 'kind' => 'data', 'headers' => self::HEADERS, 'rows' => []],
            ['name' => 'Suppliers', 'kind' => 'lookup', 'headers' => ['supplier'], 'rows' => array_map(fn (string $value): array => [$value], $suppliers)],
            ['name' => 'Categories', 'kind' => 'lookup', 'headers' => ['category'], 'rows' => array_map(fn (string $value): array => [$value], $categories)],
            ['name' => 'Wallets', 'kind' => 'lookup', 'headers' => ['wallet'], 'rows' => array_map(fn (string $value): array => [$value], $wallets)],
        ];

        $path = tempnam(sys_get_temp_dir(), 'petty-cash-import-');
        if ($path === false) {
            throw new RuntimeException('Unable to create the petty cash import template.');
        }

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            @unlink($path);
            throw new RuntimeException('Unable to build the petty cash import template.');
        }

        try {
            $zip->addFromString('[Content_Types].xml', $this->contentTypesXml(count($sheets)));
            $zip->addFromString('_rels/.rels', $this->rootRelationshipsXml());
            $zip->addFromString('xl/workbook.xml', $this->workbookXml($sheets, count($suppliers), count($categories), count($wallets)));
            $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationshipsXml(count($sheets)));
            $zip->addFromString('xl/styles.xml', $this->stylesXml());

            foreach ($sheets as $index => $sheet) {
                $xml = $sheet['kind'] === 'instructions'
                    ? $this->instructionsSheetXml($sheet['rows'])
                    : $this->tableSheetXml($sheet['headers'], $sheet['rows'], $sheet['kind']);
                $zip->addFromString('xl/worksheets/sheet'.($index + 1).'.xml', $xml);
            }
        } finally {
            $zip->close();
        }

        return $path;
    }

    /** @return array<int, array<int, string>> */
    private function instructions(): array
    {
        return [
            ['Petty cash daily expense import'],
            ['Use the Petty Cash Expenses sheet only. Keep every header exactly as supplied.'],
            [],
            ['How invoice grouping works'],
            ['entry_id', 'Use one stable value for every line belonging to the same supplier invoice.'],
            ['supplier', 'Choose the supplier token from the dropdown. Tokens use “ID | Display Name”; do not change the numeric ID.'],
            ['reference_number', 'Enter the supplier invoice or receipt reference. It must be the same on every line in an entry.'],
            ['due_date', 'Optional. Use YYYY-MM-DD; a blank due date defaults to the business date selected during upload.'],
            ['category and wallet', 'Choose lookup tokens or leave blank to use the defaults selected during upload.'],
            ['paid', 'TRUE settles the invoice from the wallet. FALSE posts it as pending settlement.'],
            ['description, quantity, unit_price', 'Each row becomes one line item. Quantity is optional and defaults to 1; unit price cannot be negative and may use up to four decimal places.'],
            ['tax_amount', 'Enter the full invoice tax on every row in an entry. Repeated tax is validated once and is not added per line.'],
            ['notes', 'Optional invoice notes. Use the same value on every line in an entry.'],
            [],
            ['Validation and safety'],
            ['Review before commit', 'Uploading only stages and validates the workbook. No invoices, payments, ledger entries, or wallet changes occur until commit.'],
            ['One day per file', 'Every entry uses the business date selected during upload. Mixed-date files are not supported in this version.'],
            ['Atomic commit', 'All entries commit together. If any invoice, posting, or settlement fails, none of the batch is applied.'],
            ['Duplicates', 'Re-uploading the same workbook reopens the existing batch. Existing supplier references are never overwritten.'],
            ['Security', 'Do not use formulas, macros, external links, renamed sheets, or additional headers. Unsafe workbooks are rejected.'],
            ['Attachments', 'Receipts and PDFs are not embedded in this workbook. Upload supporting files to the created invoice afterward.'],
        ];
    }

    /** @param array<int, array<string, mixed>> $sheets */
    private function workbookXml(array $sheets, int $supplierCount, int $categoryCount, int $walletCount): string
    {
        $sheetXml = '';
        foreach ($sheets as $index => $sheet) {
            $state = ($sheet['kind'] ?? null) === 'lookup' ? ' state="veryHidden"' : '';
            $sheetXml .= '<sheet name="'.$this->escape((string) $sheet['name']).'" sheetId="'.($index + 1).'"'.$state.' r:id="rId'.($index + 1).'"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<workbookPr date1904="0"/><sheets>'.$sheetXml.'</sheets><definedNames>'
            .'<definedName name="SupplierValues">\'Suppliers\'!$A$2:$A$'.max($supplierCount + 1, 2).'</definedName>'
            .'<definedName name="CategoryValues">\'Categories\'!$A$2:$A$'.max($categoryCount + 1, 2).'</definedName>'
            .'<definedName name="WalletValues">\'Wallets\'!$A$2:$A$'.max($walletCount + 1, 2).'</definedName>'
            .'</definedNames><calcPr calcId="0" calcMode="manual"/></workbook>';
    }

    private function workbookRelationshipsXml(int $count): string
    {
        $relationships = '';
        for ($index = 1; $index <= $count; $index++) {
            $relationships .= '<Relationship Id="rId'.$index.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$index.'.xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$relationships
            .'<Relationship Id="rId'.($count + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    private function contentTypesXml(int $count): string
    {
        $overrides = '';
        for ($index = 1; $index <= $count; $index++) {
            $overrides .= '<Override PartName="/xl/worksheets/sheet'.$index.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'.$overrides.'</Types>';
    }

    private function rootRelationshipsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
    }

    private function stylesXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
 <numFmts count="3"><numFmt numFmtId="164" formatCode="yyyy-mm-dd"/><numFmt numFmtId="165" formatCode="#,#0.00"/><numFmt numFmtId="166" formatCode="#,#0.0000"/></numFmts>
 <fonts count="4"><font><sz val="11"/><name val="Aptos"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Aptos"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="18"/><name val="Aptos Display"/></font><font><b/><color rgb="FF0F172A"/><sz val="12"/><name val="Aptos"/></font></fonts>
 <fills count="5"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF7C3AED"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FF0F172A"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFEDE9FE"/></patternFill></fill></fills>
 <borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left/><right/><top/><bottom style="thin"><color rgb="FFCBD5E1"/></bottom><diagonal/></border></borders>
 <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
 <cellXfs count="8"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="2" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1"/><xf numFmtId="0" fontId="3" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf><xf numFmtId="166" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>
 <cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>
</styleSheet>
XML;
    }

    /** @param array<int, array<int, string>> $rows */
    private function instructionsSheetXml(array $rows): string
    {
        $rowXml = '';
        foreach ($rows as $rowIndex => $row) {
            $number = $rowIndex + 1;
            $section = in_array($number, [4, 15], true);
            $style = $number === 1 ? 2 : ($section ? 3 : 6);
            $cells = '';
            foreach ($row as $column => $value) {
                $cells .= $this->stringCell($this->column($column + 1).$number, $value, $style);
            }
            $rowXml .= '<row r="'.$number.'" ht="'.($number === 1 ? 34 : ($section ? 24 : 30)).'" customHeight="1">'.$cells.'</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetViews><sheetView showGridLines="0" workbookViewId="0"/></sheetViews><cols><col min="1" max="1" width="24" customWidth="1"/><col min="2" max="2" width="105" customWidth="1"/></cols>'
            .'<sheetData>'.$rowXml.'</sheetData><mergeCells count="3"><mergeCell ref="A1:F1"/><mergeCell ref="A4:F4"/><mergeCell ref="A15:F15"/></mergeCells></worksheet>';
    }

    /** @param array<int, string> $headers @param array<int, array<int, string>> $rows */
    private function tableSheetXml(array $headers, array $rows, string $kind): string
    {
        $columns = '';
        foreach ($headers as $index => $header) {
            $width = match ($header) {
                'supplier', 'category', 'wallet' => 34,
                'description', 'notes' => 40,
                default => max(14, min(24, strlen($header) + 4)),
            };
            $style = match ($header) {
                'due_date' => 4,
                'unit_price' => 7,
                'quantity', 'tax_amount' => 5,
                default => 0,
            };
            $columns .= '<col min="'.($index + 1).'" max="'.($index + 1).'" width="'.$width.'" customWidth="1"'.($style ? ' style="'.$style.'"' : '').'/>';
        }

        $headerCells = '';
        foreach ($headers as $index => $header) {
            $headerCells .= $this->stringCell($this->column($index + 1).'1', $header, 1);
        }
        $rowXml = '<row r="1" ht="32" customHeight="1">'.$headerCells.'</row>';
        foreach ($rows as $rowIndex => $row) {
            $cells = '';
            foreach ($row as $column => $value) {
                $cells .= $this->stringCell($this->column($column + 1).($rowIndex + 2), (string) $value, 0);
            }
            $rowXml .= '<row r="'.($rowIndex + 2).'">'.$cells.'</row>';
        }

        $lastColumn = $this->column(count($headers));
        $validations = $kind === 'data' ? $this->validationsXml() : '';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetPr><tabColor rgb="'.($kind === 'lookup' ? 'FF64748B' : 'FF7C3AED').'"/></sheetPr><sheetViews><sheetView showGridLines="0" workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            .'<cols>'.$columns.'</cols><sheetData>'.$rowXml.'</sheetData><autoFilter ref="A1:'.$lastColumn.'1"/>'.$validations.'</worksheet>';
    }

    private function validationsXml(): string
    {
        $rules = [
            $this->listValidation('B2:B5001', 'SupplierValues', false),
            $this->listValidation('E2:E5001', 'CategoryValues', true),
            $this->listValidation('F2:F5001', 'WalletValues', true),
            $this->listValidation('G2:G5001', '&quot;TRUE,FALSE&quot;', false),
            '<dataValidation type="decimal" operator="greaterThan" allowBlank="1" showErrorMessage="1" errorTitle="Invalid quantity" error="Quantity must be greater than zero when supplied." sqref="I2:I5001"><formula1>0</formula1></dataValidation>',
            '<dataValidation type="decimal" operator="greaterThanOrEqual" allowBlank="0" showErrorMessage="1" errorTitle="Invalid amount" error="Unit price cannot be negative." sqref="J2:J5001"><formula1>0</formula1></dataValidation>',
            '<dataValidation type="decimal" operator="greaterThanOrEqual" allowBlank="1" showErrorMessage="1" errorTitle="Invalid tax" error="Tax cannot be negative." sqref="K2:K5001"><formula1>0</formula1></dataValidation>',
        ];

        return '<dataValidations count="'.count($rules).'">'.implode('', $rules).'</dataValidations>';
    }

    private function listValidation(string $range, string $formula, bool $allowBlank): string
    {
        return '<dataValidation type="list" allowBlank="'.($allowBlank ? '1' : '0').'" showErrorMessage="1" errorTitle="Invalid value" error="Choose a value from the workbook lookup list." sqref="'.$range.'"><formula1>'.$formula.'</formula1></dataValidation>';
    }

    private function stringCell(string $reference, string $value, int $style): string
    {
        $clean = preg_replace('/[^\P{C}\t\n\r]/u', '', $value) ?? '';

        return '<c r="'.$reference.'" t="inlineStr"'.($style ? ' s="'.$style.'"' : '').'><is><t xml:space="preserve">'.$this->escape($clean).'</t></is></c>';
    }

    private function column(int $index): string
    {
        $name = '';
        while ($index > 0) {
            $index--;
            $name = chr(65 + ($index % 26)).$name;
            $index = intdiv($index, 26);
        }

        return $name;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
