<?php

use App\Models\PettyCashExpense;
use App\Models\PettyCashWallet;
use App\Models\User;
use App\Services\PettyCash\PettyCashExpenseWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('computes total as amount plus tax', function () {
    $expense = PettyCashExpense::factory()->create([
        'amount' => 10,
        'tax_amount' => 2,
        'total_amount' => 0,
        'status' => 'draft',
    ]);

    $expense->recalcTotals();

    expect((float) $expense->refresh()->total_amount)->toBe(12.00);
});

it('approval reduces wallet balance, submission does not', function () {
    $user = User::factory()->create(['status' => 'active']);
    $wallet = PettyCashWallet::factory()->create(['balance' => 20]);
    $expense = PettyCashExpense::factory()->create([
        'wallet_id' => $wallet->id,
        'amount' => 5,
        'tax_amount' => 1,
        'total_amount' => 6,
        'status' => 'submitted',
    ]);

    $workflow = app(PettyCashExpenseWorkflowService::class);

    // approve
    $workflow->approve($expense, $user->id);
    expect((float) $wallet->refresh()->balance)->toBe(14.0);
});

it('reject does not affect balance', function () {
    $user = User::factory()->create(['status' => 'active']);
    $wallet = PettyCashWallet::factory()->create(['balance' => 15]);
    $expense = PettyCashExpense::factory()->create([
        'wallet_id' => $wallet->id,
        'total_amount' => 5,
        'status' => 'submitted',
    ]);

    $workflow = app(PettyCashExpenseWorkflowService::class);
    $workflow->reject($expense, $user->id);

    expect((float) $wallet->refresh()->balance)->toBe(15.0);
});

it('prevents negative balance when disallowed', function () {
    config()->set('petty_cash.allow_negative_wallet_balance', false);

    $user = User::factory()->create(['status' => 'active']);
    $wallet = PettyCashWallet::factory()->create(['balance' => 5]);
    $expense = PettyCashExpense::factory()->create([
        'wallet_id' => $wallet->id,
        'total_amount' => 10,
        'status' => 'submitted',
    ]);

    $workflow = app(PettyCashExpenseWorkflowService::class);

    expect(fn () => $workflow->approve($expense, $user->id))->toThrow(ValidationException::class);
});
