<?php

namespace App\Services\Spend;

use App\Models\ApInvoice;
use App\Models\ExpenseProfile;
use App\Services\AP\ApAllocationService;
use App\Services\PettyCash\PettyCashBalanceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpenseSettlementService
{
    public function __construct(
        protected ApAllocationService $allocationService,
        protected PettyCashBalanceService $pettyCashBalanceService
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{invoice: ApInvoice, payment_id: int|null, settlement_mode: string}
     */
    public function settle(ApInvoice $invoice, ExpenseProfile $profile, int $actorId, array $payload = []): array
    {
        return DB::transaction(function () use ($invoice, $profile, $actorId, $payload) {
            $invoice = ApInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $profile = ExpenseProfile::query()->lockForUpdate()->findOrFail($profile->invoice_id);

            if ($profile->approval_status !== 'approved') {
                throw ValidationException::withMessages(['approval_status' => __('Only approved expenses can be settled.')]);
            }

            if (! in_array($invoice->status, ['posted', 'partially_paid'], true)) {
                throw ValidationException::withMessages(['status' => __('Only posted or partially paid expenses can be settled.')]);
            }

            if ($profile->settled_at) {
                throw ValidationException::withMessages(['settlement' => __('Expense is already settled.')]);
            }

            $outstanding = max($invoice->outstandingAmount(), 0);
            if ($outstanding <= 0) {
                $profile->settled_at = now();
                $profile->settlement_mode = $profile->settlement_mode ?: 'manual_ap_payment';
                $profile->save();

                return ['invoice' => $invoice->fresh(), 'payment_id' => null, 'settlement_mode' => (string) $profile->settlement_mode];
            }

            $paymentDate = (string) ($payload['payment_date'] ?? now()->toDateString());
            $paymentMethod = (string) ($payload['payment_method'] ?? ($profile->channel === 'petty_cash' ? 'petty_cash' : 'bank_transfer'));
            $reference = $payload['reference'] ?? null;
            $notes = $payload['notes'] ?? null;

            if ($profile->channel === 'petty_cash') {
                $wallet = $profile->wallet;
                if (! $wallet) {
                    throw ValidationException::withMessages(['wallet_id' => __('Wallet is required for petty cash settlement.')]);
                }

                $this->pettyCashBalanceService->applyApprovedExpenseAmount($wallet, $outstanding);
                $paymentMethod = 'petty_cash';
            }

            $payment = $this->allocationService->createPaymentWithAllocations([
                'supplier_id' => (int) $invoice->supplier_id,
                'payment_date' => $paymentDate,
                'amount' => $outstanding,
                'payment_method' => $paymentMethod,
                'reference' => $reference,
                'notes' => $notes,
                'allocations' => [
                    [
                        'invoice_id' => (int) $invoice->id,
                        'allocated_amount' => $outstanding,
                    ],
                ],
            ], $actorId);

            $profile->settled_at = now();
            $profile->settlement_mode = $profile->channel === 'petty_cash'
                ? 'petty_cash_wallet'
                : 'manual_ap_payment';
            $profile->save();

            return [
                'invoice' => $invoice->fresh(),
                'payment_id' => (int) $payment->id,
                'settlement_mode' => (string) $profile->settlement_mode,
            ];
        });
    }
}
