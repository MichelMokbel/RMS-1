import assert from 'node:assert/strict';
import test from 'node:test';

import {
    CONVERSION_TARGETS,
    convertPettyCashSource,
    extractCategoryDefinitions,
    isUserIncludedSourceCell,
} from '../../scripts/accounting/petty-cash-source-converter.mjs';

test('extracts all 19 main expense categories from parallel taxonomy columns', () => {
    const rows = Array.from({length: 4}, () => Array(15).fill(null));
    for (let code = 1; code <= 19; code += 1) {
        const group = Math.floor((code - 1) / 4);
        const row = (code - 1) % 4;
        rows[row][1 + group * 3] = code;
        rows[row][2 + group * 3] = `Category ${code}`;
    }

    const categories = extractCategoryDefinitions(rows);

    assert.equal(categories.length, 19);
    assert.deepEqual(categories[0], {code: 1, name: 'Category 1'});
    assert.deepEqual(categories[18], {code: 19, name: 'Category 19'});
});

test('also accepts a taxonomy range cropped to its first populated code column', () => {
    const rows = Array.from({length: 4}, () => Array(14).fill(null));
    for (let code = 1; code <= 19; code += 1) {
        const group = Math.floor((code - 1) / 4);
        const row = (code - 1) % 4;
        rows[row][group * 3] = code;
        rows[row][group * 3 + 1] = `Category ${code}`;
    }

    assert.equal(extractCategoryDefinitions(rows).length, 19);
});

test('rejects incomplete taxonomy definitions', () => {
    assert.throws(
        () => extractCategoryDefinitions([[1, 'Raw material']]),
        /define each main category code from 1 through 19/,
    );
});

test('converter source does not expose the summary-analysis sheet', () => {
    assert.equal(convertPettyCashSource.length, 1);
});

test('includes the requested August Q148 expense in the revised target', () => {
    assert.equal(isUserIncludedSourceCell('Q148'), true);
    assert.equal(CONVERSION_TARGETS.august, 130_004.29);
});
