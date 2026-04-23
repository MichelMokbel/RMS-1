<?php

use App\Models\AccountingCompany;
use App\Models\JournalEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('allows updating a draft journal entry at the app layer', function () {
    $company = AccountingCompany::query()->where('is_default', true)->firstOrFail();

    $journal = JournalEntry::query()->create([
        'company_id' => $company->id,
        'entry_date' => now()->toDateString(),
        'status' => 'draft',
        'entry_type' => 'manual',
        'memo' => 'Original memo',
    ]);

    $journal->memo = 'Updated memo';
    $journal->save();

    expect(JournalEntry::query()->find($journal->id)->memo)->toBe('Updated memo');
});

it('throws ValidationException when updating a posted journal entry via Eloquent', function () {
    $company = AccountingCompany::query()->where('is_default', true)->firstOrFail();

    $journal = JournalEntry::query()->create([
        'company_id' => $company->id,
        'entry_date' => now()->toDateString(),
        'status' => 'draft',
        'entry_type' => 'manual',
        'memo' => 'Original memo',
    ]);

    // Bypass app-layer hook to put the row into posted state.
    DB::table('journal_entries')->where('id', $journal->id)->update(['status' => 'posted']);
    $journal->refresh();

    expect(fn () => tap($journal)->fill(['memo' => 'tampered'])->save())
        ->toThrow(ValidationException::class);
});

it('fires DB-level trigger when a posted journal entry is updated via raw SQL on MySQL', function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('MySQL BEFORE UPDATE trigger test requires MySQL driver.');
    }

    $company = AccountingCompany::query()->where('is_default', true)->firstOrFail();

    $journal = JournalEntry::query()->create([
        'company_id' => $company->id,
        'entry_date' => now()->toDateString(),
        'status' => 'draft',
        'entry_type' => 'manual',
        'memo' => 'Original memo',
    ]);

    // Set to posted via raw SQL, bypassing all Eloquent observers.
    DB::table('journal_entries')->where('id', $journal->id)->update(['status' => 'posted']);

    // Raw SQL update on a posted row must be blocked by the DB trigger.
    expect(fn () => DB::table('journal_entries')->where('id', $journal->id)->update(['memo' => 'tampered']))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

it('allows a raw SQL update on a draft journal entry (trigger must not fire)', function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('MySQL BEFORE UPDATE trigger test requires MySQL driver.');
    }

    $company = AccountingCompany::query()->where('is_default', true)->firstOrFail();

    $journal = JournalEntry::query()->create([
        'company_id' => $company->id,
        'entry_date' => now()->toDateString(),
        'status' => 'draft',
        'entry_type' => 'manual',
        'memo' => 'Original memo',
    ]);

    DB::table('journal_entries')->where('id', $journal->id)->update(['memo' => 'updated by raw sql']);

    expect(JournalEntry::query()->find($journal->id)->memo)->toBe('updated by raw sql');
});
