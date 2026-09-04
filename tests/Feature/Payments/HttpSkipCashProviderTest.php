<?php

use App\Services\Payments\HttpSkipCashProvider;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

it('normalizes a SkipCash Qatar wall clock timestamp that carries a UTC suffix', function (): void {
    Carbon::setTestNow('2026-09-04T04:54:16+00:00');
    Config::set('payments.skipcash.base_url', 'https://skipcash.example.test');
    Config::set('payments.skipcash.client_id', 'sandbox-client');
    Config::set('payments.skipcash.timezone', 'Asia/Qatar');
    Http::fake([
        'https://skipcash.example.test/api/v1/payments/payment-1' => Http::response([
            'resultObj' => [
                'id' => 'payment-1',
                'transactionId' => 'transaction-1',
                'amount' => '65.00',
                'currency' => 'QAR',
                'statusId' => 2,
                'finishedDate' => '2026-09-04T07:52:37Z',
            ],
        ]),
    ]);

    try {
        $details = app(HttpSkipCashProvider::class)->details('payment-1');

        expect($details['finished_at'])->toBe('2026-09-04T04:52:37+00:00');
    } finally {
        Carbon::setTestNow();
    }
});

it('preserves a plausible SkipCash UTC finish timestamp', function (): void {
    Carbon::setTestNow('2026-09-04T04:54:16+00:00');
    Config::set('payments.skipcash.base_url', 'https://skipcash.example.test');
    Config::set('payments.skipcash.client_id', 'sandbox-client');
    Config::set('payments.skipcash.timezone', 'Asia/Qatar');
    Http::fake([
        'https://skipcash.example.test/api/v1/payments/payment-2' => Http::response([
            'resultObj' => [
                'id' => 'payment-2',
                'transactionId' => 'transaction-2',
                'amount' => '65.00',
                'currency' => 'QAR',
                'statusId' => 2,
                'finishedDate' => '2026-09-04T04:52:37Z',
            ],
        ]),
    ]);

    try {
        $details = app(HttpSkipCashProvider::class)->details('payment-2');

        expect($details['finished_at'])->toBe('2026-09-04T04:52:37Z');
    } finally {
        Carbon::setTestNow();
    }
});
