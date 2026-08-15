<?php

namespace App\Services\HR\Imports;

class FullHistoryValueParser
{
    public function minorUnits(mixed $value): ?int
    {
        $value = trim((string) $value);
        if (! preg_match('/^-?\d+(?:\.\d{1,2})?$/', $value)) {
            return null;
        }
        [$whole, $decimal] = array_pad(explode('.', $value, 2), 2, '');
        $negative = str_starts_with($whole, '-');
        $minor = abs((int) $whole) * 100 + (int) str_pad($decimal, 2, '0');

        return $negative ? -$minor : $minor;
    }

    public function boolean(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match (strtolower(trim((string) $value))) {
            '1', 'true', 'yes', 'y' => true,
            '0', 'false', 'no', 'n' => false,
            default => null,
        };
    }

    public function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    public function isDateTime(string $value): bool
    {
        if ($this->isDate($value)) {
            return true;
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:?\d{2})?$/', $value)) {
            return false;
        }
        try {
            new \DateTimeImmutable($value);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
