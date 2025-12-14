<?php

namespace App\Services\Expenses;

use App\Models\Expense;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpensePaymentStatusService
{
    public function recalc(Expense $expense): Expense
    {
        $paid = (float) $expense->payments()->sum('amount');
        $outstanding = (float) $expense->total_amount - $paid;

        if (! config('expenses.allow_overpayment', false) && $paid - (float) $expense->total_amount > 0.01) {
            throw ValidationException::withMessages(['amount' => __('Payments exceed total amount.')]);
        }

        $status = 'unpaid';
        if ($paid <= 0) {
            $status = 'unpaid';
        } elseif ($paid + 0.01 >= (float) $expense->total_amount) {
            $status = 'paid';
        } else {
            $status = 'partial';
        }

        DB::transaction(function () use ($expense, $paid, $status) {
            $expense->payment_status = $status;
            $expense->save();
        });

        return $expense;
    }
}
