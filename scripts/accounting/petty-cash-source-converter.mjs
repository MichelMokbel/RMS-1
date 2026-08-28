export const CONVERSION_TARGETS = Object.freeze({
    july: 149_692.73,
    august: 130_004.29,
});
const CENT_TOLERANCE = 0.005;
const USER_INCLUDED_SOURCE_CELLS = new Set(['Q148']);

export function isUserIncludedSourceCell(cellReference) {
    return USER_INCLUDED_SOURCE_CELLS.has(cellReference);
}

const AUGUST_SUMMARY_ROWS = new Map([
    [1, 2], [2, 16], [3, 31], [4, 41], [5, 54], [6, 63], [7, 67],
    [8, 73], [9, null], [10, 86], [11, 94], [12, 98], [13, 106],
    [14, 111], [15, 116], [16, 122], [17, 129], [18, 138], [19, 144],
]);

function text(value) {
    return value === null || value === undefined ? '' : String(value).trim();
}

function roundMoney(value) {
    return Math.round((Number(value) + Number.EPSILON) * 100) / 100;
}

function isMainCode(value) {
    return Number.isInteger(Number(value)) && Number(value) >= 1 && Number(value) <= 19;
}

function isConstantArithmeticFormula(formula) {
    return /^=[0-9+\-*/().\s]+$/.test(text(formula));
}

function excelSerialToIsoDate(serial) {
    if (!Number.isFinite(Number(serial))) {
        throw new Error(`Invalid Excel business-date serial [${serial}].`);
    }

    const epoch = Date.UTC(1899, 11, 30);
    const date = new Date(epoch + Math.round(Number(serial)) * 86_400_000);

    return date.toISOString().slice(0, 10);
}

function columnName(index) {
    let name = '';
    let current = index;
    while (current > 0) {
        current -= 1;
        name = String.fromCharCode(65 + (current % 26)) + name;
        current = Math.floor(current / 26);
    }

    return name;
}

function sourceCell(columnIndex, rowIndex) {
    return `${columnName(columnIndex + 1)}${rowIndex + 1}`;
}

export function extractCategoryDefinitions(taxonomyValues) {
    let unique = [];
    for (const offset of [0, 1]) {
        const categories = [];
        for (const row of taxonomyValues) {
            for (let codeColumn = offset; codeColumn < row.length; codeColumn += 3) {
                const code = Number(row[codeColumn]);
                const name = text(row[codeColumn + 1]);
                if (Number.isInteger(code) && code >= 1 && code <= 19 && name !== '') {
                    categories.push({code, name});
                }
            }
        }
        const candidate = [...new Map(categories.map((category) => [category.code, category])).values()]
            .sort((left, right) => left.code - right.code);
        if (candidate.length > unique.length) {
            unique = candidate;
        }
    }
    if (unique.length !== 19 || unique.some((category, index) => category.code !== index + 1)) {
        throw new Error('The source taxonomy must define each main category code from 1 through 19 exactly once.');
    }

    return unique;
}

function extractMonthLines({
    sheetName,
    values,
    formulas,
    dateRow,
    firstDataRow,
    lastDataRow,
    categories,
    skippedRows = new Set(),
    excludedCells = new Set(),
}) {
    const lines = [];
    let mainCode = 1;

    for (let rowNumber = firstDataRow; rowNumber <= lastDataRow; rowNumber += 1) {
        const rowIndex = rowNumber - 1;
        const sourceRow = values[rowIndex] ?? [];
        const formulaRow = formulas[rowIndex] ?? [];
        if (isMainCode(sourceRow[0])) {
            mainCode = Number(sourceRow[0]);
        }
        if (skippedRows.has(rowNumber)) {
            continue;
        }

        const description = text(sourceRow[1]);
        for (let columnIndex = 2; columnIndex <= 32; columnIndex += 1) {
            const cellReference = sourceCell(columnIndex, rowIndex);
            if (excludedCells.has(cellReference)) {
                continue;
            }
            const rawValue = sourceRow[columnIndex];
            const formula = text(formulaRow[columnIndex]);
            if (rawValue === null || rawValue === undefined || rawValue === '' || Number(rawValue) === 0) {
                continue;
            }
            if (!Number.isFinite(Number(rawValue))) {
                throw new Error(`${sheetName}!${cellReference} contains a non-numeric expense amount.`);
            }
            if (Number(rawValue) < 0) {
                throw new Error(`${sheetName}!${cellReference} contains a negative expense amount.`);
            }
            if (formula !== '' && !isConstantArithmeticFormula(formula)) {
                continue;
            }
            if (description === '') {
                throw new Error(`${sheetName}!${cellReference} has an amount without a description.`);
            }

            const dateSerial = values[dateRow - 1]?.[columnIndex];
            const category = categories[mainCode - 1];
            lines.push({
                businessDate: excelSerialToIsoDate(dateSerial),
                categoryCode: mainCode,
                categoryName: category.name,
                description,
                amount: roundMoney(Number(rawValue)),
                sourceSheet: sheetName,
                sourceCell: cellReference,
                sourceKind: formula === '' ? 'literal' : 'constant_formula',
                sourceOrder: (rowIndex + 1) * 100 + columnIndex,
            });
        }
    }

    return lines;
}

