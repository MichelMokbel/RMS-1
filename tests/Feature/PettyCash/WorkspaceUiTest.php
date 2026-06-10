<?php

use App\Models\PettyCashWallet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('staff', 'web');
    Role::findOrCreate('manager', 'web');
    Permission::findOrCreate('finance.access', 'web');
});

it('creates wallets from the petty cash workspace at zero balance', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('admin');

    Volt::actingAs($user);

    Volt::test('petty-cash.index')
        ->set('walletForm.driver_name', 'Runner Wallet')
        ->set('walletForm.driver_id', 501)
        ->set('walletForm.target_float', 125)
        ->set('walletForm.active', true)
        ->call('saveWallet');

    $wallet = PettyCashWallet::query()->where('driver_name', 'Runner Wallet')->firstOrFail();

    expect((float) $wallet->balance)->toBe(0.0)
        ->and((float) $wallet->target_float)->toBe(125.0)
        ->and((bool) $wallet->active)->toBeTrue();
});

it('updates wallet float from the petty cash workspace without changing balance', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('admin');

    $wallet = PettyCashWallet::factory()->create([
        'driver_name' => 'Courier Wallet',
        'target_float' => 50,
        'balance' => 22,
        'active' => true,
    ]);

    Volt::actingAs($user);

    Volt::test('petty-cash.index')
        ->call('editWallet', $wallet->id)
        ->set('walletForm.target_float', 90)
        ->set('walletForm.active', true)
        ->call('saveWallet');

    expect((float) $wallet->fresh()->target_float)->toBe(90.0)
        ->and((float) $wallet->fresh()->balance)->toBe(22.0);
});
