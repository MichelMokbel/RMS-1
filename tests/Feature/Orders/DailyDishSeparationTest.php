<?php

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('separates subscription vs manual daily dish orders', function () {
    Order::factory()->subscription()->create([
        'scheduled_date' => '2025-01-10',
        'branch_id' => 1,
        'total_amount' => 10,
    ]);
    Order::factory()->dailyDish()->create([
        'source' => 'Backoffice',
        'scheduled_date' => '2025-01-10',
        'branch_id' => 1,
        'total_amount' => 20,
    ]);

    $subs = Order::query()
        ->whereDate('scheduled_date', '2025-01-10')
        ->where('branch_id', 1)
        ->where('is_daily_dish', 1)
        ->where('source', 'Subscription')
        ->get();

    $manual = Order::query()
        ->whereDate('scheduled_date', '2025-01-10')
        ->where('branch_id', 1)
        ->where('is_daily_dish', 1)
        ->where('source', '!=', 'Subscription')
        ->get();

    expect($subs)->toHaveCount(1);
    expect($manual)->toHaveCount(1);
});

