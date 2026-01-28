<?php

namespace App\Services\PettyCash;

use App\Models\PettyCashExpense;
use App\Services\Ledger\SubledgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PettyCashExpenseWorkflowService
{
    public function __construct(protected PettyCashBalanceService $balanceService)
    {
    }

    public function submit(PettyCashExpense $expense, int $userId): PettyCashExpense
    {
        $expense->loadMissing('wallet');

        if (! $expense->wallet?->isActive()) {
            throw ValidationException::withMessages(['wallet_id' => __('Wallet is inactive.')]);
        }

        if (! in_array($expense->status, ['draft'], true)) {
            throw ValidationException::withMessages(['status' => __('Only draft expenses can be submitted.')]);
        }

        $expense->recalcTotals();
        $expense->status = 'submitted';
        $expense->submitted_by = $expense->submitted_by ?: $userId;
        $expense->save();

        return $expense->fresh(['wallet']);
    }

    public function approve(PettyCashExpense $expense, int $approverId): PettyCashExpense
    {
        return DB::transaction(function () use ($expense, $approverId) {
            $expense->refresh()->load('wallet');

            if (! $expense->wallet?->isActive()) {
                throw ValidationException::withMessages(['wallet_id' => __('Wallet is inactive.')]);
            }

            if (! $expense->isApprovable()) {
                throw ValidationException::withMessages(['status' => __('Only submitted expenses can be approved.')]);
            }

            $expense->recalcTotals();
            $expense->status = 'approved';
            $expense->approved_by = $approverId;
            $expense->approved_at = now();
            $expense->save();

            $this->balanceService->applyApprovedExpense($expense->wallet, $expense);
            app(SubledgerService::class)->recordPettyCashExpense($expense, $approverId);

            return $expense->fresh(['wallet']);
        });
    }

    public function reject(PettyCashExpense $expense, int $approverId, ?string $reason = null): PettyCashExpense
    {
        $expense->refresh()->load('wallet');

        if (! in_array($expense->status, ['draft', 'submitted'], true)) {
            throw ValidationException::withMessages(['status' => __('Only draft or submitted expenses can be rejected.')]);
        }

        $expense->status = 'rejected';
        $expense->approved_by = $approverId;
        $expense->approved_at = now();
        if ($reason && property_exists($expense, 'rejection_reason')) {
            $expense->rejection_reason = $reason;
        }
        $expense->save();

        return $expense->fresh(['wallet']);
    }
}
