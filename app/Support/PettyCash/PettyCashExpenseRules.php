<?php

namespace App\Support\PettyCash;

class PettyCashExpenseRules
{
    public function rules(string $prefix = 'expenseForm.'): array
    {
        return [
            $prefix.'wallet_id' => ['required', 'integer', 'exists:petty_cash_wallets,id'],
            $prefix.'category_id' => ['required', 'integer', 'exists:expense_categories,id'],
            $prefix.'expense_date' => ['required', 'date'],
            $prefix.'description' => ['required', 'string', 'max:255'],
            $prefix.'amount' => ['required', 'numeric', 'min:0'],
            $prefix.'tax_amount' => ['required', 'numeric', 'min:0'],
        ];
    }
}

