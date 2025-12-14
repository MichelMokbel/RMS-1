<?php

namespace App\Services\Expenses;

use App\Models\Expense;

class ExpenseTotalsService
{
    public function recalc(Expense $expense): Expense
    {
        $expense->total_amount = round((float) $expense->amount + (float) $expense->tax_amount, 2);
        $expense->save();

        return $expense;
    }
}
