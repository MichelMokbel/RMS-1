<?php

namespace App\Services\Expenses;

use App\Models\Expense;
use App\Models\ExpensePayment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpensePaymentService
{
    public function __construct(protected ExpensePaymentStatusService $statusService)
    {
    }

    public function addPayment(Expense $expense, array $data, int $userId): ExpensePayment
    {
        return DB::transaction(function () use ($expense, $data, $userId) {
            $amount = (float) ($data['amount'] ?? 0);
            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => __('Amount must be greater than zero.')]);
            }

            $outstanding = $expense->outstandingAmount();
            if (! config('expenses.allow_overpayment', false) && $amount - $outstanding > 0.01) {
                throw ValidationException::withMessages(['amount' => __('Amount exceeds outstanding.')]);
            }

            $payment = ExpensePayment::create([
                'expense_id' => $expense->id,
                'payment_date' => $data['payment_date'] ?? now()->toDateString(),
                'amount' => $amount,
                'payment_method' => $data['payment_method'] ?? 'cash',
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            $this->statusService->recalc($expense->fresh());

            return $payment;
        });
    }
}
