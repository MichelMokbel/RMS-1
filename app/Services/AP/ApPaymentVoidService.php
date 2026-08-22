<?php

namespace App\Services\AP;

use App\Models\ApPayment;
use App\Models\ApPaymentAllocation;
use App\Models\ExpenseProfile;
use App\Models\SubledgerEntry;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\Banking\BankTransactionService;
use App\Services\Ledger\SubledgerService;
use App\Services\PettyCash\PettyCashBalanceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApPaymentVoidService
{
    public function __construct(
        protected SubledgerService $subledgerService,
        protected ApInvoiceStatusService $statusService,
        protected BankTransactionService $bankTransactionService,
        protected AccountingAuditLogService $auditLog,
        protected PettyCashBalanceService $pettyCashBalanceService,
    ) {}

    public function void(ApPayment $payment, int $userId): ApPayment
    {
        return DB::transaction(function () use ($payment, $userId) {
            // Guard: check clearance before acquiring payment lock to fail fast.
            if ($payment->payment_method === 'cheque') {
                $activeClearance = \App\Models\ApChequeClearance::where('ap_payment_id', $payment->id)
                    ->whereNull('voided_at')
                    ->lockForUpdate()
                    ->first();
                if ($activeClearance) {
                    throw ValidationException::withMessages([
                        'payment' => __('Cannot void a cheque payment that has already been cleared. Void the cheque clearance first.'),
                    ]);
                }
            }

            $payment = ApPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->voided_at) {
                throw ValidationException::withMessages(['payment' => __('Payment is already voided.')]);
            }

            $allocations = ApPaymentAllocation::where('payment_id', $payment->id)
                ->whereNull('voided_at')
                ->lockForUpdate()
                ->get();

            $invoiceIds = $allocations->pluck('invoice_id')->unique()->values();

            foreach ($allocations as $allocation) {
                $allocation->voided_at = now();
                $allocation->voided_by = $userId;
                $allocation->save();

                $entry = SubledgerEntry::where('source_type', 'ap_payment_allocation')
                    ->where('source_id', $allocation->id)
                    ->where('event', 'apply')
                    ->first();

                if ($entry) {
                    $this->subledgerService->recordReversalForEntry(
                        $entry,
                        'void',
                        'AP payment allocation void '.$allocation->id,
                        now()->toDateString(),
                        $userId
                    );
                }
            }

            $paymentEntry = SubledgerEntry::where('source_type', 'ap_payment')
                ->where('source_id', $payment->id)
                ->where('event', 'payment')
                ->first();

            if ($paymentEntry) {
                $this->subledgerService->recordReversalForEntry(
                    $paymentEntry,
                    'void',
                    'AP payment void '.$payment->id,
                    now()->toDateString(),
                    $userId
                );
            }

            $payment->voided_at = now();
            $payment->voided_by = $userId;
            $payment->save();
            $this->bankTransactionService->voidApPayment($payment, $userId);
            $this->auditLog->log('ap_payment.voided', $userId, $payment, [
                'allocation_count' => $allocations->count(),
            ], (int) ($payment->company_id ?? 0) ?: null);

            $restoredWalletAmount = 0.0;

            foreach ($invoiceIds as $invoiceId) {
                $invoice = \App\Models\ApInvoice::whereKey($invoiceId)->lockForUpdate()->first();
                if ($invoice) {
                    $profile = ExpenseProfile::query()
                        ->whereKey($invoiceId)
                        ->lockForUpdate()
                        ->first();
                    $allocatedAmount = round((float) $allocations
                        ->where('invoice_id', $invoiceId)
                        ->sum('allocated_amount'), 2);
                    $hasOtherActiveAllocations = $invoice->allocations()->exists();
                    $isRecordedSettlement = $profile
                        && (int) $profile->settlement_payment_id === (int) $payment->id;
                    $isLegacySingleSettlement = $profile
                        && ! $profile->settlement_payment_id
                        && $profile->settled_at
                        && ! $hasOtherActiveAllocations;

                    if ($profile
                        && $payment->payment_method === 'petty_cash'
                        && $profile->settlement_mode === 'petty_cash_wallet'
                        && $profile->wallet_id
                        && ($isRecordedSettlement || $isLegacySingleSettlement)
                        && $allocatedAmount > 0) {
                        $this->pettyCashBalanceService->restoreApprovedExpenseAmount(
                            $profile->wallet()->firstOrFail(),
                            $allocatedAmount,
                        );
                        $restoredWalletAmount = round($restoredWalletAmount + $allocatedAmount, 2);
                    }

                    $invoice = $this->statusService->recalcStatus($invoice);

                    if ($profile
                        && ($isRecordedSettlement || $isLegacySingleSettlement)
                        && $invoice->status !== 'paid') {
                        $profile->settled_at = null;
                        $profile->settlement_mode = null;
                        $profile->settlement_payment_id = null;
                        $profile->save();
                    }
                }
            }

            if ($restoredWalletAmount > 0) {
                $this->auditLog->log('ap_payment.petty_cash_restored', $userId, $payment, [
                    'amount' => $restoredWalletAmount,
                ], (int) ($payment->company_id ?? 0) ?: null);
            }

            return $payment->fresh(['allocations']);
        });
    }
}
