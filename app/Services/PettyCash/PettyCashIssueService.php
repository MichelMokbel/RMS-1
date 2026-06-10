<?php

namespace App\Services\PettyCash;

use App\Services\Accounting\LedgerAccountMappingService;
use App\Services\Banking\BankTransactionService;
use App\Models\PettyCashIssue;
use App\Models\PettyCashWallet;
use App\Services\Ledger\SubledgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PettyCashIssueService
{
    public function __construct(
        protected PettyCashBalanceService $balanceService,
        protected LedgerAccountMappingService $mappingService,
        protected BankTransactionService $bankTransactionService
    ) {
    }

    public function createIssue(int $walletId, array $data, int $userId): PettyCashIssue
    {
        return DB::transaction(function () use ($walletId, $data, $userId) {
            $wallet = PettyCashWallet::whereKey($walletId)->lockForUpdate()->firstOrFail();

            if (! $wallet->isActive()) {
                throw ValidationException::withMessages(['wallet_id' => __('Wallet is inactive.')]);
            }

            $method = $this->mappingService->normalizePaymentMethod((string) ($data['method'] ?? 'cash'));
            $bankAccountId = null;
            if ($method === 'bank_transfer') {
                $bankAccount = $this->mappingService->resolveBankAccount((int) ($data['bank_account_id'] ?? 0), null);
                if (! $bankAccount) {
                    throw ValidationException::withMessages([
                        'bank_account_id' => __('A valid bank account is required for bank-transfer funding.'),
                    ]);
                }
                if (! $bankAccount->ledger_account_id) {
                    throw ValidationException::withMessages([
                        'bank_account_id' => __('The selected bank account must be linked to a ledger account.'),
                    ]);
                }
                $bankAccountId = (int) $bankAccount->id;
            }

            $issue = PettyCashIssue::create([
                'wallet_id' => $wallet->id,
                'issue_date' => $data['issue_date'] ?? now()->toDateString(),
                'amount' => $data['amount'] ?? 0,
                'method' => $method,
                'bank_account_id' => $bankAccountId,
                'reference' => $data['reference'] ?? null,
                'issued_by' => $userId,
            ]);

            $this->balanceService->applyIssue($wallet, $issue);
            $issue = $issue->fresh(['wallet', 'bankAccount', 'issuer']);
            app(SubledgerService::class)->recordPettyCashIssue($issue, $userId);
            $this->bankTransactionService->recordPettyCashIssue($issue, $userId);

            return $issue;
        });
    }
}
