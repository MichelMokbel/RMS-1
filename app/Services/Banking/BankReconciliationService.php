<?php

namespace App\Services\Banking;

use App\Models\BankAccount;
use App\Models\BankReconciliationRun;
use App\Models\BankStatementImport;
use App\Models\BankTransaction;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\Accounting\AccountingContextService;
use App\Services\Accounting\AccountingPeriodGateService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BankReconciliationService
{
    public function __construct(
        protected AccountingContextService $context,
        protected AccountingPeriodGateService $periodGate,
        protected AccountingAuditLogService $auditLog
    ) {
    }

    public function reconcile(BankAccount $bankAccount, array $data, int $actorId): array
    {
        $statementDate = Carbon::parse((string) $data['statement_date'])->toDateString();
        $statementImport = $this->resolveStatementImport($bankAccount, $data['statement_import_id'] ?? null);
        $periodId = $this->context->resolvePeriodId($statementDate, (int) $bankAccount->company_id);
        $this->periodGate->assertDateOpen($statementDate, (int) $bankAccount->company_id, $periodId, 'banking', 'statement_date');

        $result = DB::transaction(function () use ($bankAccount, $statementImport, $statementDate, $data, $actorId, $periodId) {
            $bookEndingBalance = $this->bookEndingBalance($bankAccount, $statementDate);

            $run = BankReconciliationRun::query()->create([
                'bank_account_id' => $bankAccount->id,
                'company_id' => $bankAccount->company_id,
                'period_id' => $periodId,
                'statement_import_id' => $statementImport?->id,
                'statement_date' => $statementDate,
                'statement_ending_balance' => round((float) $data['statement_ending_balance'], 2),
                'book_ending_balance' => round($bookEndingBalance, 2),
                'variance_amount' => 0,
                'status' => 'draft',
                'completed_at' => null,
                'completed_by' => null,
            ]);

            $statementLines = $this->statementLines($bankAccount, $statementDate, $statementImport);
            $bookLines = $this->bookLines($bankAccount, $statementDate);
            $usedBookIds = [];
            $matched = 0;
            $unmatched = 0;

            foreach ($statementLines as $statementLine) {
                $match = $bookLines->first(function (BankTransaction $bookLine) use ($statementLine, $usedBookIds) {
                    if (in_array($bookLine->id, $usedBookIds, true)) {
                        return false;
                    }

                    if ((float) $bookLine->amount !== (float) $statementLine->amount) {
                        return false;
                    }

                    if ($bookLine->direction !== $statementLine->direction) {
                        return false;
                    }

                    $dayDiff = abs(Carbon::parse((string) $bookLine->transaction_date)->diffInDays($statementLine->transaction_date));
                    if ($dayDiff > 7) {
                        return false;
                    }

                    return $this->referencesComparable($bookLine->reference, $statementLine->reference);
                });

                if ($match) {
                    $usedBookIds[] = (int) $match->id;
                    $matched++;
                    $this->applyMatch($run, $statementLine, $match, $statementDate);
                } else {
                    $unmatched++;
                    $statementLine->forceFill([
                        'reconciliation_run_id' => $run->id,
                        'status' => 'exception',
                        'matched_bank_transaction_id' => null,
                    ])->save();
                }
            }

            $outstanding = $bookLines->whereNotIn('id', $usedBookIds)->count();
            $this->refreshRun($run, $actorId, true);

            return [
                'run' => $run->fresh(['transactions', 'statementImport']),
                'matched_count' => $matched,
                'unmatched_count' => $unmatched,
                'outstanding_count' => $outstanding,
            ];
        });

        $this->auditLog->log('bank_reconciliation.completed', $actorId, $result['run'], [
            'bank_account_id' => (int) $bankAccount->id,
            'matched_count' => $result['matched_count'],
            'unmatched_count' => $result['unmatched_count'],
            'outstanding_count' => $result['outstanding_count'],
            'statement_import_id' => $result['run']->statement_import_id,
        ], (int) $bankAccount->company_id);

        return $result;
    }

    public function match(BankReconciliationRun $run, int $statementTransactionId, int $bookTransactionId, int $actorId): array
    {
        return DB::transaction(function () use ($run, $statementTransactionId, $bookTransactionId, $actorId) {
            $run = BankReconciliationRun::query()->lockForUpdate()->findOrFail($run->id);
            $this->periodGate->assertDateOpen(
                $run->statement_date?->toDateString() ?? now()->toDateString(),
                (int) $run->company_id,
                $run->period_id ? (int) $run->period_id : null,
                'banking',
                'statement_date'
            );

            $statementLine = BankTransaction::query()
                ->lockForUpdate()
                ->where('bank_account_id', $run->bank_account_id)
                ->whereKey($statementTransactionId)
                ->whereNotNull('statement_import_id')
                ->firstOrFail();

            $bookLine = BankTransaction::query()
                ->lockForUpdate()
                ->where('bank_account_id', $run->bank_account_id)
                ->whereKey($bookTransactionId)
                ->whereNull('statement_import_id')
                ->firstOrFail();

            if ((float) $statementLine->amount !== (float) $bookLine->amount || $statementLine->direction !== $bookLine->direction) {
                throw ValidationException::withMessages([
                    'match' => __('Statement and book transactions must have the same amount and direction.'),
                ]);
            }

            if ($bookLine->status === 'reconciled' && (int) ($bookLine->reconciliation_run_id ?? 0) !== (int) $run->id) {
                throw ValidationException::withMessages([
                    'book_transaction_id' => __('The selected book transaction is already reconciled in another run.'),
                ]);
            }

            $this->clearExistingMatch($statementLine);
            $this->clearExistingMatch($bookLine);
            $this->applyMatch($run, $statementLine, $bookLine, $run->statement_date?->toDateString() ?? now()->toDateString());

            $summary = $this->refreshRun($run, $actorId, false);
            $this->auditLog->log('bank_reconciliation.matched', $actorId, $run, [
                'statement_transaction_id' => (int) $statementLine->id,
                'book_transaction_id' => (int) $bookLine->id,
            ], (int) $run->company_id);

            return $summary;
        });
    }

    public function unmatch(BankReconciliationRun $run, int $transactionId, int $actorId): array
    {
        return DB::transaction(function () use ($run, $transactionId, $actorId) {
            $run = BankReconciliationRun::query()->lockForUpdate()->findOrFail($run->id);
            $transaction = BankTransaction::query()
                ->lockForUpdate()
                ->whereKey($transactionId)
                ->where('bank_account_id', $run->bank_account_id)
                ->firstOrFail();

            $counterparty = $transaction->matched_bank_transaction_id
                ? BankTransaction::query()->lockForUpdate()->find($transaction->matched_bank_transaction_id)
                : null;

            $this->resetTransactionFromMatch($transaction);
            if ($counterparty) {
                $this->resetTransactionFromMatch($counterparty);
            }

            $summary = $this->refreshRun($run, $actorId, false);
            $this->auditLog->log('bank_reconciliation.unmatched', $actorId, $run, [
                'transaction_id' => (int) $transaction->id,
                'counterparty_id' => (int) ($counterparty?->id ?? 0),
            ], (int) $run->company_id);

            return $summary;
        });
    }

    public function markException(BankReconciliationRun $run, int $statementTransactionId, int $actorId): array
    {
        return DB::transaction(function () use ($run, $statementTransactionId, $actorId) {
            $run = BankReconciliationRun::query()->lockForUpdate()->findOrFail($run->id);
            $statementLine = BankTransaction::query()
                ->lockForUpdate()
                ->whereKey($statementTransactionId)
                ->where('bank_account_id', $run->bank_account_id)
                ->whereNotNull('statement_import_id')
                ->firstOrFail();

            $this->clearExistingMatch($statementLine);
            $statementLine->forceFill([
                'reconciliation_run_id' => $run->id,
                'matched_bank_transaction_id' => null,
                'is_cleared' => false,
                'cleared_date' => null,
                'status' => 'exception',
            ])->save();

            $summary = $this->refreshRun($run, $actorId, false);
            $this->auditLog->log('bank_reconciliation.exception_marked', $actorId, $run, [
                'statement_transaction_id' => (int) $statementLine->id,
            ], (int) $run->company_id);

            return $summary;
        });
    }

    public function close(BankReconciliationRun $run, int $actorId): array
    {
        return DB::transaction(function () use ($run, $actorId) {
            $run = BankReconciliationRun::query()->lockForUpdate()->findOrFail($run->id);
            $summary = $this->refreshRun($run, $actorId, false);

            if ((int) ($summary['unmatched_count'] ?? 0) > 0 || (int) ($summary['exception_count'] ?? 0) > 0) {
                throw ValidationException::withMessages([
                    'reconciliation' => __('All statement lines must be matched before the reconciliation can be closed.'),
                ]);
            }

            if (abs((float) ($summary['variance_amount'] ?? 0)) >= 0.01) {
                throw ValidationException::withMessages([
                    'reconciliation' => __('The reconciliation variance must be zero before closing.'),
                ]);
            }

            $run->forceFill([
                'status' => 'completed',
                'completed_at' => now(),
                'completed_by' => $actorId,
            ])->save();

            $summary['run'] = $run->fresh(['transactions', 'statementImport']);
            $this->auditLog->log('bank_reconciliation.closed', $actorId, $run, [], (int) $run->company_id);

            return $summary;
        });
    }

    public function reopen(BankReconciliationRun $run, int $actorId): array
    {
        return DB::transaction(function () use ($run, $actorId) {
            $run = BankReconciliationRun::query()->lockForUpdate()->findOrFail($run->id);
            $run->forceFill([
                'status' => 'review',
                'completed_at' => null,
                'completed_by' => null,
            ])->save();

            $summary = $this->refreshRun($run, $actorId, false, 'review');
            $this->auditLog->log('bank_reconciliation.reopened', $actorId, $run, [], (int) $run->company_id);

            return $summary;
        });
    }

    private function resolveStatementImport(BankAccount $bankAccount, mixed $statementImportId): ?BankStatementImport
    {
        if ($statementImportId) {
            $import = BankStatementImport::query()
                ->where('bank_account_id', $bankAccount->id)
                ->find($statementImportId);

            if (! $import) {
                throw ValidationException::withMessages([
                    'statement_import_id' => __('The selected statement import does not belong to this bank account.'),
                ]);
            }

            return $import;
        }

        return BankStatementImport::query()
            ->where('bank_account_id', $bankAccount->id)
            ->where('status', 'processed')
            ->latest('processed_at')
            ->first();
    }

    private function bookEndingBalance(BankAccount $bankAccount, string $statementDate): float
    {
        $movement = (float) BankTransaction::query()
            ->where('bank_account_id', $bankAccount->id)
            ->whereNull('statement_import_id')
            ->where('status', '!=', 'void')
            ->whereDate('transaction_date', '<=', $statementDate)
            ->get()
            ->sum(fn (BankTransaction $transaction) => $transaction->direction === 'inflow'
                ? (float) $transaction->amount
                : ((float) $transaction->amount * -1));

        return round((float) $bankAccount->opening_balance + $movement, 2);
    }

    /**
     * @return Collection<int, BankTransaction>
     */
    private function statementLines(BankAccount $bankAccount, string $statementDate, ?BankStatementImport $statementImport): Collection
    {
        $query = BankTransaction::query()
            ->where('bank_account_id', $bankAccount->id)
            ->whereNotNull('statement_import_id')
            ->whereNull('source_type')
            ->where('is_cleared', false)
            ->whereDate('transaction_date', '<=', $statementDate)
            ->orderBy('transaction_date');

        if ($statementImport) {
            $query->where('statement_import_id', $statementImport->id);
        }

        return $query->get();
    }

    /**
     * @return Collection<int, BankTransaction>
     */
    private function bookLines(BankAccount $bankAccount, string $statementDate): Collection
    {
        return BankTransaction::query()
            ->where('bank_account_id', $bankAccount->id)
            ->whereNull('statement_import_id')
            ->where('status', 'open')
            ->where('is_cleared', false)
            ->whereDate('transaction_date', '<=', $statementDate)
            ->orderBy('transaction_date')
            ->get();
    }

    private function referencesComparable(?string $left, ?string $right): bool
    {
        $left = $this->normalizeReference($left);
        $right = $this->normalizeReference($right);

        if ($left === '' || $right === '') {
            return true;
        }

        return $left === $right || str_contains($left, $right) || str_contains($right, $left);
    }

    private function normalizeReference(?string $value): string
    {
        $value = strtolower(trim((string) $value));
        $value = preg_replace('/[^a-z0-9]/', '', $value) ?? $value;

        return $value;
    }

    private function applyMatch(BankReconciliationRun $run, BankTransaction $statementLine, BankTransaction $bookLine, string $statementDate): void
    {
        $statementLine->forceFill([
            'reconciliation_run_id' => $run->id,
            'matched_bank_transaction_id' => $bookLine->id,
            'is_cleared' => true,
            'cleared_date' => $statementDate,
            'status' => 'matched',
        ])->save();

        $bookLine->forceFill([
            'reconciliation_run_id' => $run->id,
            'matched_bank_transaction_id' => $statementLine->id,
            'is_cleared' => true,
            'cleared_date' => $statementDate,
            'status' => 'reconciled',
        ])->save();
    }

    private function clearExistingMatch(BankTransaction $transaction): void
    {
        if (! $transaction->matched_bank_transaction_id) {
            return;
        }

        $counterparty = BankTransaction::query()->lockForUpdate()->find($transaction->matched_bank_transaction_id);
        if ($counterparty) {
            $this->resetTransactionFromMatch($counterparty);
        }

        $this->resetTransactionFromMatch($transaction);
    }

    private function resetTransactionFromMatch(BankTransaction $transaction): void
    {
        $transaction->forceFill([
            'reconciliation_run_id' => null,
            'matched_bank_transaction_id' => null,
            'is_cleared' => false,
            'cleared_date' => null,
            'status' => $transaction->statement_import_id ? 'open' : 'open',
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    public function refreshRun(BankReconciliationRun $run, ?int $actorId = null, bool $allowAutoComplete = false, ?string $forceStatus = null): array
    {
        $run->refresh();

        $statementLines = BankTransaction::query()
            ->where('bank_account_id', $run->bank_account_id)
            ->whereNotNull('statement_import_id')
            ->when($run->statement_import_id, fn ($query) => $query->where('statement_import_id', $run->statement_import_id))
            ->whereDate('transaction_date', '<=', $run->statement_date?->toDateString() ?? now()->toDateString())
            ->get();

        $bookLines = BankTransaction::query()
            ->where('bank_account_id', $run->bank_account_id)
            ->whereNull('statement_import_id')
            ->where('status', '!=', 'void')
            ->whereDate('transaction_date', '<=', $run->statement_date?->toDateString() ?? now()->toDateString())
            ->get();

        $matchedCount = $statementLines->where('reconciliation_run_id', $run->id)->where('status', 'matched')->count();
        $exceptionCount = $statementLines->where('reconciliation_run_id', $run->id)->where('status', 'exception')->count();
        $unmatchedCount = $statementLines->filter(function (BankTransaction $line) use ($run) {
            return in_array($line->status, ['open'], true)
                || ((int) ($line->reconciliation_run_id ?? 0) !== (int) $run->id && ! $line->is_cleared);
        })->count();
        $outstandingCount = $bookLines->where('status', 'open')->where('is_cleared', false)->count();
        $variance = round((float) $run->statement_ending_balance - $this->bookEndingBalance($run->bankAccount()->firstOrFail(), $run->statement_date?->toDateString() ?? now()->toDateString()), 2);

        $status = $forceStatus;
        $completedAt = $forceStatus === 'review' ? null : null;
        $completedBy = $forceStatus === 'review' ? null : null;

        if ($status === null) {
            if ($exceptionCount === 0 && $unmatchedCount === 0 && abs($variance) < 0.01) {
                $status = 'completed';
                if ($allowAutoComplete) {
                    $completedAt = now();
                    $completedBy = $actorId;
                } else {
                    $completedAt = $run->completed_at;
                    $completedBy = $run->completed_by;
                }
            } elseif ($matchedCount > 0 || $exceptionCount > 0) {
                $status = 'review';
            } else {
                $status = 'draft';
            }
        }

        $run->forceFill([
            'book_ending_balance' => $this->bookEndingBalance($run->bankAccount()->firstOrFail(), $run->statement_date?->toDateString() ?? now()->toDateString()),
            'variance_amount' => $variance,
            'status' => $status,
            'completed_at' => $completedAt,
            'completed_by' => $completedBy,
        ])->save();

        return [
            'run' => $run->fresh(['transactions', 'statementImport']),
            'matched_count' => $matchedCount,
            'unmatched_count' => $unmatchedCount,
            'exception_count' => $exceptionCount,
            'outstanding_count' => $outstandingCount,
            'variance_amount' => $variance,
        ];
    }
}
