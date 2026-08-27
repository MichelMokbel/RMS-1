<?php

namespace App\Services\PettyCash;

use DateTimeImmutable;
use Illuminate\Support\Str;

class PettyCashImportValueParser
{
    public function tokenId(mixed $value): ?int
    {
        if (! is_scalar($value) || ! preg_match('/^\s*([1-9][0-9]*)\s*(?:\||$)/', (string) $value, $match)) {
            return null;
        }

        return (int) $match[1];
    }

    public function boolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return match (strtolower(trim((string) $value))) {
            'true', 'yes', '1' => true,
            'false', 'no', '0' => false,
            default => null,
        };
    }

    public function date(mixed $value): ?string
    {
        $raw = is_scalar($value) ? trim((string) $value) : '';
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);

        return $date && $date->format('Y-m-d') === $raw ? $raw : null;
    }

    public function decimal(mixed $value, int $scale, bool $allowZero): ?float
    {
        $raw = is_int($value) || is_float($value) ? (string) $value : trim((string) $value);
        if ($raw === '' || ! preg_match('/^\d+(?:\.\d{1,'.$scale.'})?$/', $raw)) {
            return null;
        }
        $number = round((float) $raw, $scale);

        return $number < 0 || (! $allowZero && $number <= 0) ? null : $number;
    }

    public function optionalText(mixed $value, int $max, string $field, array &$errors): ?string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > $max) {
            $errors[$field] = __(':Field may not exceed :max characters.', [
                'field' => Str::headline($field),
                'max' => $max,
            ]);
        }

        return $text;
    }
}