function addAugustUnallocatedLines(lines, values, categories) {
    const actual = new Map();
    for (const line of lines) {
        const key = `${line.businessDate}|${line.categoryCode}`;
        actual.set(key, roundMoney((actual.get(key) ?? 0) + line.amount));
    }

    for (const category of categories) {
        const summaryRow = AUGUST_SUMMARY_ROWS.get(category.code);
        for (let columnIndex = 2; columnIndex <= 32; columnIndex += 1) {
            const businessDate = excelSerialToIsoDate(values[0]?.[columnIndex]);
            const expected = summaryRow === null
                ? 0
                : roundMoney(Number(values[summaryRow - 1]?.[columnIndex] ?? 0));
            const key = `${businessDate}|${category.code}`;
            const explained = actual.get(key) ?? 0;
            const difference = roundMoney(expected - explained);
            if (difference > CENT_TOLERANCE) {
                lines.push({
                    businessDate,
                    categoryCode: category.code,
                    categoryName: category.name,
                    description: `Unallocated – ${category.name}`,
                    amount: difference,
                    sourceSheet: 'Aug 2026',
                    sourceCell: summaryRow === null ? '' : sourceCell(columnIndex, summaryRow - 1),
                    sourceKind: 'reconciliation_gap',
                    sourceOrder: 99_999,
                });
            }
        }
    }

    const convertedByDate = new Map();
    for (const line of lines) {
        convertedByDate.set(
            line.businessDate,
            roundMoney((convertedByDate.get(line.businessDate) ?? 0) + line.amount),
        );
    }
    for (let columnIndex = 2; columnIndex <= 32; columnIndex += 1) {
        const businessDate = excelSerialToIsoDate(values[0]?.[columnIndex]);
        const userIncludedAdjustment = roundMoney(lines
            .filter((line) => line.businessDate === businessDate && isUserIncludedSourceCell(line.sourceCell))
            .reduce((sum, line) => sum + line.amount, 0));
        const sourceDayTotal = roundMoney(Number(values[153]?.[columnIndex] ?? 0) + userIncludedAdjustment);
        const convertedDayTotal = convertedByDate.get(businessDate) ?? 0;
        if (Math.abs(sourceDayTotal - convertedDayTotal) > CENT_TOLERANCE) {
            throw new Error(`August daily total for ${businessDate} does not reconcile: converted ${convertedDayTotal.toFixed(2)}, source ${sourceDayTotal.toFixed(2)}.`);
        }
    }
}

function total(lines) {
    return roundMoney(lines.reduce((sum, line) => sum + line.amount, 0));
}

function assertTarget(month, actual, target) {
    if (Math.abs(actual - target) > CENT_TOLERANCE) {
        throw new Error(`${month} converted total ${actual.toFixed(2)} does not reconcile to ${target.toFixed(2)}.`);
    }
}

function canonicalRows(lines) {
    return lines
        .sort((left, right) => left.businessDate.localeCompare(right.businessDate)
            || left.categoryCode - right.categoryCode
            || left.sourceSheet.localeCompare(right.sourceSheet)
            || left.sourceOrder - right.sourceOrder)
        .map((line) => ({
            business_date: line.businessDate,
            entry_id: `${line.businessDate.replaceAll('-', '')}-${String(line.categoryCode).padStart(2, '0')}`,
            supplier: '',
            reference_number: '',
            due_date: '',
            category: line.categoryName,
            wallet: '',
            paid: '',
            description: line.description,
            quantity: 1,
            unit_price: line.amount,
            notes: `Source: ${line.sourceSheet}!${line.sourceCell}${line.sourceKind === 'reconciliation_gap' ? ' (unallocated reconciliation)' : ''}`,
        }));
}

export function convertPettyCashSource({taxonomyValues, july, august}) {
    const categories = extractCategoryDefinitions(taxonomyValues);
    const julyLines = extractMonthLines({
        sheetName: 'July 2026',
        values: july.values,
        formulas: july.formulas,
        dateRow: 2,
        firstDataRow: 3,
        lastDataRow: 136,
        categories,
    });
    const augustLines = extractMonthLines({
        sheetName: 'Aug 2026',
        values: august.values,
        formulas: august.formulas,
        dateRow: 1,
        firstDataRow: 2,
        lastDataRow: 153,
        categories,
        skippedRows: new Set([...AUGUST_SUMMARY_ROWS.values()].filter((row) => row !== null)),
    });
    addAugustUnallocatedLines(augustLines, august.values, categories);

    const julyTotal = total(julyLines);
    const augustTotal = total(augustLines);
    assertTarget('July 2026', julyTotal, CONVERSION_TARGETS.july);
    assertTarget('August 2026', augustTotal, CONVERSION_TARGETS.august);
    const transferFees = roundMoney(augustLines
        .filter((line) => line.description === 'Transfer fees')
        .reduce((sum, line) => sum + line.amount, 0));
    if (Math.abs(transferFees - 105.21) > CENT_TOLERANCE) {
        throw new Error(`August Transfer fees must reconcile to 105.21, received ${transferFees.toFixed(2)}.`);
    }

    return {
        categories,
        rows: canonicalRows([...julyLines, ...augustLines]),
        reconciliation: {
            julyTotal,
            augustTotal,
            total: roundMoney(julyTotal + augustTotal),
            transferFees,
            includedSourceCells: ['Aug 2026!Q148 (QAR 619.00; included by user instruction)'],
            excludedSourceCells: [],
            unallocatedLineCount: [...julyLines, ...augustLines]
                .filter((line) => line.sourceKind === 'reconciliation_gap').length,
        },
    };
}
