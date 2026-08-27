import fs from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

import {convertPettyCashSource} from './petty-cash-source-converter.mjs';

// Run with @oai/artifact-tool installed, or point ARTIFACT_TOOL_MODULE at its ESM entry file.
const artifactToolModule = process.env.ARTIFACT_TOOL_MODULE || '@oai/artifact-tool';
const {FileBlob, SpreadsheetFile, Workbook} = await import(artifactToolModule);
const scriptDirectory = path.dirname(fileURLToPath(import.meta.url));
const projectRoot = path.resolve(scriptDirectory, '../..');
const sourcePath = path.join(projectRoot, 'docs/categories of expenses.xlsx');
const docsOutput = path.join(projectRoot, 'docs/petty-cash-bulk-july-august-2026.xlsx');
const outputDir = path.join(projectRoot, 'outputs/multi-day-petty-cash-import');
const outputPath = path.join(outputDir, 'petty-cash-bulk-july-august-2026.xlsx');

await fs.mkdir(path.join(outputDir, 'previews', 'source'), {recursive: true});
await fs.mkdir(path.join(outputDir, 'previews', 'final'), {recursive: true});

const sourceWorkbook = await SpreadsheetFile.importXlsx(await FileBlob.load(sourcePath));
for (const [sheetName, range] of [
    ['Sheet1', 'B3:O46'],
    ['July 2026', 'A1:AH45'],
    ['Aug 2026', 'A1:AH45'],
]) {
    const preview = await sourceWorkbook.render({sheetName, range, scale: 1, format: 'png'});
    await fs.writeFile(
        path.join(outputDir, 'previews', 'source', `${sheetName.replaceAll(' ', '-')}.png`),
        new Uint8Array(await preview.arrayBuffer()),
    );
}

const taxonomy = sourceWorkbook.worksheets.getItem('Sheet1').getRange('B3:O46');
const julyRange = sourceWorkbook.worksheets.getItem('July 2026').getRange('A1:AH137');
const augustRange = sourceWorkbook.worksheets.getItem('Aug 2026').getRange('A1:AH185');
const converted = convertPettyCashSource({
    taxonomyValues: taxonomy.values,
    july: {values: julyRange.values, formulas: julyRange.formulas},
    august: {values: augustRange.values, formulas: augustRange.formulas},
});

const workbook = Workbook.create();
const instructions = workbook.worksheets.add('Instructions');
const expenses = workbook.worksheets.add('Petty Cash Expenses');
const definitions = workbook.worksheets.add('Category Definitions');
const suppliers = workbook.worksheets.add('Suppliers');
const categories = workbook.worksheets.add('Categories');
const wallets = workbook.worksheets.add('Wallets');

const purple = '#6D28D9';
const dark = '#172033';
const gray = '#E2E8F0';
const light = '#F8FAFC';
const green = '#DCFCE7';
const border = '#CBD5E1';

