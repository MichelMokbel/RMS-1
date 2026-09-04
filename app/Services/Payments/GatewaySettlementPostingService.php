<?php

namespace App\Services\Payments;

use App\Models\ArClearingSettlement;
use App\Models\ArClearingSettlementAdjustment;
use App\Models\ArClearingSettlementItem;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\GatewaySettlementImport;
use App\Models\GatewaySettlementRow;
use App\Models\LedgerAccount;
use App\Models\Payment;
use App\Models\PaymentProviderTransaction;
use App\Models\PaymentSource;
use App\Models\User;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\Accounting\AccountingPeriodGateService;
use App\Services\Accounting\LedgerAccountMappingService;
use App\Services\Banking\BankTransactionService;
use App\Services\Ledger\SubledgerService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GatewaySettlementPostingService
{
    public function __construct(
        private readonly GatewaySettlementReviewService $reviews,
        private readonly AccountingPeriodGateService $periodGate,
        private readonly LedgerAccountMappingService $mappings,
        private readonly SubledgerService $subledger,
        private readonly BankTransactionService $bankTransactions,
        private readonly AccountingAuditLogService $audit,
    ) {}

    public function post(
        GatewaySettlementImport $entryImport,
        string $payoutReference,
        string $reviewedFingerprint,
        string $clientUuid,
        User $actor,
    ): ArClearingSettlement {
        $this->assertEnabled();
        if (! $actor->can('gateway_settlements.post')) {
            throw new AuthorizationException('You are not allowed to post gateway settlements.');
        }
        $this->reviews->authorizeImportScope($entryImport, $actor);
        if (! Str::isUuid($clientUuid)) {
            throw ValidationException::withMessages([
                'client_uuid' => __('A valid client UUID is required.'),
            ]);
        }

        try {
            return DB::transaction(function () use (
                $entryImport,
                $payoutReference,
                $reviewedFingerprint,
                $clientUuid,
                $actor,
            ): ArClearingSettlement {
                $lockedSource = PaymentSource::query()
                    ->lockForUpdate()
                    ->findOrFail($entryImport->payment_source_id);
                $entryImport = GatewaySettlementImport::query()->lockForUpdate()->findOrFail($entryImport->id);
                if ((int) $entryImport->payment_source_id !== (int) $lockedSource->id) {
                    throw new GatewaySettlementConflictException('REVIEW_STALE', 'The settlement payment source changed.');
                }
                $operations = $entryImport->saved_post_operations ?? [];
                $requestFingerprint = hash('sha256', implode('|', [$payoutReference, $reviewedFingerprint]));
                if (isset($operations[$clientUuid]['settlement_id'])) {
                    if (! hash_equals(
                        (string) ($operations[$clientUuid]['request_fingerprint'] ?? ''),
                        $requestFingerprint,
                    )) {
                        throw new GatewaySettlementConflictException('IDEMPOTENCY_CONFLICT', 'The client UUID was already used for a different settlement request.');
                    }

                    return ArClearingSettlement::query()->findOrFail((int) $operations[$clientUuid]['settlement_id']);
                }

                $snapshot = ($entryImport->review_snapshots ?? [])[$payoutReference] ?? null;
                if (! is_array($snapshot) || ! hash_equals((string) ($snapshot['reviewed_fingerprint'] ?? ''), $reviewedFingerprint)) {
                    throw new GatewaySettlementConflictException('PAYOUT_NOT_REVIEWED', 'The payout must be reviewed again before posting.');
                }

                $lockedRows = GatewaySettlementRow::query()
                    ->where('payment_source_id', $entryImport->payment_source_id)
                    ->where('payout_reference', $payoutReference)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                $this->reviews->authorizePayoutRows($lockedRows, $actor);
                $summary = $this->reviews->summarizeLockedRows($lockedRows, $payoutReference);
                if (! hash_equals((string) $snapshot['payout_fingerprint'], (string) $summary['payout_fingerprint'])
                    || $summary['blocking_reasons'] !== []) {
                    throw new GatewaySettlementConflictException('REVIEW_STALE', 'The payout rows or matches changed after review.');
                }

                $existing = ArClearingSettlement::query()
                    ->where('payment_source_id', $entryImport->payment_source_id)
                    ->where('payout_reference', $payoutReference)
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    if (! $existing->voided_at && hash_equals((string) $existing->reviewed_fingerprint, $reviewedFingerprint)) {
                        return $existing;
                    }
                    throw new GatewaySettlementConflictException('SETTLEMENT_CORRECTION_REQUIRED', 'This payout already has settlement history.');
                }

                $bankAccount = BankAccount::query()->lockForUpdate()->findOrFail((int) $snapshot['bank_account_id']);
                $bankEvidenceId = isset($snapshot['evidence_bank_transaction_id'])
                    ? (int) $snapshot['evidence_bank_transaction_id']
                    : null;
                $bankEvidence = $bankEvidenceId
                    ? BankTransaction::query()->lockForUpdate()->findOrFail($bankEvidenceId)
                    : null;
                $remittanceEvidence = is_array($snapshot['bank_remittance_evidence'] ?? null)
                    ? $snapshot['bank_remittance_evidence']
                    : null;
                if ((int) $bankAccount->company_id !== (int) $entryImport->company_id
                    || ! $bankAccount->is_active
                    || ! $bankAccount->is_default
                    || strtoupper((string) $bankAccount->currency_code) !== (string) $entryImport->currency
                    || (int) $bankAccount->ledger_account_id !== (int) $snapshot['bank_ledger_account_id']
                ) {
                    throw new GatewaySettlementConflictException('BANK_EVIDENCE_CHANGED', 'The reviewed bank deposit is no longer available for this payout.');
                }
                if ($remittanceEvidence !== null) {
                    $this->assertRemittanceEvidence(
                        $remittanceEvidence,
                        $payoutReference,
                        (int) $bankAccount->id,
                        (int) $summary['net_cents'],
                        (string) $snapshot['settlement_date'],
                    );
                }
                if ($bankEvidence !== null) {
                    if ((int) $bankEvidence->bank_account_id !== (int) $bankAccount->id
                        || $bankEvidence->statement_import_id === null
                        || $bankEvidence->source_type !== null
                        || $bankEvidence->matched_bank_transaction_id !== null
                        || $bankEvidence->status === 'void'
                        || $bankEvidence->direction !== 'inflow'
                        || $bankEvidence->transaction_date?->toDateString() !== (string) $snapshot['settlement_date']
                        || $this->decimalToCents((string) $bankEvidence->amount) !== (int) $summary['net_cents']
                        || (! $this->referencesComparable($bankEvidence->reference, $payoutReference) && $remittanceEvidence === null)) {
                        throw new GatewaySettlementConflictException('BANK_EVIDENCE_CHANGED', 'The reviewed bank deposit is no longer available for this payout.');
                    }
                } elseif ($remittanceEvidence === null) {
                    throw new GatewaySettlementConflictException('BANK_EVIDENCE_CHANGED', 'The reviewed bank evidence is no longer available for this payout.');
                }

                $source = $lockedSource;
                if ((int) $source->id !== (int) ($snapshot['payment_source_id'] ?? 0)) {
                    throw new GatewaySettlementConflictException('REVIEW_STALE', 'The reviewed SkipCash payment source changed.');
                }
                $mappingEvidence = trim((string) config(
                    'skipcash.report_profiles.'.strtolower((string) $source->code).'.identifier_mapping.evidence_reference',
                ));
                if (! hash_equals((string) ($snapshot['identifier_mapping_evidence'] ?? ''), $mappingEvidence)) {
                    throw new GatewaySettlementConflictException('REVIEW_STALE', 'The SkipCash identifier mapping changed after review.');
                }
                $commissionAccount = LedgerAccount::query()->lockForUpdate()->findOrFail((int) $snapshot['commission_expense_account_id']);
                $feeAccount = LedgerAccount::query()->lockForUpdate()->findOrFail((int) $snapshot['settlement_fee_expense_account_id']);
                foreach ([$commissionAccount, $feeAccount] as $account) {
                    if ((int) $account->company_id !== (int) $entryImport->company_id || ! $account->is_active || ! $account->allow_direct_posting) {
                        throw ValidationException::withMessages([
                            'account_mappings' => __('The reviewed SkipCash expense accounts are no longer available for posting.'),
                        ]);
                    }
                }
                $originalClearingBreakdown = $snapshot['original_clearing_breakdown'] ?? null;
                if (! is_array($originalClearingBreakdown) || $originalClearingBreakdown === []) {
                    throw new GatewaySettlementConflictException('REVIEW_STALE', 'The reviewed original clearing account evidence is missing.');
                }
                $clearingTotal = 0;
                foreach ($originalClearingBreakdown as $clearingItem) {
                    $clearingAccount = LedgerAccount::query()->lockForUpdate()->findOrFail((int) ($clearingItem['id'] ?? 0));
                    if ((int) $clearingAccount->company_id !== (int) $entryImport->company_id
                        || ! $clearingAccount->is_active
                        || ! $clearingAccount->allow_direct_posting) {
                        throw ValidationException::withMessages([
                            'account_mappings' => __('A reviewed original SkipCash clearing account is no longer available.'),
                        ]);
                    }
                    $clearingTotal += (int) ($clearingItem['amount_cents'] ?? 0);
                }
                if ($clearingTotal !== (int) $summary['gross_cents']) {
                    throw new GatewaySettlementConflictException('REVIEW_STALE', 'The reviewed original clearing amounts no longer equal payout gross.');
                }
                if ((int) $this->mappings->resolveAccountId('skipcash_commission_expense', (int) $entryImport->company_id) !== (int) $commissionAccount->id
                    || (int) $this->mappings->resolveAccountId('skipcash_settlement_fee_expense', (int) $entryImport->company_id) !== (int) $feeAccount->id) {
                    throw new GatewaySettlementConflictException('REVIEW_STALE', 'The SkipCash expense mappings changed after review.');
                }

                $settlementDate = (string) $snapshot['settlement_date'];
                $this->periodGate->assertDateOpen(
                    $settlementDate,
                    (int) $entryImport->company_id,
                    null,
                    'ar',
                    'settlement_date',
                );

                if ($lockedRows->count() !== count($snapshot['member_row_ids'])) {
                    throw new GatewaySettlementConflictException('REVIEW_STALE', 'One or more reviewed payout rows are unavailable.');
                }
                $rows = $lockedRows->whereIn('id', $snapshot['posting_row_ids'] ?? [])->values();
                if ($rows->count() !== count($snapshot['posting_row_ids'] ?? [])) {
                    throw new GatewaySettlementConflictException('REVIEW_STALE', 'One or more reviewed economic payout rows are unavailable.');
                }

                $claimed = [];
                foreach ($rows->where('order_type', 'sale') as $row) {
                    $transaction = PaymentProviderTransaction::query()->lockForUpdate()->findOrFail((int) $row->matched_provider_transaction_id);
                    $payment = Payment::query()->lockForUpdate()->findOrFail((int) $transaction->payment_id);
                    if ($transaction->active_clearing_settlement_id !== null
                        || $payment->voided_at !== null
                        || $payment->clearing_settled_at !== null
                        || (int) $payment->amount_cents !== (int) $row->gross_cents
                        || (int) $payment->payment_source_id !== (int) $source->id) {
                        throw new GatewaySettlementConflictException('PAYMENT_ALREADY_CLAIMED', 'A reviewed customer receipt is no longer available for clearing.');
                    }
                    $claimed[] = [$row, $transaction, $payment];
                }

                $settlement = ArClearingSettlement::query()->create([
                    'company_id' => (int) $entryImport->company_id,
                    'payment_source_id' => (int) $source->id,
                    'gateway_import_id' => (int) $entryImport->id,
                    'bank_account_id' => (int) $bankAccount->id,
                    'evidence_bank_transaction_id' => $bankEvidence?->id,
                    'settlement_method' => 'skipcash',
                    'settlement_date' => $settlementDate,
                    'amount_cents' => (int) $summary['gross_cents'],
                    'commission_cents' => (int) $summary['commission_cents'],
                    'settlement_fee_cents' => (int) $summary['settlement_fee_cents'],
                    'net_cents' => (int) $summary['net_cents'],
                    'client_uuid' => $clientUuid,
                    'reference' => $payoutReference,
                    'payout_reference' => $payoutReference,
                    'reviewed_fingerprint' => $reviewedFingerprint,
                    'evidence_snapshot' => [
                        'type' => $bankEvidence ? 'bank_statement' : 'bank_remittance',
                        'statement_transaction_id' => $bankEvidence?->id,
                        'statement_import_id' => $bankEvidence?->statement_import_id,
                        'remittance_evidence' => $remittanceEvidence,
                        'amount_cents' => (int) $summary['net_cents'],
                        'settlement_date' => $settlementDate,
                    ],
                    'original_clearing_breakdown' => collect($originalClearingBreakdown)->map(fn (array $item): array => [
                        'account_id' => (int) $item['id'],
                        'account_code' => (string) ($item['code'] ?? ''),
                        'account_name' => (string) ($item['name'] ?? ''),
                        'amount_cents' => (int) $item['amount_cents'],
                    ])->values()->all(),
                    'commission_expense_account_id' => (int) $commissionAccount->id,
                    'settlement_fee_expense_account_id' => (int) $feeAccount->id,
                    'bank_ledger_account_id' => (int) $bankAccount->ledger_account_id,
                    'notes' => 'SkipCash payout clearing',
                    'created_by' => (int) $actor->id,
                ]);

                foreach ($claimed as [$row, $transaction, $payment]) {
                    ArClearingSettlementItem::query()->create([
                        'settlement_id' => (int) $settlement->id,
                        'payment_id' => (int) $payment->id,
                        'provider_transaction_id' => (int) $transaction->id,
                        'gateway_settlement_row_id' => (int) $row->id,
                        'amount_cents' => (int) $row->gross_cents,
                    ]);

                    if ((int) $row->total_commission_cents > 0) {
                        $this->createAdjustment($settlement, $source->id, $commissionAccount, $row, 'commission', (int) $row->total_commission_cents);
                    }

                    $transaction->forceFill(['active_clearing_settlement_id' => (int) $settlement->id])->save();
                    $payment->forceFill(['clearing_settled_at' => now()])->save();
                }

                foreach ($rows->where('order_type', 'settlement_fee') as $row) {
                    if ((int) $row->settlement_fee_cents > 0) {
                        $this->createAdjustment($settlement, $source->id, $feeAccount, $row, 'settlement_fee', (int) $row->settlement_fee_cents);
                    }
                }

                if ((int) $settlement->items()->sum('amount_cents') !== (int) $settlement->amount_cents) {
                    throw new \RuntimeException('SkipCash settlement items do not equal the gross payout amount.');
                }
                if (! $this->subledger->recordArClearingSettlement($settlement, (int) $actor->id)) {
                    throw ValidationException::withMessages([
                        'ledger' => __('The SkipCash settlement could not create its required subledger entry.'),
                    ]);
                }
                $bookTransaction = $this->bankTransactions->recordArClearingSettlement($settlement, (int) $actor->id);
                if (! $bookTransaction || $this->decimalToCents((string) $bookTransaction->amount) !== (int) $settlement->net_cents) {
                    throw ValidationException::withMessages([
                        'bank' => __('The SkipCash settlement could not create its exact net bank transaction.'),
                    ]);
                }

                $operations[$clientUuid] = [
                    'request_fingerprint' => $requestFingerprint,
                    'settlement_id' => (int) $settlement->id,
                    'completed_at' => now()->toISOString(),
                ];
                $this->refreshImportPostingStates(
                    $summary['contributing_import_ids'],
                    (int) $source->id,
                    (int) $entryImport->id,
                    $operations,
                );

                $this->audit->log('gateway_settlement_payout.posted', (int) $actor->id, $settlement, [
                    'payment_source_id' => (int) $source->id,
                    'payout_reference' => $payoutReference,
                    'gross_cents' => (int) $settlement->amount_cents,
                    'commission_cents' => (int) $settlement->commission_cents,
                    'settlement_fee_cents' => (int) $settlement->settlement_fee_cents,
                    'net_cents' => (int) $settlement->net_cents,
                    'bank_transaction_id' => (int) $bookTransaction->id,
                ], (int) $settlement->company_id);

                return $settlement->fresh(['items', 'adjustments']);
            });
        } catch (QueryException $exception) {
            $existing = ArClearingSettlement::query()
                ->where('payment_source_id', $entryImport->payment_source_id)
                ->where('payout_reference', $payoutReference)
                ->whereNull('voided_at')
                ->first();
            if ($existing && hash_equals((string) $existing->reviewed_fingerprint, $reviewedFingerprint)) {
                return $existing->fresh(['items', 'adjustments']);
            }
            throw $exception;
        }
    }

    private function createAdjustment(
        ArClearingSettlement $settlement,
        int $paymentSourceId,
        LedgerAccount $account,
        GatewaySettlementRow $row,
        string $type,
        int $amountCents,
    ): void {
        ArClearingSettlementAdjustment::query()->create([
            'settlement_id' => (int) $settlement->id,
            'payment_source_id' => $paymentSourceId,
            'expense_account_id' => (int) $account->id,
            'gateway_settlement_row_id' => (int) $row->id,
            'adjustment_type' => $type,
            'economic_identity' => hash('sha256', $row->economic_identity.'|'.$type),
            'amount_cents' => $amountCents,
            'account_snapshot' => [
                'id' => (int) $account->id,
                'code' => (string) $account->code,
                'name' => (string) $account->name,
            ],
            'evidence_snapshot' => [
                'gateway_settlement_row_id' => (int) $row->id,
                'row_reference' => $row->row_reference,
                'financial_content_hash' => $row->financial_content_hash,
            ],
        ]);
    }

    /**
     * @param  array<int, int>  $importIds
     * @param  array<string, mixed>  $entryOperations
     */
    private function refreshImportPostingStates(
        array $importIds,
        int $paymentSourceId,
        int $entryImportId,
        array $entryOperations,
    ): void {
        $imports = GatewaySettlementImport::query()
            ->whereIn('id', $importIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($imports as $import) {
            $payoutReferences = $import->rows()
                ->whereNotNull('payout_reference')
                ->distinct()
                ->pluck('payout_reference');
            $postedCount = ArClearingSettlement::query()
                ->where('payment_source_id', $paymentSourceId)
                ->whereIn('payout_reference', $payoutReferences)
                ->distinct()
                ->count('payout_reference');
            $postingState = $postedCount === 0
                ? 'unposted'
                : ($postedCount === $payoutReferences->count() ? 'posted' : 'partially_posted');

            $attributes = [
                'posting_state' => $postingState,
                'revision' => (int) $import->revision + 1,
            ];
            if ((int) $import->id === $entryImportId) {
                $attributes['saved_post_operations'] = $entryOperations;
            }
            $import->forceFill($attributes)->save();
        }
    }

    private function decimalToCents(string $value): int
    {
        if (! preg_match('/^(-?)(\d+)\.(\d{2})$/', trim($value), $matches)) {
            return PHP_INT_MIN;
        }
        $cents = ((int) $matches[2] * 100) + (int) $matches[3];

        return ($matches[1] ?? '') === '-' ? -$cents : $cents;
    }

    /** @param array<string, mixed> $evidence */
    private function assertRemittanceEvidence(
        array $evidence,
        string $payoutReference,
        int $bankAccountId,
        int $amountCents,
        string $settlementDate,
    ): void {
        $import = GatewaySettlementImport::query()->find((int) ($evidence['import_id'] ?? 0));
        $entry = $import ? (($import->evidence_manifest ?? [])[$evidence['id'] ?? ''] ?? null) : null;
        $disk = is_array($entry) ? (string) ($entry['private_disk'] ?? '') : '';
        $objectKey = is_array($entry) ? (string) ($entry['object_key'] ?? '') : '';
        if (! is_array($entry)
            || ($entry['purpose'] ?? null) !== 'bank_remittance'
            || ! hash_equals((string) ($entry['payout_reference'] ?? ''), $payoutReference)
            || (int) ($entry['bank_account_id'] ?? 0) !== $bankAccountId
            || (int) ($entry['amount_cents'] ?? -1) !== $amountCents
            || ! hash_equals((string) ($entry['bank_date'] ?? ''), $settlementDate)
            || ! hash_equals((string) ($entry['sha256'] ?? ''), (string) ($evidence['sha256'] ?? ''))
            || $disk === ''
            || $objectKey === ''
            || ! Storage::disk($disk)->exists($objectKey)) {
            throw new GatewaySettlementConflictException('BANK_EVIDENCE_CHANGED', 'The reviewed remittance evidence is no longer available for this payout.');
        }
    }

    private function referencesComparable(?string $left, ?string $right): bool
    {
        $left = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '', trim((string) $left)));
        $right = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '', trim((string) $right)));

        return $left !== ''
            && $right !== ''
            && ($left === $right || str_contains($left, $right) || str_contains($right, $left));
    }

    private function assertEnabled(): void
    {
        if (! config('skipcash.settlements.enabled', false)) {
            throw ValidationException::withMessages([
                'settlements' => __('SkipCash settlement mutations are disabled.'),
            ]);
        }
    }
}
