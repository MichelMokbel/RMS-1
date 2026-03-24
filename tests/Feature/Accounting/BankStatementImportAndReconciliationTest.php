<?php

use App\Models\AccountingCompany;
use App\Models\AccountingPeriod;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin');
    $this->user = User::factory()->create();
    $this->user->assignRole('admin');
});

it('imports bank statement lines and reconciles matching book transactions', function () {
    Storage::fake('local');

    $company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $bankAccount = BankAccount::query()->where('company_id', $company->id)->firstOrFail();
    $period = AccountingPeriod::query()->where('company_id', $company->id)->orderBy('period_number')->firstOrFail();

    $bookTransaction = BankTransaction::query()->create([
        'company_id' => $company->id,
        'bank_account_id' => $bankAccount->id,
        'period_id' => $period->id,
        'reconciliation_run_id' => null,
        'transaction_type' => 'ap_payment',
        'transaction_date' => '2026-03-10',
        'amount' => 125.00,
        'direction' => 'outflow',
        'status' => 'open',
        'is_cleared' => false,
        'cleared_date' => null,
        'reference' => 'CHK-1001',
        'memo' => 'Supplier payment',
        'source_type' => 'test',
        'source_id' => 1001,
        'statement_import_id' => null,
    ]);

    $statement = UploadedFile::fake()->createWithContent(
        'statement.csv',
        "date,amount,reference,memo\n2026-03-10,-125,CHK-1001,Supplier payment\n"
    );

    $importResponse = $this->actingAs($this->user)->postJson(route('api.accounting.banking.imports.store'), [
        'bank_account_id' => $bankAccount->id,
        'statement_file' => $statement,
    ])->assertCreated();

    $importId = (int) $importResponse->json('import.id');

    $this->actingAs($this->user)->postJson(route('api.accounting.banking.reconciliations.store'), [
        'bank_account_id' => $bankAccount->id,
        'statement_import_id' => $importId,
        'statement_date' => '2026-03-10',
        'statement_ending_balance' => -125.00,
    ])->assertCreated()
        ->assertJsonPath('matched_count', 1)
        ->assertJsonPath('unmatched_count', 0);

    expect($bookTransaction->fresh())
        ->is_cleared->toBeTrue()
        ->status->toBe('reconciled');

    expect(BankTransaction::query()
        ->where('statement_import_id', $importId)
        ->where('status', 'matched')
        ->exists())->toBeTrue();
});

it('supports manual reconciliation review actions', function () {
    Storage::fake('local');

    $company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $bankAccount = BankAccount::query()->where('company_id', $company->id)->firstOrFail();
    $period = AccountingPeriod::query()->where('company_id', $company->id)->orderBy('period_number')->firstOrFail();

    $bookTransaction = BankTransaction::query()->create([
        'company_id' => $company->id,
        'bank_account_id' => $bankAccount->id,
        'period_id' => $period->id,
        'reconciliation_run_id' => null,
        'matched_bank_transaction_id' => null,
        'transaction_type' => 'ap_payment',
        'transaction_date' => '2026-03-12',
        'amount' => 80.00,
        'direction' => 'outflow',
        'status' => 'open',
        'is_cleared' => false,
        'cleared_date' => null,
        'reference' => 'BOOK-REF',
        'memo' => 'Supplier payment',
        'source_type' => 'test',
        'source_id' => 2001,
        'statement_import_id' => null,
    ]);

    $statement = UploadedFile::fake()->createWithContent(
        'statement-review.csv',
        "date,amount,reference,memo\n2026-03-12,-80,STMT-REF,Supplier payment\n"
    );

    $importId = (int) $this->actingAs($this->user)
        ->postJson(route('api.accounting.banking.imports.store'), [
            'bank_account_id' => $bankAccount->id,
            'statement_file' => $statement,
        ])
        ->assertCreated()
        ->json('import.id');

    $reconcile = $this->actingAs($this->user)
        ->postJson(route('api.accounting.banking.reconciliations.store'), [
            'bank_account_id' => $bankAccount->id,
            'statement_import_id' => $importId,
            'statement_date' => '2026-03-12',
            'statement_ending_balance' => -80.00,
        ])
        ->assertCreated()
        ->assertJsonPath('run.status', 'review');

    $runId = (int) $reconcile->json('run.id');
    $statementTransaction = BankTransaction::query()
        ->where('statement_import_id', $importId)
        ->firstOrFail();

    $this->actingAs($this->user)
        ->postJson(route('api.accounting.banking.reconciliations.match', $runId), [
            'statement_transaction_id' => $statementTransaction->id,
            'book_transaction_id' => $bookTransaction->id,
        ])
        ->assertOk()
        ->assertJsonPath('matched_count', 1)
        ->assertJsonPath('unmatched_count', 0);

    $statementTransaction->refresh();
    $bookTransaction->refresh();
    expect($statementTransaction->matched_bank_transaction_id)->toBe($bookTransaction->id)
        ->and($bookTransaction->matched_bank_transaction_id)->toBe($statementTransaction->id);

    $this->actingAs($this->user)
        ->postJson(route('api.accounting.banking.reconciliations.close', $runId))
        ->assertOk()
        ->assertJsonPath('run.status', 'completed');

    $this->actingAs($this->user)
        ->postJson(route('api.accounting.banking.reconciliations.reopen', $runId))
        ->assertOk()
        ->assertJsonPath('run.status', 'review');

    $this->actingAs($this->user)
        ->postJson(route('api.accounting.banking.reconciliations.unmatch', $runId), [
            'transaction_id' => $statementTransaction->id,
        ])
        ->assertOk()
        ->assertJsonPath('matched_count', 0);

    $this->actingAs($this->user)
        ->postJson(route('api.accounting.banking.reconciliations.exception', $runId), [
            'statement_transaction_id' => $statementTransaction->id,
        ])
        ->assertOk()
        ->assertJsonPath('exception_count', 1);
});
