<?php

namespace App\Services\AP;

use App\Models\ApChequeClearance;
use App\Models\ApPayment;
use App\Models\BankAccount;
use App\Models\SubledgerEntry;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\Accounting\AccountingContextService;
use App\Services\Accounting\AccountingPeriodGateService;
use App\Services\Accounting\LedgerAccountMappingService;
use App\Services\Ledger\SubledgerService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApChequeClearanceService
{
    public function __construct(
        protected SubledgerService $subledgerService,
        protected AccountingContextService $accountingContext,
        protected LedgerAccountMappingService $mappingService,
        protected AccountingAuditLogService $auditLog,
        protected AccountingPeriodGateService $periodGate,
    ) {}

    public function clear(
        int $apPaymentId,
        int $bankAccountId,
        string $clearanceDate,
        float $amount,
        int $actorId,
        ?string $clientUuid = null,
        ?string $reference = null,
        ?string $notes = null,
    ): ApChequeClearance {
        $clientUuid = trim((string) ($clientUuid ?? ''));

        if ($clientUuid !== '') {
            $existing = ApChequeClearance::where('client_uuid', $clientUuid)->first();
            if ($existing) {
                return $existing;
            }
        }

        try {
            return DB::transaction(function () use ($apPaymentId, $bankAccountId, $clearanceDate, $amount, $actorId, $clientUuid, $reference, $notes) {
                // Guard before acquiring payment lock to fail fast and reduce contention.
                $activeExists = ApChequeClearance::where('ap_payment_id', $apPaymentId)
                    ->whereNull('voided_at')
                    ->lockForUpdate()
                    ->exists();

                if ($activeExists) {
                    throw ValidationException::withMessages(['ap_payment_id' => __('Payment #:id already has an active cheque clearance.', ['id' => $apPaymentId])]);
                }

                $payment = ApPayment::whereKey($apPaymentId)->lockForUpdate()->firstOrFail();

                if ($payment->payment_method !== 'cheque') {
                    throw ValidationException::withMessages(['ap_payment_id' => __('Only cheque AP payments can be cleared.')]);
                }
                if ($payment->voided_at) {
                    throw ValidationException::withMessages(['ap_payment_id' => __('Cannot clear a voided payment.')]);
                }
                if ($payment->cheque_cleared_at) {
                    throw ValidationException::withMessages(['ap_payment_id' => __('Payment #:id is already cleared.', ['id' => $apPaymentId])]);
                }

                $bankAccount = BankAccount::whereKey($bankAccountId)->lockForUpdate()->firstOrFail();
                if (! $bankAccount->ledger_account_id) {
                    throw ValidationException::withMessages(['bank_account_id' => __('Bank account must have a linked ledger account.')]);
                }

                $companyId = (int) ($payment->company_id ?? $bankAccount->company_id);
                $this->periodGate->assertDateOpen($clearanceDate, $companyId, null, 'ap', 'clearance_date');

                $clearance = ApChequeClearance::create([
                    'company_id'      => $companyId,
                    'bank_account_id' => $bankAccountId,
                    'ap_payment_id'   => $apPaymentId,
                    'clearance_date'  => $clearanceDate,
                    'amount'          => round($amount, 2),
                    'client_uuid'     => $clientUuid !== '' ? $clientUuid : null,
                    'reference'       => $reference,
                    'notes'           => $notes,
                    'created_by'      => $actorId,
                ]);

                $payment->cheque_cleared_at = now();
                $payment->save();

                $this->subledgerService->recordApChequeClearance($clearance, $actorId);

                $this->auditLog->log('ap_cheque_clearance.created', $actorId, $clearance, [
                    'ap_payment_id' => $apPaymentId,
                    'amount'        => round($amount, 2),
                ], $companyId);

                return $clearance->fresh();
            });
        } catch (QueryException $e) {
            if ($clientUuid !== '') {
                $existing = ApChequeClearance::where('client_uuid', $clientUuid)->first();
                if ($existing) {
                    return $existing;
                }
            }
            throw $e;
        }
    }

    public function void(
        ApChequeClearance $clearance,
        int $actorId,
        ?string $voidReason = null,
    ): ApChequeClearance {
        return DB::transaction(function () use ($clearance, $actorId, $voidReason) {
            $clearance = ApChequeClearance::whereKey($clearance->id)->lockForUpdate()->firstOrFail();

            if ($clearance->voided_at) {
                throw ValidationException::withMessages(['clearance' => __('Clearance is already voided.')]);
            }

            $entry = SubledgerEntry::where('source_type', 'ap_cheque_clearance')
                ->where('source_id', $clearance->id)
                ->where('event', 'clear')
                ->first();

            if ($entry) {
                $this->subledgerService->recordReversalForEntry(
                    $entry,
                    'void',
                    'AP cheque clearance void #'.$clearance->id,
                    now()->toDateString(),
                    $actorId
                );
            }

            $payment = ApPayment::whereKey($clearance->ap_payment_id)->lockForUpdate()->first();
            if ($payment) {
                $payment->cheque_cleared_at = null;
                $payment->save();
            }

            $clearance->voided_at   = now();
            $clearance->voided_by   = $actorId;
            $clearance->void_reason = $voidReason;
            $clearance->save();

            $this->auditLog->log('ap_cheque_clearance.voided', $actorId, $clearance, [
                'void_reason' => $voidReason,
            ], (int) $clearance->company_id);

            return $clearance->fresh();
        });
    }
}
