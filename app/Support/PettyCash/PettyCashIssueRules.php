<?php

namespace App\Support\PettyCash;

class PettyCashIssueRules
{
    public function rules(string $prefix = 'issueForm.'): array
    {
        return [
            $prefix.'wallet_id' => ['required', 'integer', 'exists:petty_cash_wallets,id'],
            $prefix.'issue_date' => ['required', 'date'],
            $prefix.'amount' => ['required', 'numeric', 'min:0.01'],
            $prefix.'method' => ['required', 'in:cash,card,bank_transfer,cheque,other'],
            $prefix.'bank_account_id' => ['nullable', 'integer', 'exists:bank_accounts,id'],
            $prefix.'reference' => ['nullable', 'string', 'max:100'],
        ];
    }
}
