<?php

use App\Models\ApInvoice;
use App\Services\AP\ApReportsService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('calculates aging buckets', function () {
    $service = app(ApReportsService::class);

    // Current
    ApInvoice::factory()->create([
        'due_date' => now()->addDays(5)->toDateString(),
        'status' => 'posted',
        'total_amount' => 100,
        'tax_amount' => 0,
        'subtotal' => 100,
    ]);

    // 1-30 overdue
    ApInvoice::factory()->create([
        'due_date' => now()->subDays(10)->toDateString(),
        'status' => 'posted',
        'total_amount' => 200,
        'tax_amount' => 0,
        'subtotal' => 200,
    ]);

    $aging = $service->agingSummary();
    expect($aging['current'])->toBeGreaterThan(0);
    expect($aging['1_30'])->toBeGreaterThan(0);
});
