<?php

namespace App\Services\HR\Imports;

use RuntimeException;
use ZipArchive;

class FullHistoryTemplateBuilder
{
    /** @var array<string, array<int, string>> */
    public const DATA_SHEETS = [
        'Employees' => [
            'employee_ref', 'legacy_employee_number', 'legal_first_name', 'legal_middle_name', 'legal_last_name',
            'display_name', 'preferred_name', 'work_email', 'personal_email', 'work_phone', 'personal_phone',
            'date_of_birth', 'nationality', 'gender', 'qid_number', 'passport_number', 'address_line_1',
            'address_line_2', 'city', 'country', 'emergency_contact_name', 'emergency_contact_relationship',
            'emergency_contact_phone', 'hire_date', 'probation_end_date', 'notice_date', 'exit_date', 'exit_reason',
            'employment_type', 'employment_status',
        ],
        'Assignments' => [
            'employee_ref', 'branch_code', 'department_code', 'manager_ref', 'job_title', 'employment_type',
            'effective_from', 'effective_to', 'notes',
        ],
        'Compensation' => [
            'employee_ref', 'package_ref', 'effective_from', 'effective_to', 'currency', 'pay_frequency',
            'proration_divisor', 'bank_name', 'beneficiary_name', 'bank_account_number', 'iban', 'swift_code', 'notes',
        ],
        'Compensation Components' => [
            'employee_ref', 'package_ref', 'component_code', 'component_name', 'category', 'amount_qar', 'is_taxable',
        ],
        'Leave Balances' => ['employee_ref', 'leave_type_code', 'effective_date', 'days', 'notes'],
        'Leave History' => [
            'employee_ref', 'leave_type_code', 'start_date', 'end_date', 'start_portion', 'end_portion',
            'requested_days', 'status', 'reason',
        ],
        'Payroll History' => [
            'payroll_ref', 'employee_ref', 'pay_period_start', 'pay_period_end', 'currency', 'basic_qar',
            'gross_qar', 'earnings_qar', 'deductions_qar', 'net_qar', 'calendar_days', 'worked_days',
            'unpaid_leave_days', 'paid_at', 'description',
        ],
        'Payroll Components' => [
            'payroll_ref', 'employee_ref', 'component_code', 'component_name', 'category', 'amount_qar', 'is_taxable',
        ],
    ];

    /**
     * @param  array<int, array{identifier:string,name:string}>  $branches
     * @param  array<int, array{identifier:string,name:string}>  $departments
     * @param  array<int, array{identifier:string,name:string}>  $leaveTypes
     */
    public function build(string $companyName, array $branches, array $departments, array $leaveTypes): string
    {
        $sheets = [['name' => 'Instructions', 'kind' => 'instructions', 'rows' => $this->instructions($companyName)]];
        foreach (self::DATA_SHEETS as $name => $headers) {
            $sheets[] = ['name' => $name, 'kind' => 'data', 'headers' => $headers, 'rows' => []];
        }
        foreach (['Branches' => $branches, 'Departments' => $departments, 'Leave Types' => $leaveTypes] as $name => $rows) {
            $sheets[] = ['name' => $name, 'kind' => 'lookup', 'headers' => ['identifier', 'display_name'],
                'rows' => array_map(fn (array $row): array => [$row['identifier'], $row['name']], $rows)];
        }

        $path = tempnam(sys_get_temp_dir(), 'hr-full-history-');
        if ($path === false) {
            throw new RuntimeException('Unable to create the HR import template.');
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            @unlink($path);
            throw new RuntimeException('Unable to build the HR import template.');
        }

        try {
            $zip->addFromString('[Content_Types].xml', $this->contentTypesXml(count($sheets)));
            $zip->addFromString('_rels/.rels', $this->rootRelationshipsXml());
            $zip->addFromString('xl/workbook.xml', $this->workbookXml($sheets, $branches, $departments, $leaveTypes));
            $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationshipsXml(count($sheets)));
            $zip->addFromString('xl/styles.xml', $this->stylesXml());
            foreach ($sheets as $index => $sheet) {
                $xml = $sheet['kind'] === 'instructions'
                    ? $this->instructionsSheetXml($sheet['rows'])
                    : $this->tableSheetXml($sheet['name'], $sheet['headers'], $sheet['rows'], $sheet['kind']);
                $zip->addFromString('xl/worksheets/sheet'.($index + 1).'.xml', $xml);
            }
        } finally {
            $zip->close();
        }

