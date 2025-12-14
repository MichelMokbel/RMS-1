<?php

use App\Models\PettyCashWallet;
use App\Services\PettyCash\PettyCashReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('captures expected balance and variance on reconciliation', function () {
    $wallet = PettyCashWallet::factory()->create(['balance' => 50]);
    $service = app(PettyCashReconciliationService::class);

    $recon = $service->reconcile($wallet->id, [
        'period_start' => now()->subDay()->toDateString(),
        'period_end' => now()->toDateString(),
        'counted_balance' => 45,
        'note' => 'Count',
    ], 1);

    expect((float) $recon->expected_balance)->toBe(50.00)
        ->and((float) $recon->variance)->toBe(-5.00);
});

it('applies reconciliation to wallet balance when enabled', function () {
    config()->set('petty_cash.apply_reconciliation_to_wallet_balance', true);
    $wallet = PettyCashWallet::factory()->create(['balance' => 30]);
    $service = app(PettyCashReconciliationService::class);

    $service->reconcile($wallet->id, [
        'period_start' => now()->subDay()->toDateString(),
        'period_end' => now()->toDateString(),
        'counted_balance' => 40,
    ], 1);

    expect($wallet->refresh()->balance)->toBe(40.00);
});

it('does not apply reconciliation to wallet balance when disabled', function () {
    config()->set('petty_cash.apply_reconciliation_to_wallet_balance', false);
    $wallet = PettyCashWallet::factory()->create(['balance' => 30]);
    $service = app(PettyCashReconciliationService::class);

    $service->reconcile($wallet->id, [
        'period_start' => now()->subDay()->toDateString(),
        'period_end' => now()->toDateString(),
        'counted_balance' => 40,
    ], 1);

    expect($wallet->refresh()->balance)->toBe(30.00);
});
