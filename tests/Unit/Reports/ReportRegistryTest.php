<?php

use App\Support\Reports\ReportRegistry;

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
    ]);
});

it('leaves only expense reports in the expenses category', function () {
    $reports = ReportRegistry::allInCategory('expenses');

    expect($reports->pluck('key')->all())->toBe([
        'expenses',
    ]);
});
