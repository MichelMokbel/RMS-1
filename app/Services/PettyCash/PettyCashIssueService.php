<?php

namespace App\Services\PettyCash;

use App\Models\PettyCashIssue;
use App\Models\PettyCashWallet;
use App\Services\Ledger\SubledgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PettyCashIssueService
{
    public function __construct(protected PettyCashBalanceService $balanceService)
    {
    }

    public function createIssue(int $walletId, array $data, int $userId): PettyCashIssue
    {
        return DB::transaction(function () use ($walletId, $data, $userId) {
            $wallet = PettyCashWallet::whereKey($walletId)->lockForUpdate()->firstOrFail();

            if (! $wallet->isActive()) {
                throw ValidationException::withMessages(['wallet_id' => __('Wallet is inactive.')]);
            }

            $issue = PettyCashIssue::create([
                'wallet_id' => $wallet->id,
                'issue_date' => $data['issue_date'] ?? now()->toDateString(),
                'amount' => $data['amount'] ?? 0,
                'method' => $data['method'] ?? 'cash',
                'reference' => $data['reference'] ?? null,
                'issued_by' => $userId,
            ]);

            $this->balanceService->applyIssue($wallet, $issue);
            app(SubledgerService::class)->recordPettyCashIssue($issue, $userId);

            return $issue->fresh(['wallet']);
        });
    }
}
