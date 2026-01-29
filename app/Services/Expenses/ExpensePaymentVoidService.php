<?php

namespace App\Services\Expenses;

use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\SubledgerEntry;
use App\Services\Ledger\SubledgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpensePaymentVoidService
{
    public function __construct(
        protected ExpensePaymentStatusService $statusService,
        protected SubledgerService $subledgerService
    ) {
    }

    public function void(ExpensePayment $payment, int $userId): ExpensePayment
    {
        return DB::transaction(function () use ($payment, $userId) {
            $payment = ExpensePayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->voided_at) {
                throw ValidationException::withMessages(['payment' => __('Payment is already voided.')]);
            }

            $payment->voided_at = now();
            $payment->voided_by = $userId;
            $payment->save();

            $entry = SubledgerEntry::where('source_type', 'expense_payment')
                ->where('source_id', $payment->id)
                ->where('event', 'payment')
                ->first();

            if ($entry) {
                $this->subledgerService->recordReversalForEntry(
                    $entry,
                    'void',
                    'Expense payment void '.$payment->id,
                    now()->toDateString(),
                    $userId
                );
            }

            $expense = Expense::whereKey($payment->expense_id)->lockForUpdate()->first();
            if ($expense) {
                $this->statusService->recalc($expense);
            }

            return $payment->fresh();
        });
    }
}
