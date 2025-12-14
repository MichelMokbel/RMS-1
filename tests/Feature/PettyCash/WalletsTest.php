<?php

use App\Models\PettyCashWallet;
use App\Models\User;
use App\Services\PettyCash\PettyCashWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('creates wallet with creator via service', function () {
    $user = User::factory()->create();
    $service = app(PettyCashWalletService::class);

    $wallet = $service->create([
        'driver_id' => 123,
        'driver_name' => 'Driver',
        'target_float' => 50,
        'balance' => 10,
        'active' => true,
    ], $user->id);

    expect($wallet->refresh()->created_by)->toBe($user->id);
});
