<?php

namespace App\Services\Accounting;

use App\Enums\HR\PayrollPaymentBatchStatus;
use App\Models\ApPayment;
use App\Models\HrPayrollPaymentBatch;
use App\Models\Payment;
use App\Support\Money\MinorUnits;
use Illuminate\Support\Facades\Schema;

class DashboardCashActivityService
{
    /**
     * Summarize payment activity, independently of cash-account ledger movements.
     *
     * @param  list<int>  $companyIds
     * @return array{inflow_total: float, outflow_total: float, net_cash_flow: float}
     */
    public function forRange(array $companyIds, string $dateFrom, string $dateTo): array
    {
        $inflow = 0.0;
        $outflow = 0.0;

        if ($companyIds !== []) {
            if (Schema::hasTable('payments')) {
                $inflow = (float) Payment::query()
                    ->whereIn('company_id', $companyIds)
                    ->where('source', 'ar')
                    ->whereNull('voided_at')
                    ->whereDate('received_at', '>=', $dateFrom)
                    ->whereDate('received_at', '<=', $dateTo)
                    ->sum('amount_cents') / MinorUnits::posScale();
            }

            // Settled petty-cash expenses already have AP payments; wallet funding is not spending.
            if (Schema::hasTable('ap_payments')) {
                $outflow = (float) ApPayment::query()
                    ->whereIn('company_id', $companyIds)
                    ->whereNotNull('posted_at')
                    ->whereNull('voided_at')
                    ->whereDate('payment_date', '>=', $dateFrom)
                    ->whereDate('payment_date', '<=', $dateTo)
                    ->sum('amount');
            }

            // Run paid_at can be an import/processing timestamp; use the actual payment batch date.
            if (Schema::hasTable('hr_payroll_payment_batches')) {
                $outflow += (float) HrPayrollPaymentBatch::query()
                    ->whereIn('company_id', $companyIds)
                    ->where('status', PayrollPaymentBatchStatus::Processed->value)
                    ->whereNull('reversed_at')
                    ->whereDate('payment_date', '>=', $dateFrom)
                    ->whereDate('payment_date', '<=', $dateTo)
                    ->sum('total_minor') / 100;
            }
        }

        $inflow = round($inflow, 2);
        $outflow = round($outflow, 2);

        return [
            'inflow_total' => $inflow,
            'outflow_total' => $outflow,
            'net_cash_flow' => round($inflow - $outflow, 2),
        ];
    }
}
