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

                    $match->forceFill([
                        'reconciliation_run_id' => $run->id,
                        'is_cleared' => true,
                        'cleared_date' => $statementDate,
                        'status' => 'reconciled',
                    ])->save();

                    $statementLine->forceFill([
                        'reconciliation_run_id' => $run->id,
                        'is_cleared' => true,
                        'cleared_date' => $statementDate,
                        'status' => 'matched',
                    ])->save();
                } else {
                    $unmatched++;
                    $statementLine->forceFill([
                        'reconciliation_run_id' => $run->id,
                        'status' => 'exception',
                    ])->save();
                }
            }

            $outstanding = $bookLines->whereNotIn('id', $usedBookIds)->count();
            $variance = round((float) $data['statement_ending_balance'] - $bookEndingBalance, 2);

            $run->forceFill([
                'variance_amount' => $variance,
                'status' => abs($variance) < 0.01 && $unmatched === 0 ? 'completed' : 'review',
                'completed_at' => now(),
                'completed_by' => $actorId,
            ])->save();

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
}
