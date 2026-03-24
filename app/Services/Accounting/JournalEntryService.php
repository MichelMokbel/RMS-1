<?php

namespace App\Services\Accounting;

use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Services\Ledger\SubledgerService;
use App\Services\Sequences\DocumentSequenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class JournalEntryService
{
    public function __construct(
        protected AccountingContextService $context,
        protected AccountingPeriodGateService $periodGate,
        protected AccountingAuditLogService $auditLog,
        protected SubledgerService $subledgerService,
        protected DocumentSequenceService $sequences,
        protected JobCostingService $jobCostingService
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function saveDraft(array $payload, int $actorId, ?JournalEntry $journal = null): JournalEntry
    {
        return DB::transaction(function () use ($payload, $actorId, $journal) {
            $entryDate = (string) ($payload['entry_date'] ?? now()->toDateString());
            $companyId = $this->context->resolveCompanyId(null, $payload['company_id'] ?? $journal?->company_id);
            $periodId = $this->context->resolvePeriodId($entryDate, $companyId);
            $normalizedLines = $this->normalizeLines($payload['lines'] ?? []);
            $this->assertBalanced($normalizedLines);

            if ($journal) {
                $journal = JournalEntry::query()->lockForUpdate()->findOrFail($journal->id);
                if ($journal->status !== 'draft') {
                    throw ValidationException::withMessages([
                        'journal' => __('Only draft journal entries can be edited.'),
                    ]);
                }
            }

            $entry = $journal ?: new JournalEntry();
            if (! $entry->exists) {
                $entry->entry_number = $this->nextEntryNumber($companyId, $entryDate);
                $entry->created_by = $actorId;
            }

            $entry->fill([
                'company_id' => $companyId,
                'period_id' => $periodId,
                'entry_type' => (string) ($payload['entry_type'] ?? 'manual'),
                'entry_date' => $entryDate,
                'status' => 'draft',
                'memo' => $payload['memo'] ?? null,
            ]);
            $entry->save();

            $entry->lines()->delete();
            foreach ($normalizedLines as $line) {
                JournalEntryLine::query()->create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $line['account_id'],
                    'branch_id' => $line['branch_id'],
                    'department_id' => $line['department_id'],
                    'job_id' => $line['job_id'],
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                    'memo' => $line['memo'],
                ]);
            }

            $this->auditLog->log(
                $journal ? 'journal.updated' : 'journal.created',
                $actorId,
                $entry,
                [
                    'entry_number' => $entry->entry_number,
                    'line_count' => count($normalizedLines),
                ],
                $companyId
            );

            return $entry->fresh(['lines.account', 'company', 'period']);
        });
    }

    public function post(JournalEntry $journal, int $actorId): JournalEntry
    {
        return DB::transaction(function () use ($journal, $actorId) {
            $journal = JournalEntry::query()->with('lines')->lockForUpdate()->findOrFail($journal->id);

            if ($journal->status !== 'draft') {
                throw ValidationException::withMessages([
                    'journal' => __('Only draft journal entries can be posted.'),
                ]);
            }

            $normalizedLines = $this->normalizeLines($journal->lines->map(fn (JournalEntryLine $line) => [
                'account_id' => $line->account_id,
                'branch_id' => $line->branch_id,
                'department_id' => $line->department_id,
                'job_id' => $line->job_id,
                'debit' => $line->debit,
                'credit' => $line->credit,
                'memo' => $line->memo,
            ])->all());
            $this->assertBalanced($normalizedLines);

            $periodId = $this->context->resolvePeriodId(optional($journal->entry_date)->toDateString(), (int) $journal->company_id);
            $this->periodGate->assertDateOpen(
                optional($journal->entry_date)->toDateString() ?? now()->toDateString(),
                (int) $journal->company_id,
                $periodId,
                'ledger',
                'entry_date'
            );

            $journal->forceFill([
                'period_id' => $periodId,
                'status' => 'posted',
                'posted_at' => now(),
                'posted_by' => $actorId,
            ])->save();

            $journal = $journal->fresh('lines');
            $this->subledgerService->recordJournalEntry($journal, $actorId);
            $this->recordJobTransactions($journal, $actorId);
            $this->auditLog->log('journal.posted', $actorId, $journal, [
                'entry_number' => $journal->entry_number,
            ], (int) $journal->company_id);

            return $journal->fresh(['lines.account', 'company', 'period', 'postedBy']);
        });
    }

    public function reverse(JournalEntry $journal, int $actorId, ?string $reversalDate = null, ?string $memo = null): JournalEntry
    {
        return DB::transaction(function () use ($journal, $actorId, $reversalDate, $memo) {
            $journal = JournalEntry::query()->with('lines')->findOrFail($journal->id);
            if ($journal->status !== 'posted') {
                throw ValidationException::withMessages([
                    'journal' => __('Only posted journal entries can be reversed.'),
                ]);
            }

            $date = $reversalDate ?: now()->toDateString();
            $periodId = $this->context->resolvePeriodId($date, (int) $journal->company_id);
            $this->periodGate->assertDateOpen($date, (int) $journal->company_id, $periodId, 'ledger', 'reversal_date');

            $reversal = JournalEntry::query()->create([
                'company_id' => $journal->company_id,
                'period_id' => $periodId,
                'entry_number' => $this->nextEntryNumber((int) $journal->company_id, $date),
                'entry_type' => 'reversal',
                'entry_date' => $date,
                'status' => 'posted',
                'source_type' => JournalEntry::class,
                'source_id' => $journal->id,
                'memo' => $memo ?: __('Reversal of :entry', ['entry' => $journal->entry_number]),
                'posted_at' => now(),
                'posted_by' => $actorId,
                'created_by' => $actorId,
            ]);

            foreach ($journal->lines as $line) {
                JournalEntryLine::query()->create([
                    'journal_entry_id' => $reversal->id,
                    'account_id' => $line->account_id,
                    'branch_id' => $line->branch_id,
                    'department_id' => $line->department_id,
                    'job_id' => $line->job_id,
                    'debit' => $line->credit,
                    'credit' => $line->debit,
                    'memo' => $line->memo ? __('Reversal: :memo', ['memo' => $line->memo]) : __('Reversal'),
                ]);
            }

            $reversal = $reversal->fresh('lines');
            $this->subledgerService->recordJournalEntry($reversal, $actorId);
            $this->recordJobTransactions($reversal, $actorId);
            $this->auditLog->log('journal.reversed', $actorId, $reversal, [
                'reversed_journal_id' => (int) $journal->id,
                'reversed_entry_number' => $journal->entry_number,
            ], (int) $journal->company_id);

            return $reversal->fresh(['lines.account', 'company', 'period', 'postedBy']);
        });
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @return array<int, array<string, mixed>>
     */
    private function normalizeLines(array $lines): array
    {
        $normalized = collect($lines)
            ->map(function ($line) {
                return [
                    'account_id' => (int) ($line['account_id'] ?? 0),
                    'branch_id' => ($line['branch_id'] ?? null) !== null && (int) $line['branch_id'] > 0 ? (int) $line['branch_id'] : null,
                    'department_id' => ($line['department_id'] ?? null) !== null && (int) $line['department_id'] > 0 ? (int) $line['department_id'] : null,
                    'job_id' => ($line['job_id'] ?? null) !== null && (int) $line['job_id'] > 0 ? (int) $line['job_id'] : null,
                    'debit' => round((float) ($line['debit'] ?? 0), 2),
                    'credit' => round((float) ($line['credit'] ?? 0), 2),
                    'memo' => $line['memo'] ?? null,
                ];
            })
            ->filter(fn (array $line) => $line['account_id'] > 0 && ($line['debit'] > 0 || $line['credit'] > 0))
            ->values()
            ->all();

        if ($normalized === []) {
            throw ValidationException::withMessages([
                'lines' => __('At least one journal line is required.'),
            ]);
        }

        foreach ($normalized as $index => $line) {
            if ($line['debit'] > 0 && $line['credit'] > 0) {
                throw ValidationException::withMessages([
                    "lines.$index" => __('A journal line cannot contain both debit and credit.'),
                ]);
            }
        }

        return $normalized;
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     */
    private function assertBalanced(array $lines): void
    {
        $debits = round(collect($lines)->sum('debit'), 2);
        $credits = round(collect($lines)->sum('credit'), 2);

        if (count($lines) < 2) {
            throw ValidationException::withMessages([
                'lines' => __('A journal entry must contain at least two lines.'),
            ]);
        }

        if (abs($debits - $credits) > 0.009) {
            throw ValidationException::withMessages([
                'lines' => __('Journal entry is not balanced. Debits must equal credits.'),
            ]);
        }
    }

    private function nextEntryNumber(int $companyId, string $entryDate): string
    {
        $year = date('Y', strtotime($entryDate));
        $sequence = $this->sequences->next('journal_entry', max($companyId, 1), $year);

        return sprintf('JRN-%s-%04d', $year, $sequence);
    }

    private function recordJobTransactions(JournalEntry $journal, int $actorId): void
    {
        $journal->loadMissing('lines.job');

        foreach ($journal->lines as $line) {
            if (! $line->job_id || ! $line->job) {
                continue;
            }

            if ((float) $line->debit > 0) {
                $this->jobCostingService->recordSourceTransaction($line->job, [
                    'transaction_date' => optional($journal->entry_date)->toDateString() ?? now()->toDateString(),
                    'amount' => (float) $line->debit,
                    'transaction_type' => 'adjustment',
                    'job_phase_id' => null,
                    'job_cost_code_id' => null,
                    'memo' => $line->memo ?: __('Journal debit :entry', ['entry' => $journal->entry_number]),
                ], JournalEntry::class, (int) $journal->id, $actorId);
            }

            if ((float) $line->credit > 0) {
                $this->jobCostingService->recordSourceTransaction($line->job, [
                    'transaction_date' => optional($journal->entry_date)->toDateString() ?? now()->toDateString(),
                    'amount' => (float) $line->credit,
                    'transaction_type' => 'revenue',
                    'job_phase_id' => null,
                    'job_cost_code_id' => null,
                    'memo' => $line->memo ?: __('Journal credit :entry', ['entry' => $journal->entry_number]),
                ], JournalEntry::class, (int) $journal->id, $actorId);
            }
        }
    }
}