instructions.showGridLines = false;
instructions.getRange('A1:B1').merge();
instructions.getRange('A1:B1').values = [['Petty Cash Multi-Day Import — July & August 2026', null]];
instructions.getRange('A1:B1').format = {
    fill: dark,
    font: {bold: true, color: '#FFFFFF', size: 18},
    verticalAlignment: 'center',
};
instructions.getRange('A1:B1').format.rowHeight = 32;
instructions.getRange('A3:B3').values = [['Workbook totals', 'QAR']];
instructions.getRange('A4:B6').values = [
    ['July 2026', converted.reconciliation.julyTotal],
    ['August 2026', converted.reconciliation.augustTotal],
    ['Combined total', converted.reconciliation.total],
];
instructions.getRange('A3:B3').format = {fill: purple, font: {bold: true, color: '#FFFFFF'}};
instructions.getRange('A4:B6').format.borders = {preset: 'inside', style: 'thin', color: border};
instructions.getRange('B4:B6').format.numberFormat = '#,##0.00';
instructions.getRange('A6:B6').format = {
    fill: green,
    font: {bold: true, color: '#166534'},
    borders: {preset: 'doubleBottom', style: 'thin', color: '#16A34A'},
};
const instructionRows = [
    ['How to use this file', ''],
    ['Upload mode', 'Choose Multiple Dates on the Expense Imports page.'],
    ['Upload defaults', 'Select a default supplier and wallet. Select the default paid state; blank workbook cells use those defaults.'],
    ['Invoice grouping', 'Rows sharing business_date and entry_id form one invoice. Each invoice represents one date and one main category.'],
    ['Line items', 'Descriptions use the source subcategory or expense label. Quantity is 1 and unit_price is the source amount.'],
    ['Categories', 'All 19 main categories are declared on Category Definitions. Missing active categories are proposed during review and created only on commit.'],
    ['Due dates', 'Blank due dates default to the business date.'],
    ['Review', 'Correct supplier, wallet, paid state, dates, categories, and lines on the review dashboard before commit.'],
    ['Source safety', 'This workbook contains static values only. Source formulas and summary rollups were not copied.'],
    ['Reconciliation', `July and August reconcile exactly; August includes QAR ${converted.reconciliation.transferFees.toFixed(2)} of Transfer fees.`],
    ['Source inclusion', 'Aug 2026!Q148 (QAR 619.00) is included as an expense by user instruction.'],
    ['Headers', 'Do not rename sheets or change the Petty Cash Expenses and Category Definitions headers.'],
];
instructions.getRange(`A8:B${7 + instructionRows.length}`).values = instructionRows;
instructions.getRange('A8:B8').format = {fill: purple, font: {bold: true, color: '#FFFFFF'}};
instructions.getRange(`A9:A${7 + instructionRows.length}`).format.font = {bold: true, color: dark};
instructions.getRange(`A8:B${7 + instructionRows.length}`).format.wrapText = true;
instructions.getRange(`A8:B${7 + instructionRows.length}`).format.borders = {preset: 'inside', style: 'thin', color: border};
instructions.getRange('A:A').format.columnWidth = 24;
instructions.getRange('B:B').format.columnWidth = 78;

const expenseHeaders = [
    'business_date', 'entry_id', 'supplier', 'reference_number', 'due_date', 'category',
    'wallet', 'paid', 'description', 'quantity', 'unit_price', 'notes',
];
const expenseRows = converted.rows.map((row) => [
    new Date(`${row.business_date}T00:00:00Z`), row.entry_id, row.supplier, row.reference_number,
    row.due_date, row.category, row.wallet, row.paid, row.description, row.quantity,
    row.unit_price, row.notes,
]);
expenses.getRange('A1:L1').values = [expenseHeaders];
expenses.getRange(`A2:L${expenseRows.length + 1}`).values = expenseRows;
expenses.getRange('A1:L1').format = {
    fill: purple,
    font: {bold: true, color: '#FFFFFF'},
    verticalAlignment: 'center',
    wrapText: true,
};
expenses.getRange('A1:L1').format.rowHeight = 30;
expenses.getRange(`A2:A${expenseRows.length + 1}`).format.numberFormat = 'yyyy-mm-dd';
expenses.getRange(`E2:E${expenseRows.length + 1}`).format.numberFormat = 'yyyy-mm-dd';
expenses.getRange(`J2:J${expenseRows.length + 1}`).format.numberFormat = '0.####';
expenses.getRange(`K2:K${expenseRows.length + 1}`).format.numberFormat = '#,##0.00';
expenses.getRange(`A2:L${expenseRows.length + 1}`).format.verticalAlignment = 'top';
expenses.getRange(`I2:I${expenseRows.length + 1}`).format.wrapText = true;
expenses.getRange(`L2:L${expenseRows.length + 1}`).format.wrapText = true;
let previousEntry = '';
for (let index = 0; index < converted.rows.length; index += 1) {
    const rowNumber = index + 2;
    const currentEntry = converted.rows[index].entry_id;
    if (currentEntry !== previousEntry) {
        expenses.getRange(`A${rowNumber}:L${rowNumber}`).format = {
            fill: gray,
            font: {bold: true, color: dark},
            borders: {top: {style: 'medium', color: '#64748B'}},
        };
    } else {
        expenses.getRange(`A${rowNumber}:L${rowNumber}`).format.fill = index % 2 === 0 ? '#FFFFFF' : light;
    }
    previousEntry = currentEntry;
}
expenses.freezePanes.freezeRows(1);
expenses.freezePanes.freezeColumns(2);
expenses.getRange(`H2:H${expenseRows.length + 100}`).dataValidation = {
    rule: {type: 'list', values: ['TRUE', 'FALSE']},
};
expenses.getRange(`J2:J${expenseRows.length + 100}`).dataValidation = {
    rule: {type: 'decimal', operator: 'greaterThan', formula1: 0},
};
expenses.getRange(`K2:K${expenseRows.length + 100}`).dataValidation = {
    rule: {type: 'decimal', operator: 'greaterThanOrEqual', formula1: 0},
};
expenses.tables.add(`A1:L${expenseRows.length + 1}`, true, 'PettyCashBulkExpenses');
expenses.getRange('A:A').format.columnWidth = 14;
expenses.getRange('B:B').format.columnWidth = 18;
expenses.getRange('C:C').format.columnWidth = 25;
expenses.getRange('D:D').format.columnWidth = 21;
expenses.getRange('E:E').format.columnWidth = 14;
expenses.getRange('F:F').format.columnWidth = 28;
expenses.getRange('G:G').format.columnWidth = 22;
expenses.getRange('H:H').format.columnWidth = 10;
expenses.getRange('I:I').format.columnWidth = 38;
expenses.getRange('J:K').format.columnWidth = 13;
expenses.getRange('L:L').format.columnWidth = 34;

