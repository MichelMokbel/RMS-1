<?php

use App\Services\HR\Imports\SafeSpreadsheetReader;

/** @param array<int, array{name:string,xml:string,target?:string}> $sheets */
function hrReaderWorkbook(array $sheets, ?string $worksheetRelationships = null): string
{
    $path = tempnam(sys_get_temp_dir(), 'hr-reader-test-');
    $zip = new \ZipArchive;
    $zip->open($path, \ZipArchive::OVERWRITE);
    $overrides = '';
    $workbookSheets = '';
    $relationships = '';
    foreach ($sheets as $index => $sheet) {
        $number = $index + 1;
        $overrides .= '<Override PartName="/xl/worksheets/sheet'.$number.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $workbookSheets .= '<sheet name="'.htmlspecialchars($sheet['name'], ENT_XML1).'" sheetId="'.$number.'" r:id="rId'.$number.'"/>';
        $relationships .= '<Relationship Id="rId'.$number.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="'.($sheet['target'] ?? 'worksheets/sheet'.$number.'.xml').'"/>';
        $zip->addFromString('xl/worksheets/sheet'.$number.'.xml', $sheet['xml']);
    }
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'.$overrides.'</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><workbookPr date1904="0"/><sheets>'.$workbookSheets.'</sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$relationships.'<Relationship Id="rId'.(count($sheets) + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="yyyy-mm-dd"/></numFmts><fonts count="1"><font/></fonts><fills count="1"><fill><patternFill patternType="none"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0"/><xf numFmtId="164" applyNumberFormat="1"/></cellXfs></styleSheet>');
    if ($worksheetRelationships !== null) {
        $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels', $worksheetRelationships);
    }
    $zip->close();

    return $path;
}

function hrReaderSheet(string $rows): string
{
    return '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$rows.'</sheetData></worksheet>';
}

function hrPrefixReaderWorkbook(string $path): void
{
    $zip = new \ZipArchive;
    $zip->open($path);
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><x:workbook xmlns:x="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><x:workbookPr date1904="0"/><x:sheets><x:sheet name="Employees" sheetId="1" r:id="rId1"/></x:sheets></x:workbook>');
    $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><x:worksheet xmlns:x="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><x:sheetData><x:row r="1"><x:c r="A1" t="inlineStr"><x:is><x:t>employee_ref</x:t></x:is></x:c><x:c r="B1" t="inlineStr"><x:is><x:t>hire_date</x:t></x:is></x:c></x:row><x:row r="2"><x:c r="A2" t="inlineStr"><x:is><x:t>PREFIX-001</x:t></x:is></x:c><x:c r="B2" s="1"><x:v>46249</x:v></x:c></x:row></x:sheetData></x:worksheet>');
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0"?><x:styleSheet xmlns:x="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><x:numFmts count="1"><x:numFmt numFmtId="164" formatCode="yyyy-mm-dd"/></x:numFmts><x:cellXfs count="2"><x:xf numFmtId="0"/><x:xf numFmtId="164"/></x:cellXfs></x:styleSheet>');
    $zip->close();
}

it('returns normalized multi-sheet rows and preserves first-sheet rows', function (): void {
    $serial = (new DateTimeImmutable('1899-12-30'))->diff(new DateTimeImmutable('2026-08-15'))->days;
    $path = hrReaderWorkbook([
        ['name' => 'Employees', 'xml' => hrReaderSheet(
            '<row r="1"><c r="A1" t="inlineStr"><is><t>Employee Ref</t></is></c><c r="B1" t="inlineStr"><is><t>Hire Date</t></is></c></row>'
            .'<row r="2"><c r="A2" t="inlineStr"><is><t>LOCAL-001</t></is></c><c r="B2" s="1"><v>'.$serial.'</v></c></row>'
        )],
        ['name' => 'Compensation Components', 'xml' => hrReaderSheet(
            '<row r="1"><c r="A1" t="inlineStr"><is><t>Employee Ref</t></is></c><c r="B1" t="inlineStr"><is><t>Amount QAR</t></is></c></row>'
            .'<row r="2"><c r="A2" t="inlineStr"><is><t>LOCAL-001</t></is></c><c r="B2"><v>4250.75</v></c></row>'
        )],
    ]);

    try {
        $reader = new SafeSpreadsheetReader;
        $sheets = $reader->sheets($path);

        expect(array_keys($sheets))->toBe(['employees', 'compensation_components'])
            ->and($sheets['employees'])->toBe([['employee_ref' => 'LOCAL-001', 'hire_date' => '2026-08-15']])
            ->and($sheets['compensation_components'][0]['amount_qar'])->toBe(4250.75)
            ->and($reader->rows($path))->toBe($sheets['employees']);
    } finally {
        @unlink($path);
    }
});

it('can opt into exact numeric strings and physical row metadata', function (): void {
    $path = hrReaderWorkbook([
        ['name' => 'Settlement', 'xml' => hrReaderSheet(
            '<row r="1"><c r="A1" t="inlineStr"><is><t>Reference Number</t></is></c><c r="B1" t="inlineStr"><is><t>Gross Amount</t></is></c></row>'
            .'<row r="7"><c r="A7"><v>000123</v></c><c r="B7"><v>4270.00</v></c></row>'
        )],
    ]);

    try {
        $reader = new SafeSpreadsheetReader;
        $legacy = $reader->workbook($path);
        $exact = $reader->workbook($path, [
            'raw_numeric_strings' => true,
            'include_row_metadata' => true,
        ]);

        expect($legacy['sheets']['settlement'][0])->toBe([
            'reference_number' => 123,
            'gross_amount' => 4270.0,
        ])->and($exact['sheets']['settlement'][0])->toBe([
            'reference_number' => '000123',
            'gross_amount' => '4270.00',
            '__physical_row' => 7,
        ]);
    } finally {
        @unlink($path);
    }
});

it('reads Excel workbooks that prefix the spreadsheet namespace', function (): void {
    $path = hrReaderWorkbook([['name' => 'Employees', 'xml' => hrReaderSheet('')]]);
    hrPrefixReaderWorkbook($path);

    try {
        $workbook = (new SafeSpreadsheetReader)->workbook($path);

        expect($workbook['headers']['employees'])->toBe(['employee_ref', 'hire_date'])
            ->and($workbook['sheets']['employees'])->toBe([
                ['employee_ref' => 'PREFIX-001', 'hire_date' => '2026-08-15'],
            ]);
    } finally {
        @unlink($path);
    }
});

it('reads every indexed value from a shared strings table', function (): void {
    $path = hrReaderWorkbook([['name' => 'Expenses', 'xml' => hrReaderSheet(
        '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>'
        .'<row r="2"><c r="A2" t="s"><v>2</v></c><c r="B2" t="s"><v>3</v></c></row>'
    )]]);
    $zip = new \ZipArchive;
    $zip->open($path);
    $zip->addFromString(
        'xl/sharedStrings.xml',
        '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="4" uniqueCount="4">'
        .'<si><t>Entry ID</t></si><si><t>Description</t></si><si><t>ENTRY-001</t></si><si><t>Kitchen supplies</t></si></sst>'
    );
    $zip->close();

    try {
        $workbook = (new SafeSpreadsheetReader)->workbook($path);

        expect($workbook['headers']['expenses'])->toBe(['entry_id', 'description'])
            ->and($workbook['sheets']['expenses'])->toBe([
                ['entry_id' => 'ENTRY-001', 'description' => 'Kitchen supplies'],
            ]);
    } finally {
        @unlink($path);
    }
});

it('rejects formula cells', function (): void {
    $path = hrReaderWorkbook([['name' => 'Employees', 'xml' => hrReaderSheet(
        '<row r="1"><c r="A1" t="inlineStr"><is><t>employee_ref</t></is></c></row>'
        .'<row r="2"><c r="A2"><f>CONCAT("EMP",1)</f><v>EMP1</v></c></row>'
    )]]);

    try {
        expect(fn () => (new SafeSpreadsheetReader)->sheets($path))
            ->toThrow(\RuntimeException::class, 'Formulas are not allowed');
    } finally {
        @unlink($path);
    }
});

it('rejects macro and active content embedded in XLSX archives', function (): void {
    $path = hrReaderWorkbook([['name' => 'Employees', 'xml' => hrReaderSheet(
        '<row r="1"><c r="A1" t="inlineStr"><is><t>employee_ref</t></is></c></row>'
    )]]);
    $zip = new \ZipArchive;
    $zip->open($path);
    $zip->addFromString('xl/vbaProject.bin', 'not-a-real-macro');
    $zip->close();

    try {
        expect(fn () => (new SafeSpreadsheetReader)->sheets($path))
            ->toThrow(\RuntimeException::class, 'Macros and active content are not allowed');
    } finally {
        @unlink($path);
    }
});

it('rejects external worksheet relationships', function (): void {
    $external = '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="https://example.test/leak" TargetMode="External"/></Relationships>';
    $path = hrReaderWorkbook([['name' => 'Employees', 'xml' => hrReaderSheet('<row r="1"><c r="A1" t="inlineStr"><is><t>employee_ref</t></is></c></row>')]], $external);

    try {
        expect(fn () => (new SafeSpreadsheetReader)->sheets($path))
            ->toThrow(\RuntimeException::class, 'External worksheet relationships are not allowed');
    } finally {
        @unlink($path);
    }
});

it('rejects duplicate normalized sheet names and excessive columns', function (): void {
    $duplicate = hrReaderWorkbook([
        ['name' => 'Leave History', 'xml' => hrReaderSheet('<row r="1"><c r="A1" t="inlineStr"><is><t>employee_ref</t></is></c></row>')],
        ['name' => 'Leave-History', 'xml' => hrReaderSheet('<row r="1"><c r="A1" t="inlineStr"><is><t>employee_ref</t></is></c></row>')],
    ]);
    $wide = hrReaderWorkbook([['name' => 'Employees', 'xml' => hrReaderSheet('<row r="1"><c r="IW1" t="inlineStr"><is><t>unsafe</t></is></c></row>')]]);

    try {
        expect(fn () => (new SafeSpreadsheetReader)->sheets($duplicate))
            ->toThrow(\RuntimeException::class, 'unique after normalization')
            ->and(fn () => (new SafeSpreadsheetReader)->sheets($wide))
            ->toThrow(\RuntimeException::class, 'too many columns');
    } finally {
        @unlink($duplicate);
        @unlink($wide);
    }
});
