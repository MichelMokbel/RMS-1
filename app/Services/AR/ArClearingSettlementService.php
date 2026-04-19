<?php

namespace App\Services\AR;

use App\Models\ArClearingSettlement;
use App\Models\ArClearingSettlementItem;
use App\Models\BankAccount;
use App\Models\Payment;
use App\Models\SubledgerEntry;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\Accounting\AccountingContextService;
use App\Services\Accounting\AccountingPeriodGateService;
use App\Services\Accounting\LedgerAccountMappingService;
use App\Services\Ledger\SubledgerService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ArClearingSettlementService
{
    public function __construct(
        protected SubledgerService $subledgerService,
        protected AccountingContextService $accountingContext,
        protected LedgerAccountMappingService $mappingService,
        protected AccountingAuditLogService $auditLog,
        protected AccountingPeriodGateService $periodGate,
    ) {}

    public function settle(
        array $paymentIds,
        string $method,
        int $bankAccountId,
        string $settlementDate,
        int $actorId,
        ?string $clientUuid = null,
        ?string $reference = null,
        ?string $notes = null,
    ): ArClearingSettlement {
        $clientUuid = trim((string) ($clientUuid ?? ''));

        if ($clientUuid !== '') {
            $existing = ArClearingSettlement::where('client_uuid', $clientUuid)->first();
            if ($existing) {
                return $existing;
            }
        }

        try {
            return DB::transaction(function () use ($paymentIds, $method, $bankAccountId, $settlementDate, $actorId, $clientUuid, $reference, $notes) {
                if (! in_array($method, ['card', 'cheque'], true)) {
                    throw ValidationException::withMessages(['method' => __('Settlement method must be card or cheque.')]);
                }

                $bankAccount = BankAccount::whereKey($bankAccountId)->lockForUpdate()->firstOrFail();
                if (! $bankAccount->ledger_account_id) {
                    throw ValidationException::withMessages(['bank_account_id' => __('Bank account must have a linked ledger account.')]);
                }

                $companyId = (int) $bankAccount->company_id;
                $this->periodGate->assertDateOpen($settlementDate, $companyId, null, 'ar', 'settlement_date');

                $payments = Payment::whereIn('id', $paymentIds)->lockForUpdate()->get();

                if ($payments->count() !== count($paymentIds)) {
                    throw ValidationException::withMessages(['payment_ids' => __('One or more payments not found.')]);
                }

                $totalCents = 0;
                foreach ($payments as $payment) {
                    if ($payment->source !== 'ar') {
                        throw ValidationException::withMessages(['payment_ids' => __('Only AR payments can be settled via this workflow.')]);
                    }
                    if ($payment->method !== $method) {
                        throw ValidationException::withMessages(['payment_ids' => __('All payments must match the settlement method.')]);
                    }
                    if ($payment->voided_at) {
                        throw ValidationException::withMessages(['payment_ids' => __('Cannot settle a voided payment.')]);
                    }
                    if ($payment->clearing_settled_at) {
                        throw ValidationException::withMessages(['payment_ids' => __('Payment #:id is already settled.', ['id' => $payment->id])]);
                    }
                    $totalCents += (int) $payment->amount_cents;
                }

                $settlement = ArClearingSettlement::create([
                    'company_id'        => $companyId,
                    'bank_account_id'   => $bankAccountId,
                    'settlement_method' => $method,
                    'settlement_date'   => $settlementDate,
                    'amount_cents'      => $totalCents,
                    'client_uuid'       => $clientUuid !== '' ? $clientUuid : null,
                    'reference'         => $reference,
                    'notes'             => $notes,
                    'created_by'        => $actorId,
                ]);

                foreach ($payments as $payment) {
                    ArClearingSettlementItem::create([
                        'settlement_id' => $settlement->id,
                        'payment_id'    => $payment->id,
                        'amount_cents'  => (int) $payment->amount_cents,
                    ]);
                    $payment->clearing_settled_at = now();
                    $payment->save();
                }

                $this->subledgerService->recordArClearingSettlement($settlement, $actorId);

                $this->auditLog->log('ar_clearing_settlement.created', $actorId, $settlement, [
                    'payment_count' => count($paymentIds),
                    'amount_cents'  => $totalCents,
                    'method'        => $method,
                ], $companyId);

                return $settlement->fresh(['items']);
            });
        } catch (QueryException $e) {
            if ($clientUuid !== '') {
                $existing = ArClearingSettlement::where('client_uuid', $clientUuid)->first();
                if ($existing) {
                    return $existing;
                }
            }
            throw $e;
        }
    }

    public function void(
        ArClearingSettlement $settlement,
        int $actorId,
        ?string $voidReason = null,
    ): ArClearingSettlement {
        return DB::transaction(function () use ($settlement, $actorId, $voidReason) {
            $settlement = ArClearingSettlement::whereKey($settlement->id)->lockForUpdate()->firstOrFail();

            if ($settlement->voided_at) {
                throw ValidationException::withMessages(['settlement' => __('Settlement is already voided.')]);
            }

            $entry = SubledgerEntry::where('source_type', 'ar_clearing_settlement')
                ->where('source_id', $settlement->id)
                ->where('event', 'settle')
                ->first();

            if ($entry) {
                $this->subledgerService->recordReversalForEntry(
                    $entry,
                    'void',
                    'AR clearing settlement void #'.$settlement->id,
                    now()->toDateString(),
                    $actorId
                );
            }

            // Re-open all linked payments.
            $itemPaymentIds = $settlement->items()->pluck('payment_id');
            Payment::whereIn('id', $itemPaymentIds)->update(['clearing_settled_at' => null]);

            $settlement->voided_at   = now();
            $settlement->voided_by   = $actorId;
            $settlement->void_reason = $voidReason;
            $settlement->save();

            $this->auditLog->log('ar_clearing_settlement.voided', $actorId, $settlement, [
                'void_reason' => $voidReason,
            ], (int) $settlement->company_id);

            return $settlement->fresh();
        });
    }
}
