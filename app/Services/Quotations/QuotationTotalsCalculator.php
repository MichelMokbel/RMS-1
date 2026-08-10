<?php

namespace App\Services\Quotations;

use Illuminate\Validation\ValidationException;

class QuotationTotalsCalculator
{
    /**
     * Percentage discount values are basis points (10_000 = 100%).
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public function calculate(array $items, ?string $discountType = null, int $discountValue = 0): array
    {
        $normalized = [];
        $grossSubtotal = 0;
        $lineDiscountTotal = 0;

        foreach (array_values($items) as $index => $item) {
            $quantity = $this->normalizeQuantity($item['quantity'] ?? $item['qty'] ?? 0, $index);
            $quantityMills = $this->quantityMills($quantity);
            $unitPrice = (int) ($item['unit_price_cents'] ?? $item['unit_price_minor'] ?? 0);
            $lineDiscount = (int) ($item['discount_cents'] ?? $item['discount_minor'] ?? $item['line_discount_minor'] ?? 0);

            if ($unitPrice < 0 || $lineDiscount < 0) {
                throw ValidationException::withMessages(["items.$index" => __('Prices and discounts cannot be negative.')]);
            }
            if ($quantityMills > 0 && $unitPrice > intdiv(PHP_INT_MAX - 500, $quantityMills)) {
                throw ValidationException::withMessages(["items.$index.unit_price_cents" => __('The line amount is too large.')]);
            }

            $gross = intdiv(($unitPrice * $quantityMills) + 500, 1000);
            if ($lineDiscount > $gross) {
                throw ValidationException::withMessages(["items.$index.discount_cents" => __('Line discount cannot exceed the line amount.')]);
            }

            $normalized[] = array_merge($item, [
                'quantity' => $quantity,
                'unit_price_cents' => $unitPrice,
                'discount_cents' => $lineDiscount,
                'line_total_cents' => $gross - $lineDiscount,
                'sort_order' => (int) ($item['sort_order'] ?? $index),
            ]);
            $grossSubtotal += $gross;
            $lineDiscountTotal += $lineDiscount;
        }

        $subtotal = $grossSubtotal - $lineDiscountTotal;
        $quotationDiscount = $this->quotationDiscount($subtotal, $discountType, $discountValue);

        return [
            'items' => $normalized,
            'gross_subtotal_cents' => $grossSubtotal,
            'line_discount_total_cents' => $lineDiscountTotal,
            'subtotal_cents' => $subtotal,
            'quotation_discount_cents' => $quotationDiscount,
            'discount_total_cents' => $lineDiscountTotal + $quotationDiscount,
            'total_cents' => $subtotal - $quotationDiscount,
        ];
    }

    private function quotationDiscount(int $subtotal, ?string $type, int $value): int
    {
        $type = blank($type) ? null : strtolower((string) $type);
        if ($type === null) {
            return 0;
        }
        if (! in_array($type, ['fixed', 'percentage'], true)) {
            throw ValidationException::withMessages(['quotation_discount_type' => __('Invalid quotation discount type.')]);
        }
        if ($value < 0 || ($type === 'percentage' && $value > 10000)) {
            throw ValidationException::withMessages(['quotation_discount_value' => __('Invalid quotation discount value.')]);
        }

        $discount = $type === 'fixed'
            ? $value
            : intdiv(($subtotal * $value) + 5000, 10000);

        if ($discount > $subtotal) {
            throw ValidationException::withMessages(['quotation_discount_value' => __('Quotation discount cannot exceed the subtotal.')]);
        }

        return $discount;
    }

    private function normalizeQuantity(mixed $quantity, int $index): string
    {
        $value = trim((string) $quantity);
        if (! preg_match('/^\d{1,11}(?:\.\d{1,3})?$/', $value) || (float) $value <= 0) {
            throw ValidationException::withMessages(["items.$index.quantity" => __('Quantity must be positive with at most three decimals.')]);
        }

        return number_format((float) $value, 3, '.', '');
    }

    private function quantityMills(string $quantity): int
    {
        [$whole, $fraction] = array_pad(explode('.', $quantity, 2), 2, '');

        return ((int) $whole * 1000) + (int) str_pad(substr($fraction, 0, 3), 3, '0');
    }
}
