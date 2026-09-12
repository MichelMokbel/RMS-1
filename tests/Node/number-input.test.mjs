import test from 'node:test';
import assert from 'node:assert/strict';
import { incrementNumber, stepNumber } from '../../resources/js/number-input.js';

test('increments whole units while retaining decimal portions', () => {
    assert.equal(incrementNumber('1', 1, '0.001'), '2');
    assert.equal(incrementNumber('2.375', 1), '3.375');
    assert.equal(incrementNumber('2.375', -1), '1.375');
    assert.equal(incrementNumber('0.0001', 1), '1.0001');
    assert.equal(incrementNumber('', 1, '0.001'), '1');
});
test('respects lower and upper limits and negative values', () => {
    assert.equal(incrementNumber('0.5', -1, '0.001'), '0.001');
    assert.equal(incrementNumber('99.5', 1, '0', '100'), '100');
    assert.equal(incrementNumber('-2.5', -1), '-3.5');
});
test('notifies Livewire and Alpine and ignores disabled or readonly controls', () => {
    const events = [];
    const input = { value: '2.5', min: '0', max: '', dispatchEvent: event => events.push(event.type) };
    const root = { querySelector: () => input };
    stepNumber(root, 1);
    assert.equal(input.value, '3.5');
    assert.deepEqual(events, ['input', 'change']);
    input.disabled = true;
    stepNumber(root, 1);
    assert.equal(input.value, '3.5');
    input.disabled = false;
    input.readOnly = true;
    stepNumber(root, -1);
    assert.equal(input.value, '3.5');
});
