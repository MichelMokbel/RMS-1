<?php

namespace App\Support\PettyCash;

class PettyCashReconciliationRules
{
    public function rules(string $prefix = 'reconForm.'): array
    {
        return [
            $prefix.'wallet_id' => ['required', 'integer', 'exists:petty_cash_wallets,id'],
            $prefix.'period_start' => ['required', 'date'],
            $prefix.'period_end' => ['required', 'date'],
            $prefix.'counted_balance' => ['required', 'numeric'],
            $prefix.'note' => ['nullable', 'string'],
        ];
    }
}