definitions.getRange('A1:B1').values = [['code', 'name']];
definitions.getRange('A2:B20').values = converted.categories.map((category) => [String(category.code), category.name]);
definitions.getRange('A1:B1').format = {fill: purple, font: {bold: true, color: '#FFFFFF'}};
definitions.getRange('A2:B20').format.borders = {preset: 'inside', style: 'thin', color: border};
definitions.getRange('A:A').format.columnWidth = 12;
definitions.getRange('B:B').format.columnWidth = 42;
definitions.freezePanes.freezeRows(1);
definitions.tables.add('A1:B20', true, 'PettyCashCategoryDefinitions');

for (const [sheet, header, width] of [
    [suppliers, 'supplier', 42],
    [categories, 'category', 42],
    [wallets, 'wallet', 42],
]) {
    sheet.getRange('A1').values = [[header]];
    sheet.getRange('A1').format = {fill: dark, font: {bold: true, color: '#FFFFFF'}};
    sheet.getRange('A:A').format.columnWidth = width;
    sheet.showGridLines = false;
}

for (const [sheetName, range] of [
    ['Instructions', 'A1:B19'],
    ['Petty Cash Expenses', 'A1:L40'],
    ['Category Definitions', 'A1:B20'],
    ['Suppliers', 'A1:A2'],
    ['Categories', 'A1:A2'],
    ['Wallets', 'A1:A2'],
]) {
    const preview = await workbook.render({sheetName, range, scale: 1.4, format: 'png'});
    await fs.writeFile(
        path.join(outputDir, 'previews', 'final', `${sheetName.replaceAll(' ', '-')}.png`),
        new Uint8Array(await preview.arrayBuffer()),
    );
}

const inspections = [];
for (const [sheetId, range, tableMaxRows, tableMaxCols, maxChars] of [
    ['Instructions', 'A1:B19', 20, 3, 8_000],
    ['Petty Cash Expenses', 'A1:L12', 12, 12, 12_000],
    ['Category Definitions', 'A1:B20', 20, 2, 8_000],
]) {
    inspections.push((await workbook.inspect({
        kind: 'table', sheetId, range, include: 'values,formulas',
        tableMaxRows, tableMaxCols, maxChars,
    })).ndjson);
}
inspections.push((await workbook.inspect({
    kind: 'match',
    searchTerm: '#REF!|#DIV/0!|#VALUE!|#NAME\\?|#N/A',
    options: {useRegex: true, maxResults: 300},
    summary: 'final formula error scan',
})).ndjson);
inspections.push((await workbook.inspect({kind: 'formula', options: {maxResults: 50}, maxChars: 5_000})).ndjson);
await fs.writeFile(path.join(outputDir, 'verification.ndjson'), inspections.join('\n'));
await fs.writeFile(path.join(outputDir, 'reconciliation.json'), JSON.stringify({
    source: 'docs/categories of expenses.xlsx',
    sourceSheets: ['Sheet1', 'July 2026', 'Aug 2026'],
    ignoredSheets: ['Aug Cost Analysis'],
    rowCount: converted.rows.length,
    categoryCount: converted.categories.length,
    ...converted.reconciliation,
}, null, 2));

const xlsx = await SpreadsheetFile.exportXlsx(workbook);
await xlsx.save(outputPath);
await fs.copyFile(outputPath, docsOutput);
console.log(JSON.stringify({
    outputPath,
    docsOutput,
    rowCount: converted.rows.length,
    ...converted.reconciliation,
}, null, 2));
