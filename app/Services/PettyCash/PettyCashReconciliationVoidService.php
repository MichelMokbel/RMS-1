<?php

namespace App\Services\PettyCash;

use App\Models\PettyCashReconciliation;
use App\Models\SubledgerEntry;
use App\Services\Ledger\SubledgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PettyCashReconciliationVoidService
{
    public function __construct(
        protected PettyCashBalanceService $balanceService,
        protected SubledgerService $subledgerService
    ) {
    }

    public function void(PettyCashReconciliation $recon, int $userId): PettyCashReconciliation
    {
        return DB::transaction(function () use ($recon, $userId) {
            $recon = PettyCashReconciliation::whereKey($recon->id)->lockForUpdate()->firstOrFail();

            if ($recon->voided_at) {
                throw ValidationException::withMessages(['reconciliation' => __('Reconciliation is already voided.')]);
            }

            $wallet = $recon->wallet()->firstOrFail();
            $this->balanceService->reverseReconciliation($wallet, $recon);

            $recon->voided_at = now();
            $recon->voided_by = $userId;
            $recon->save();

            $entry = SubledgerEntry::where('source_type', 'petty_cash_reconciliation')
                ->where('source_id', $recon->id)
                ->where('event', 'reconcile')
                ->first();

            if ($entry) {
                $this->subledgerService->recordReversalForEntry(
                    $entry,
                    'void',
                    'Petty cash reconciliation void '.$recon->id,
                    now()->toDateString(),
                    $userId
                );
            }

            return $recon->fresh(['wallet']);
        });
    }
}
