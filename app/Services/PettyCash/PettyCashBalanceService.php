<?php

namespace App\Services\PettyCash;

use App\Models\PettyCashExpense;
use App\Models\PettyCashIssue;
use App\Models\PettyCashReconciliation;
use App\Models\PettyCashWallet;
use Illuminate\Validation\ValidationException;

class PettyCashBalanceService
{
    public function applyIssue(PettyCashWallet $wallet, PettyCashIssue $issue): void
    {
        $lockedWallet = $this->lockWallet($wallet->id);

        if (! $lockedWallet->isActive()) {
            throw ValidationException::withMessages(['wallet_id' => __('Wallet is inactive.')]);
        }

        $lockedWallet->balance = round((float) $lockedWallet->balance + (float) $issue->amount, 2);
        $lockedWallet->save();
    }

    public function reverseIssue(PettyCashWallet $wallet, PettyCashIssue $issue): void
    {
        $lockedWallet = $this->lockWallet($wallet->id);

        if (! $lockedWallet->isActive()) {
            throw ValidationException::withMessages(['wallet_id' => __('Wallet is inactive.')]);
        }

        $newBalance = round((float) $lockedWallet->balance - (float) $issue->amount, 2);
        if (! config('petty_cash.allow_negative_wallet_balance', false) && $newBalance < 0) {
            throw ValidationException::withMessages(['balance' => __('Balance cannot go negative.')]);
        }

        $lockedWallet->balance = $newBalance;
        $lockedWallet->save();
    }

    public function applyApprovedExpense(PettyCashWallet $wallet, PettyCashExpense $expense): void
    {
        $lockedWallet = $this->lockWallet($wallet->id);

        if (! $lockedWallet->isActive()) {
            throw ValidationException::withMessages(['wallet_id' => __('Wallet is inactive.')]);
        }

        if ($expense->status !== 'approved') {
            throw ValidationException::withMessages(['status' => __('Expense must be approved to affect balance.')]);
        }

        $delta = -1 * (float) $expense->total_amount;
        $newBalance = round((float) $lockedWallet->balance + $delta, 2);

        if (! config('petty_cash.allow_negative_wallet_balance', false) && $newBalance < 0) {
            throw ValidationException::withMessages(['balance' => __('Balance cannot go negative.')]);
        }

        $lockedWallet->balance = $newBalance;
        $lockedWallet->save();
    }

    public function applyReconciliation(PettyCashWallet $wallet, PettyCashReconciliation $recon): void
    {
        if (! config('petty_cash.apply_reconciliation_to_wallet_balance', true)) {
            return;
        }

        $lockedWallet = $this->lockWallet($wallet->id);
        $lockedWallet->balance = round((float) $recon->counted_balance, 2);
        $lockedWallet->save();
    }

    public function reverseReconciliation(PettyCashWallet $wallet, PettyCashReconciliation $recon): void
    {
        if (! config('petty_cash.apply_reconciliation_to_wallet_balance', true)) {
            return;
        }

        $lockedWallet = $this->lockWallet($wallet->id);
        $lockedWallet->balance = round((float) $recon->expected_balance, 2);
        $lockedWallet->save();
    }

    private function lockWallet(int $walletId): PettyCashWallet
    {
        return PettyCashWallet::whereKey($walletId)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
