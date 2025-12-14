<?php

namespace App\Services\PettyCash;

use App\Models\PettyCashReconciliation;
use App\Models\PettyCashWallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PettyCashReconciliationService
{
    public function __construct(protected PettyCashBalanceService $balanceService)
    {
    }

    public function reconcile(int $walletId, array $data, int $userId): PettyCashReconciliation
    {
        return DB::transaction(function () use ($walletId, $data, $userId) {
            $wallet = PettyCashWallet::whereKey($walletId)->lockForUpdate()->firstOrFail();

            if (! $wallet->isActive()) {
                throw ValidationException::withMessages(['wallet_id' => __('Wallet is inactive.')]);
            }

            $expected = (float) $wallet->balance;
            $counted = (float) ($data['counted_balance'] ?? 0);
            $variance = round($counted - $expected, 2);

            $recon = PettyCashReconciliation::create([
                'wallet_id' => $wallet->id,
                'period_start' => $data['period_start'] ?? now()->toDateString(),
                'period_end' => $data['period_end'] ?? now()->toDateString(),
                'expected_balance' => $expected,
                'counted_balance' => $counted,
                'variance' => $variance,
                'note' => $data['note'] ?? null,
                'reconciled_by' => $userId,
                'reconciled_at' => $data['reconciled_at'] ?? now(),
            ]);

            $this->balanceService->applyReconciliation($wallet, $recon);

            return $recon->fresh(['wallet']);
        });
    }
}
