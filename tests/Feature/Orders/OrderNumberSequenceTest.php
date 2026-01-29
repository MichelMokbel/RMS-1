<?php

use App\Services\Orders\OrderNumberService;
use Illuminate\Support\Carbon;

it('generates sequential order numbers', function () {
    Carbon::setTestNow(Carbon::create(2026, 1, 15, 12, 0, 0));

    $svc = app(OrderNumberService::class);

    $a = $svc->generate();
    $b = $svc->generate();
    $c = $svc->generate();

    expect($a)->toStartWith('ORD2026-');
    expect($b)->toStartWith('ORD2026-');
    expect($c)->toStartWith('ORD2026-');

    $na = (int) substr($a, -6);
    $nb = (int) substr($b, -6);
    $nc = (int) substr($c, -6);

    expect($nb)->toBe($na + 1);
    expect($nc)->toBe($nb + 1);
});

