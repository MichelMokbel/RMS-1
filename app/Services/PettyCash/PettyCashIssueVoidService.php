<?php

namespace App\Services\PettyCash;

use App\Models\PettyCashIssue;
use App\Models\SubledgerEntry;
use App\Services\Ledger\SubledgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PettyCashIssueVoidService
{
    public function __construct(
        protected PettyCashBalanceService $balanceService,
        protected SubledgerService $subledgerService
    ) {
    }

    public function void(PettyCashIssue $issue, int $userId): PettyCashIssue
    {
        return DB::transaction(function () use ($issue, $userId) {
            $issue = PettyCashIssue::whereKey($issue->id)->lockForUpdate()->firstOrFail();

            if ($issue->voided_at) {
                throw ValidationException::withMessages(['issue' => __('Issue is already voided.')]);
            }

            $wallet = $issue->wallet()->firstOrFail();
            $this->balanceService->reverseIssue($wallet, $issue);

            $issue->voided_at = now();
            $issue->voided_by = $userId;
            $issue->save();

            $entry = SubledgerEntry::where('source_type', 'petty_cash_issue')
                ->where('source_id', $issue->id)
                ->where('event', 'issue')
                ->first();

            if ($entry) {
                $this->subledgerService->recordReversalForEntry(
                    $entry,
                    'void',
                    'Petty cash issue void '.$issue->id,
                    now()->toDateString(),
                    $userId
                );
            }

            return $issue->fresh(['wallet']);
        });
    }
}
