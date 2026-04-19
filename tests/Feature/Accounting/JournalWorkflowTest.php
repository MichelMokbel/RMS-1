<?php

use App\Models\AccountingCompany;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\LedgerAccount;
use App\Models\SubledgerEntry;
use App\Services\Accounting\JournalEntryService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin');
    $this->user = User::factory()->create(['status' => 'active']);
    $this->user->assignRole('admin');
});

it('creates drafts, posts journals, and creates posted reversals', function () {
    $company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $cash = LedgerAccount::query()->where('code', '1000')->firstOrFail();
    $expense = LedgerAccount::query()->where('code', '6000')->firstOrFail();

    $this->actingAs($this->user);

    Livewire::test('accounting.journals')
        ->set('company_id', $company->id)
        ->set('entry_date', '2026-03-20')
        ->set('memo', 'Month-end accrual')
        ->set('lines', [
            [
                'account_id' => $expense->id,
                'branch_id' => null,
                'department_id' => null,
                'job_id' => null,
                'debit' => 250,
                'credit' => 0,
                'memo' => 'Expense accrual',
            ],
            [
                'account_id' => $cash->id,
                'branch_id' => null,
                'department_id' => null,
                'job_id' => null,
                'debit' => 0,
                'credit' => 250,
                'memo' => 'Cash offset',
            ],
        ])
        ->call('saveDraft')
        ->assertHasNoErrors();

    $journal = JournalEntry::query()->latest('id')->firstOrFail();
    expect($journal->status)->toBe('draft');

    Livewire::test('accounting.journals')
        ->call('postJournal', $journal->id)
        ->assertHasNoErrors();

    $journal->refresh();
    expect($journal->status)->toBe('posted')
        ->and(SubledgerEntry::query()
            ->where('source_type', 'journal_entry')
            ->where('source_id', $journal->id)
            ->where('event', 'post')
            ->exists())->toBeTrue();

    Livewire::test('accounting.journals')
        ->call('reverseJournal', $journal->id)
        ->assertHasNoErrors();

    $reversal = JournalEntry::query()
        ->where('source_type', JournalEntry::class)
        ->where('source_id', $journal->id)
        ->latest('id')
        ->firstOrFail();

    expect($reversal->status)->toBe('posted')
        ->and($reversal->entry_type)->toBe('reversal')
        ->and(SubledgerEntry::query()
            ->where('source_type', 'journal_entry')
            ->where('source_id', $reversal->id)
            ->where('event', 'post')
            ->exists())->toBeTrue();

    expect(fn () => app(JournalEntryService::class)->reverse($journal->fresh(), $this->user->id))
        ->toThrow(ValidationException::class);
});

it('prevents mutation of posted journals and lines', function () {
    $company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $cash = LedgerAccount::query()->where('code', '1000')->firstOrFail();
    $expense = LedgerAccount::query()->where('code', '6000')->firstOrFail();

    /** @var JournalEntryService $service */
    $service = app(JournalEntryService::class);
    $journal = $service->saveDraft([
        'company_id' => $company->id,
        'entry_date' => '2026-03-20',
        'memo' => 'Immutable journal',
        'lines' => [
            [
                'account_id' => $expense->id,
                'debit' => 125,
                'credit' => 0,
                'memo' => 'Expense',
            ],
            [
                'account_id' => $cash->id,
                'debit' => 0,
                'credit' => 125,
                'memo' => 'Cash',
            ],
        ],
    ], $this->user->id);
    $journal = $service->post($journal, $this->user->id);
    $line = JournalEntryLine::query()->where('journal_entry_id', $journal->id)->firstOrFail();

    expect(function () use ($journal) {
        $journal->memo = 'Changed';
        $journal->save();
    })->toThrow(ValidationException::class);

    expect(function () use ($line) {
        $line->memo = 'Changed';
        $line->save();
    })->toThrow(ValidationException::class);
});
