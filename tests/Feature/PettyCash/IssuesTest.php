<?php

use App\Models\PettyCashWallet;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\SubledgerEntry;
use App\Models\User;
use App\Services\PettyCash\PettyCashIssueService;
use App\Services\PettyCash\PettyCashIssueVoidService;
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

it('creates a bank transaction and credits the selected bank ledger for bank-funded issues', function () {
    $user = User::factory()->create(['status' => 'active']);
    $wallet = PettyCashWallet::factory()->create(['balance' => 10, 'active' => true]);
    $bankAccount = BankAccount::factory()->create();
    $service = app(PettyCashIssueService::class);

    $issue = $service->createIssue($wallet->id, [
        'issue_date' => now()->toDateString(),
        'amount' => 25,
        'method' => 'bank_transfer',
        'bank_account_id' => $bankAccount->id,
    ], $user->id);

    expect((float) $wallet->fresh()->balance)->toBe(35.0);

    $entry = SubledgerEntry::query()
        ->where('source_type', 'petty_cash_issue')
        ->where('source_id', $issue->id)
        ->where('event', 'issue')
        ->firstOrFail();

    expect((int) $entry->lines()->where('credit', 25.0)->value('account_id'))->toBe((int) $bankAccount->ledger_account_id);

    $this->assertDatabaseHas('bank_transactions', [
        'source_type' => \App\Models\PettyCashIssue::class,
        'source_id' => $issue->id,
        'transaction_type' => 'petty_cash_issue',
        'bank_account_id' => $bankAccount->id,
        'status' => 'open',
    ]);
});

it('voids bank-funded issues and marks their bank transaction void', function () {
    $user = User::factory()->create(['status' => 'active']);
    $wallet = PettyCashWallet::factory()->create(['balance' => 0, 'active' => true]);
    $bankAccount = BankAccount::factory()->create();

    $issue = app(PettyCashIssueService::class)->createIssue($wallet->id, [
        'issue_date' => now()->toDateString(),
        'amount' => 30,
        'method' => 'bank_transfer',
        'bank_account_id' => $bankAccount->id,
    ], $user->id);

    app(PettyCashIssueVoidService::class)->void($issue, $user->id);

    expect((float) $wallet->fresh()->balance)->toBe(0.0);

    $this->assertDatabaseHas('bank_transactions', [
        'source_type' => \App\Models\PettyCashIssue::class,
        'source_id' => $issue->id,
        'transaction_type' => 'petty_cash_issue',
        'status' => 'void',
    ]);
});
