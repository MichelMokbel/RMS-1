<?php

namespace App\Services\PettyCash;

use Illuminate\Support\Str;

class PettyCashImportRowPreparer
{
    /** @var array<int, string> */
    private const INVOICE_FIELDS = [
        'supplier', 'reference_number', 'due_date', 'category',
        'wallet', 'paid', 'tax_amount', 'notes',
    ];

    /** @var array<int, string> */
    private const IMPORT_FIELDS = [
        ...self::INVOICE_FIELDS,
        'description', 'quantity', 'unit_price',
    ];

    /**
     * Ignore unused prefilled slots and inherit invoice fields into active
     * continuation rows without overwriting explicit values.
     *
     * @param  array<int, array<string, mixed>>  $sourceRows
     * @return array<int, array<string, mixed>>
     */
    public function prepare(array $sourceRows): array
    {
        $prepared = [];
        $currentEntryId = null;

        foreach ($sourceRows as $offset => $source) {
            $entryId = Str::upper(trim((string) ($source['entry_id'] ?? '')));
            if ($entryId !== '') {
                $currentEntryId = $entryId;
            }
            if (! $this->hasImportInput($source)) {
                continue;
            }
            if ($entryId === '' && $currentEntryId !== null) {
                $source['entry_id'] = $currentEntryId;
            }
            $prepared[$offset] = $source;
        }

        $groups = [];
        foreach ($prepared as $offset => $source) {
            $entryId = Str::upper(trim((string) ($source['entry_id'] ?? '')));
            if ($entryId !== '') {
                $groups[$entryId][] = $offset;
            }
        }

        foreach ($groups as $indexes) {
            foreach (self::INVOICE_FIELDS as $field) {
                $inherited = null;
                $found = false;
                foreach ($indexes as $index) {
                    if ($this->hasValue($prepared[$index][$field] ?? null)) {
                        $inherited = $prepared[$index][$field];
                        $found = true;
                        break;
                    }
                }
                if (! $found) {
                    continue;
                }
                foreach ($indexes as $index) {
                    if (! $this->hasValue($prepared[$index][$field] ?? null)) {
                        $prepared[$index][$field] = $inherited;
                    }
                }
            }
        }

        return $prepared;
    }

    /** @param array<string, mixed> $source */
    private function hasImportInput(array $source): bool
    {
        foreach (self::IMPORT_FIELDS as $field) {
            if ($this->hasValue($source[$field] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function hasValue(mixed $value): bool
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return false;
        }

        return is_scalar($value);
    }
}
