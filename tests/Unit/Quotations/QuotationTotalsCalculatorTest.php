<?php

use App\Services\Quotations\QuotationTotalsCalculator;
use Illuminate\Validation\ValidationException;

uses(Tests\TestCase::class);

it('calculates three-decimal quantities and both discount levels in QAR minor units', function () {
    $totals = (new QuotationTotalsCalculator)->calculate([
        ['description' => 'Catering', 'quantity' => '2.125', 'unit_price_cents' => 1000, 'discount_cents' => 125],
        ['description' => 'Delivery', 'quantity' => '1.000', 'unit_price_cents' => 500, 'discount_cents' => 0],
    ], 'percentage', 1000);

    expect($totals)
        ->gross_subtotal_cents->toBe(2625)
        ->line_discount_total_cents->toBe(125)
        ->subtotal_cents->toBe(2500)
        ->quotation_discount_cents->toBe(250)
        ->discount_total_cents->toBe(375)
        ->total_cents->toBe(2250)
        ->and($totals['items'][0]['quantity'])->toBe('2.125')
        ->and($totals['items'][0]['line_total_cents'])->toBe(2000);
});

it('rounds fractional line amounts half up', function () {
    $totals = (new QuotationTotalsCalculator)->calculate([
        ['quantity' => '0.001', 'unit_price_cents' => 500, 'discount_cents' => 0],
    ]);

    expect($totals['total_cents'])->toBe(1);
});

it('rejects invalid precision and discounts above the line amount', function (array $item) {
    expect(fn () => (new QuotationTotalsCalculator)->calculate([$item]))
        ->toThrow(ValidationException::class);
})->with([
    'more than three decimals' => [['quantity' => '1.0001', 'unit_price_cents' => 100, 'discount_cents' => 0]],
    'discount above gross' => [['quantity' => '1.000', 'unit_price_cents' => 100, 'discount_cents' => 101]],
]);
