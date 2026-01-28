<?php

use App\Models\PettyCashWallet;
use App\Models\User;
use App\Services\PettyCash\PettyCashIssueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('blocks issue on inactive wallet', function () {
    $user = User::factory()->create(['status' => 'active']);
    $wallet = PettyCashWallet::factory()->create(['active' => false]);
    $service = app(PettyCashIssueService::class);

    expect(fn () => $service->createIssue($wallet->id, [
        'issue_date' => now()->toDateString(),
        'amount' => 10,
        'method' => 'cash',
    ], $user->id))->toThrow(ValidationException::class);
});

it('increases wallet balance when issue is created', function () {
    $user = User::factory()->create(['status' => 'active']);
    $wallet = PettyCashWallet::factory()->create(['balance' => 10, 'active' => true]);
    $service = app(PettyCashIssueService::class);

    $service->createIssue($wallet->id, [
        'issue_date' => now()->toDateString(),
        'amount' => 5,
        'method' => 'cash',
    ], $user->id);

    expect((float) $wallet->refresh()->balance)->toBe(15.0);
});
