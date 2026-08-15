<?php

use App\Enums\HR\ImportType;
use App\Services\HR\Imports\FullHistoryTemplateBuilder;
use App\Services\HR\Imports\SafeSpreadsheetReader;

/** @return array<int, string> */
function hrTemplateHeaders(\ZipArchive $zip, int $sheetNumber): array
{
    $xml = simplexml_load_string((string) $zip->getFromName("xl/worksheets/sheet{$sheetNumber}.xml"), \SimpleXMLElement::class);
    $headers = [];
    foreach ($xml->sheetData->row[0]->c as $cell) {
        $headers[] = (string) $cell->is->t;
    }

    return $headers;
}

it('builds the authoritative consolidated history workbook contract', function (): void {
    $builder = new FullHistoryTemplateBuilder;
    $path = $builder->build('Layla Kitchen QA',
        [['identifier' => 'ID:1', 'name' => 'Main Kitchen'], ['identifier' => 'WEST', 'name' => 'West Branch']],
        [['identifier' => 'ID:10', 'name' => 'Operations']],
        [['identifier' => 'annual', 'name' => 'Annual Leave']]);

    try {
        $sheets = (new SafeSpreadsheetReader)->sheets($path);
        expect(array_keys($sheets))->toBe([
            'instructions', 'employees', 'assignments', 'compensation', 'compensation_components',
            'leave_balances', 'leave_history', 'payroll_history', 'payroll_components',
            'branches', 'departments', 'leave_types',
        ])->and($sheets['branches'])->toBe([
            ['identifier' => 'ID:1', 'display_name' => 'Main Kitchen'],
            ['identifier' => 'WEST', 'display_name' => 'West Branch'],
        ])->and($sheets['departments'][0]['identifier'])->toBe('ID:10')
            ->and($sheets['leave_types'][0]['identifier'])->toBe('annual');

        $zip = new \ZipArchive;
        $zip->open($path);
        foreach (array_values(FullHistoryTemplateBuilder::DATA_SHEETS) as $index => $headers) {
            expect(hrTemplateHeaders($zip, $index + 2))->toBe($headers);
        }
        $assignmentsXml = (string) $zip->getFromName('xl/worksheets/sheet3.xml');
        $compensationXml = (string) $zip->getFromName('xl/worksheets/sheet5.xml');
        expect($assignmentsXml)->toContain('<formula1>BranchCodes</formula1>')
            ->and($compensationXml)->toContain('<formula1>&quot;basic,allowance,earning,deduction&quot;</formula1>');
        $zip->close();

        $allHeaders = collect(FullHistoryTemplateBuilder::DATA_SHEETS)->flatten()->all();
        expect($allHeaders)->not->toContain('document_number')->not->toContain('file')
            ->and(ImportType::FullHistory->value)->toBe('full_history');
    } finally {
        @unlink($path);
    }
});

it('keeps full history template delivery company scoped in the controller', function (): void {
    $controller = file_get_contents(dirname(__DIR__, 3).'/app/Http/Controllers/HR/HrImportTemplateController.php');

    expect($controller)->toContain('ImportType::FullHistory->value')
        ->toContain("where('company_id', \$companyId)")
        ->toContain("'ID:'.\$row->id")
        ->toContain('FullHistoryTemplateBuilder $fullHistory');
});
