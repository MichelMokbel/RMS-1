<?php

use App\Models\LedgerAccount;
use App\Models\SubledgerEntry;
use App\Models\SubledgerLine;
use App\Models\User;
use App\Services\Finance\FinanceSettingsService;
use App\Services\Ledger\GlBatchPostingService;
use App\Services\Ledger\GlSummaryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

it('generates and posts a gl batch and can close the period', function () {
    if (! Schema::hasTable('subledger_entries') || ! Schema::hasTable('subledger_lines') || ! Schema::hasTable('gl_batches')) {
        $this->markTestSkipped('Ledger tables not available.');
    }

    $cash = LedgerAccount::firstOrCreate(['code' => '1000'], ['name' => 'Cash', 'type' => 'asset', 'is_active' => 1]);
    $exp = LedgerAccount::firstOrCreate(['code' => '6000'], ['name' => 'General Expense', 'type' => 'expense', 'is_active' => 1]);

    $user = User::factory()->create();

    $date = Carbon::create(2026, 1, 10)->toDateString();

    $entry = SubledgerEntry::create([
        'source_type' => 'test',
        'source_id' => 1,
        'event' => 'seed',
        'entry_date' => $date,
        'description' => 'Test entry',
        'branch_id' => 1,
        'status' => 'posted',
        'posted_at' => now(),
        'posted_by' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    SubledgerLine::create([
        'entry_id' => $entry->id,
        'account_id' => $exp->id,
        'debit' => 100,
        'credit' => 0,
        'memo' => 'Expense',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    SubledgerLine::create([
        'entry_id' => $entry->id,
        'account_id' => $cash->id,
        'debit' => 0,
        'credit' => 100,
        'memo' => 'Cash',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $periodStart = Carbon::create(2026, 1, 1);
    $periodEnd = Carbon::create(2026, 1, 31);

    $batch = app(GlSummaryService::class)->generateForPeriod($periodStart, $periodEnd, $user->id);
    expect($batch->lines)->not->toBeEmpty();

    // Ensure lock date starts empty (or at least <= period end after posting).
    $finance = app(FinanceSettingsService::class);
    $finance->setLockDate(null, null);

    $posted = app(GlBatchPostingService::class)->post($batch, $user->id, true);
    expect($posted->status)->toBe('posted');

    $lock = $finance->getLockDate();
    expect($lock)->toBe($periodEnd->toDateString());
});

