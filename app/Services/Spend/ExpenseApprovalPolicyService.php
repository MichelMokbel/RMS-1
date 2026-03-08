<?php

namespace App\Services\Spend;

use App\Models\ApInvoice;
use App\Models\ExpenseProfile;

class ExpenseApprovalPolicyService
{
    /**
     * @return array<int, string>
     */
    public function exceptionFlags(ApInvoice $invoice, ExpenseProfile $profile): array
    {
        $invoice->loadMissing('attachments');
        $profile->loadMissing('wallet');

        $flags = [];

        $threshold = (float) config('spend.approval_exception_threshold', 1000.0);
        if ((float) $invoice->total_amount > $threshold) {
            $flags[] = 'amount_over_threshold';
        }

        if ($invoice->attachments->count() === 0) {
            $flags[] = 'missing_attachment';
        }

        $highRisk = collect(config('spend.high_risk_category_ids', []))
            ->map(fn ($v) => (int) $v)
            ->filter(fn (int $v) => $v > 0)
            ->values();

        if ($highRisk->contains((int) $invoice->category_id)) {
            $flags[] = 'category_high_risk';
        }

        if ($profile->channel === 'petty_cash' && $profile->wallet) {
            if ((float) $invoice->total_amount > (float) $profile->wallet->balance) {
                $flags[] = 'petty_cash_over_wallet_limit';
            }
        }

        return $flags;
    }

    /**
     * @param array<int, string> $flags
     */
    public function requiresFinanceApproval(array $flags): bool
    {
        return $flags !== [];
    }
}
