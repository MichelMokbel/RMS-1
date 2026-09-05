<?php

use App\Support\Reports\ReportRegistry;

uses(Tests\TestCase::class);

it('registers an inventory report category', function () {
    $categories = ReportRegistry::categories();

    expect($categories->pluck('key'))->toContain('inventory');
    expect($categories->firstWhere('key', 'inventory')['label'])->toBe('Inventory');
});

it('groups inventory-related reports under the inventory category', function () {
    $reports = ReportRegistry::allInCategory('inventory');

    expect($reports->pluck('key')->all())->toBe([
        'costing',
        'inventory',
        'inventory-transactions',
        'purchase-order-inventory-list',
    ]);
});

it('groups expense reports under the expenses category', function () {
    $reports = ReportRegistry::allInCategory('expenses');

    expect($reports->pluck('key')->all())->toBe([
        'expenses',
        'expenses-by-category',
    ]);
});
