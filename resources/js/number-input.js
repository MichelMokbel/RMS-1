// Spinner increments are independent of the precision accepted by the input.
export function incrementNumber(value, direction, min = '', max = '') {
    const current = value === '' ? 0 : Number(value);
    if (!Number.isFinite(current)) return value;
    let next = Number((current + direction).toFixed(12));
    if (min !== '' && Number.isFinite(Number(min))) next = Math.max(next, Number(min));
    if (max !== '' && Number.isFinite(Number(max))) next = Math.min(next, Number(max));
    return String(next);
}

export function stepNumber(root, direction) {
    const input = root.querySelector('input[type="number"]');
    if (!input || input.disabled || input.readOnly) return;
    const next = incrementNumber(input.value, direction, input.min, input.max);
    if (next === input.value) return;
    input.value = next;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
}

if (typeof window !== 'undefined') window.rmsStepNumber = stepNumber;
