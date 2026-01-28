<?php

use App\Models\PettyCashWallet;
use App\Models\User;
use App\Services\PettyCash\PettyCashReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('captures expected balance and variance on reconciliation', function () {
    $user = User::factory()->create(['status' => 'active']);
    $wallet = PettyCashWallet::factory()->create(['balance' => 50]);
    $service = app(PettyCashReconciliationService::class);

    $recon = $service->reconcile($wallet->id, [
        'period_start' => now()->subDay()->toDateString(),
        'period_end' => now()->toDateString(),
        'counted_balance' => 45,
        'note' => 'Count',
    ], $user->id);

    expect((float) $recon->expected_balance)->toBe(50.00)
        ->and((float) $recon->variance)->toBe(-5.00);
});

it('applies reconciliation to wallet balance when enabled', function () {
    config()->set('petty_cash.apply_reconciliation_to_wallet_balance', true);
    $user = User::factory()->create(['status' => 'active']);
    $wallet = PettyCashWallet::factory()->create(['balance' => 30]);
    $service = app(PettyCashReconciliationService::class);

    $service->reconcile($wallet->id, [
        'period_start' => now()->subDay()->toDateString(),
        'period_end' => now()->toDateString(),
        'counted_balance' => 40,
    ], $user->id);

    expect((float) $wallet->refresh()->balance)->toBe(40.0);
});

it('does not apply reconciliation to wallet balance when disabled', function () {
    config()->set('petty_cash.apply_reconciliation_to_wallet_balance', false);
    $user = User::factory()->create(['status' => 'active']);
    $wallet = PettyCashWallet::factory()->create(['balance' => 30]);
    $service = app(PettyCashReconciliationService::class);

    $service->reconcile($wallet->id, [
        'period_start' => now()->subDay()->toDateString(),
        'period_end' => now()->toDateString(),
        'counted_balance' => 40,
    ], $user->id);

    expect((float) $wallet->refresh()->balance)->toBe(30.0);
});
