<?php

namespace App\Support\Money;

final class MinorUnits
{
    /**
     * Convert a fixed-scale decimal string (e.g. "12.345") into minor units.
     * Default scale is 1000 to match existing app money precision (3 decimals).
     *
     * @throws \InvalidArgumentException
     */
    public static function parse(string $amount, int $scale = 1000): int
    {
        $amount = trim($amount);
        if ($amount === '') {
            return 0;
        }

        $negative = false;
        if (str_starts_with($amount, '-')) {
            $negative = true;
            $amount = ltrim($amount, '-');
        }

        $digits = self::scaleDigits($scale);

        // Remove any thousands separators.
        $amount = str_replace(',', '', $amount);

        if (! preg_match('/^\d+(\.\d+)?$/', $amount)) {
            throw new \InvalidArgumentException('Invalid decimal amount: '.$amount);
        }

        [$whole, $frac] = array_pad(explode('.', $amount, 2), 2, '');
        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;

        // Round half-up if more fractional digits than scale.
        if (strlen($frac) > $digits) {
            $carry = (int) ($frac[$digits] ?? '0') >= 5 ? 1 : 0;
            $frac = substr($frac, 0, $digits);

            $n = (int) ($whole.$frac);
            $n += $carry;

            return $negative ? -$n : $n;
        }

        $frac = str_pad($frac, $digits, '0', STR_PAD_RIGHT);

        $n = (int) ($whole.$frac);

        return $negative ? -$n : $n;
    }

    /**
     * Parse a quantity like "1.250" into milli-units (3 decimal places).
     */
    public static function parseQtyMilli(string $qty): int
    {
        return self::parse($qty, 1000);
    }

    public static function mulQty(int $unitCents, int $qtyMilli): int
    {
        // Round half-up to cents.
        $prod = $unitCents * $qtyMilli;
        $sign = $prod < 0 ? -1 : 1;
        $prodAbs = abs($prod);

        $q = intdiv($prodAbs, 1000);
        $r = $prodAbs % 1000;
        if ($r >= 500) {
            $q++;
        }

        return $q * $sign;
    }

    public static function percentBps(int $baseCents, int $bps): int
    {
        // Round half-up.
        $prod = $baseCents * $bps;
        $sign = $prod < 0 ? -1 : 1;
        $prodAbs = abs($prod);

        $q = intdiv($prodAbs, 10000);
        $r = $prodAbs % 10000;
        if ($r >= 5000) {
            $q++;
        }

        return $q * $sign;
    }

    /**
     * Format minor units as decimal string for display.
     * When scale is null, uses config pos.money_scale (e.g. 100 for QAR 2 decimals).
     *
     * @param  bool  $withCurrency  When true, appends " {currency}" from config (POS only).
     */
    public static function format(int $minorUnits, ?int $scale = null, bool $withCurrency = false): string
    {
        $scale = $scale ?? self::posScale();
        $digits = self::scaleDigits($scale);
        $sign = $minorUnits < 0 ? '-' : '';
        $n = abs($minorUnits);
        $whole = intdiv($n, $scale);
        $frac = $n % $scale;
        $fracStr = str_pad((string) $frac, $digits, '0', STR_PAD_LEFT);
        $out = $sign.$whole.'.'.$fracStr;
        if ($withCurrency && config('pos.currency')) {
            $out .= ' '.config('pos.currency');
        }

        return $out;
    }

    /**
     * Money scale used by POS (e.g. 100 for QAR 2 decimals, 1000 for KWD 3 decimals).
     */
    public static function posScale(): int
    {
        return (int) config('pos.money_scale', 100);
    }

    /**
     * Parse a POS amount string using POS money scale (e.g. "12.34" => 1234 for QAR).
     *
     * @throws \InvalidArgumentException
     */
    public static function parsePos(string $amount): int
    {
        return self::parse($amount, self::posScale());
    }

    private static function scaleDigits(int $scale): int
    {
        $digits = 0;
        while ($scale > 1) {
            $scale = intdiv($scale, 10);
            $digits++;
        }
        return max(0, $digits);
    }
}