        return $path;
    }

    /** @return array<int, array<int, string>> */
    private function instructions(string $companyName): array
    {
        return [
            ['Consolidated HR history import'],
            ['Selected company', $companyName],
            [],
            ['How to use this workbook'],
            ['1', 'Create one unique employee_ref per employee on Employees (example: EMP-LOCAL-001).'],
            ['2', 'Use that same employee_ref on every child sheet. References are local to this workbook only.'],
            ['3', 'Create at least one assignment for every employee. Use the company-aware lookup sheets for organization codes.'],
            ['4', 'Create compensation packages before their components. Each package needs exactly one basic component.'],
            ['5', 'Upload the completed XLSX as a Full history import. Do not rename or remove required sheets or headers.'],
            [],
            ['Import rules'],
            ['Dates', 'Use YYYY-MM-DD. Excel date cells are accepted and normalized during validation.'],
            ['Money', 'Enter all *_qar values in QAR major units (for example 4250.75), never integer dirham/minor units.'],
            ['Identifiers', 'employee_ref, package_ref and payroll_ref must be stable within this workbook. Do not use formulas.'],
            ['Legacy numbers', 'legacy_employee_number is optional and only preserves a number issued by the previous HR system.'],
            ['Documents', 'Documents and files are intentionally excluded. Upload secure employee documents separately.'],
            ['Security', 'Formula cells, external workbook relationships and unsafe worksheet links are rejected.'],
            [],
            ['Required data sheets'],
            ['Employees', 'One row per employee. employee_ref must be unique.'],
            ['Assignments', 'At least one dated assignment per employee.'],
            ['Compensation', 'Effective-dated packages identified by package_ref.'],
            ['Compensation Components', 'Components link to employee_ref + package_ref.'],
            ['Leave Balances', 'Opening or adjustment balances by leave type.'],
            ['Leave History', 'Historical leave requests and outcomes.'],
            ['Payroll History', 'Historical payroll totals identified by payroll_ref.'],
            ['Payroll Components', 'This sheet is required, but detail rows are optional. When supplied, components must reconcile to the Payroll History totals.'],
        ];
    }

    /** @param array<int, array<string, mixed>> $sheets @param array<int, mixed> $branches @param array<int, mixed> $departments @param array<int, mixed> $leaveTypes */
    private function workbookXml(array $sheets, array $branches, array $departments, array $leaveTypes): string
    {
        $sheetXml = '';
        foreach ($sheets as $index => $sheet) {
            $sheetXml .= '<sheet name="'.$this->escape((string) $sheet['name']).'" sheetId="'.($index + 1).'" r:id="rId'.($index + 1).'"/>';
        }
        $branchEnd = max(count($branches) + 1, 2);
        $departmentEnd = max(count($departments) + 1, 2);
        $leaveEnd = max(count($leaveTypes) + 1, 2);

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<workbookPr date1904="0"/><sheets>'.$sheetXml.'</sheets><definedNames>'
            .'<definedName name="BranchCodes">\'Branches\'!$A$2:$A$'.$branchEnd.'</definedName>'
            .'<definedName name="DepartmentCodes">\'Departments\'!$A$2:$A$'.$departmentEnd.'</definedName>'
            .'<definedName name="LeaveTypeCodes">\'Leave Types\'!$A$2:$A$'.$leaveEnd.'</definedName>'
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
 <numFmts count="3"><numFmt numFmtId="164" formatCode="yyyy-mm-dd"/><numFmt numFmtId="165" formatCode="#,##0.00"/><numFmt numFmtId="166" formatCode="0.00"/></numFmts>
 <fonts count="4"><font><sz val="11"/><name val="Aptos"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Aptos"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="18"/><name val="Aptos Display"/></font><font><b/><color rgb="FF0F172A"/><sz val="12"/><name val="Aptos"/></font></fonts>
 <fills count="5"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF0F766E"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FF0F172A"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE2E8F0"/><bgColor indexed="64"/></patternFill></fill></fills>
 <borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left/><right/><top/><bottom style="thin"><color rgb="FFCBD5E1"/></bottom><diagonal/></border></borders>
 <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
 <cellXfs count="8"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="2" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center"/></xf><xf numFmtId="0" fontId="3" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="166" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf></cellXfs>
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
            $style = $number === 1 ? 2 : (in_array($number, [4, 11, 19], true) ? 3 : 7);
            $cells = '';
            foreach ($row as $column => $value) {
                $cells .= $this->stringCell($this->column($column + 1).$number, $value, $style);
            }
            $height = $number === 1 ? 34 : (in_array($number, [4, 11, 19], true) ? 24 : 20);
            $rowXml .= '<row r="'.$number.'" ht="'.$height.'" customHeight="1">'.$cells.'</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetViews><sheetView showGridLines="0" workbookViewId="0"/></sheetViews><cols><col min="1" max="1" width="22" customWidth="1"/><col min="2" max="2" width="100" customWidth="1"/></cols>'
            .'<sheetData>'.$rowXml.'</sheetData><mergeCells count="4"><mergeCell ref="A1:F1"/><mergeCell ref="A4:F4"/><mergeCell ref="A11:F11"/><mergeCell ref="A19:F19"/></mergeCells></worksheet>';
    }

    /** @param array<int, string> $headers @param array<int, array<int, string>> $rows */
    private function tableSheetXml(string $name, array $headers, array $rows, string $kind): string
    {
        $columns = '';
        foreach ($headers as $index => $header) {
            $style = $this->columnStyle($header);
            $width = max(14, min(30, strlen($header) + 3));
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
        $validations = $kind === 'data' ? $this->validationsXml($name, $headers) : '';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetPr><tabColor rgb="'.($kind === 'lookup' ? 'FF64748B' : 'FF0F766E').'"/></sheetPr><sheetViews><sheetView showGridLines="0" workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            .'<cols>'.$columns.'</cols><sheetData>'.$rowXml.'</sheetData><autoFilter ref="A1:'.$lastColumn.'1"/>'.$validations.'</worksheet>';
    }

    /** @param array<int, string> $headers */
    private function validationsXml(string $sheet, array $headers): string
    {
        $rules = [];
        $add = function (string $header, string $formula) use (&$rules, $headers): void {
            $index = array_search($header, $headers, true);
            if ($index !== false) {
                $column = $this->column($index + 1);
                $rules[] = '<dataValidation type="list" allowBlank="1" showErrorMessage="1" errorTitle="Invalid value" error="Choose a value allowed by this template." sqref="'.$column.'2:'.$column.'5001"><formula1>'.$this->escape($formula).'</formula1></dataValidation>';
            }
        };
        if ($sheet === 'Employees') {
            $add('employment_type', '"full_time,part_time,temporary,contractor"');
            $add('employment_status', '"onboarding,active,suspended,notice,exited,archived"');
        }
        if ($sheet === 'Assignments') {
            $add('branch_code', 'BranchCodes');
            $add('department_code', 'DepartmentCodes');
            $add('employment_type', '"full_time,part_time,temporary,contractor"');
        }
        if (in_array($sheet, ['Compensation', 'Payroll History'], true)) {
            $add('currency', '"QAR"');
        }
        if ($sheet === 'Compensation') {
            $add('pay_frequency', '"monthly"');
        }
        if (in_array($sheet, ['Compensation Components', 'Payroll Components'], true)) {
            $add('category', '"basic,allowance,earning,deduction"');
            $add('is_taxable', '"TRUE,FALSE"');
        }
        if (in_array($sheet, ['Leave Balances', 'Leave History'], true)) {
            $add('leave_type_code', 'LeaveTypeCodes');
        }
        if ($sheet === 'Leave History') {
            $add('start_portion', '"full,half"');
            $add('end_portion', '"full,half"');
            $add('status', '"approved,rejected,cancelled"');
        }

        return $rules === [] ? '' : '<dataValidations count="'.count($rules).'">'.implode('', $rules).'</dataValidations>';
    }

    private function columnStyle(string $header): int
    {
        if (str_ends_with($header, '_date') || str_ends_with($header, '_at') || str_contains($header, 'date_') || str_starts_with($header, 'pay_period_') || str_starts_with($header, 'effective_')) {
            return 4;
        }
        if (str_ends_with($header, '_qar')) {
            return 5;
        }
        if (str_contains($header, 'days') || $header === 'proration_divisor') {
            return 6;
        }

        return 0;
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
