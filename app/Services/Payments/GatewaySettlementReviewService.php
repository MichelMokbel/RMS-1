<?php

namespace App\Services\Payments;

use App\Models\ArClearingSettlementItem;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\GatewaySettlementImport;
use App\Models\GatewaySettlementRow;
use App\Models\LedgerAccount;
use App\Models\PaymentProviderTransaction;
use App\Models\PaymentSource;
use App\Models\SubledgerEntry;
use App\Models\User;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\Accounting\AccountingContextService;
use App\Services\Accounting\LedgerAccountMappingService;
use App\Services\Customers\PhoneNumberService;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class GatewaySettlementReviewService
{
    public function __construct(
        private readonly AccountingContextService $accountingContext,
        private readonly LedgerAccountMappingService $mappings,
        private readonly AccountingAuditLogService $audit,
        private readonly PhoneNumberService $phoneNumbers,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function payoutsForImport(GatewaySettlementImport $import, User $actor): array
    {
        $this->authorizeImport($import, $actor);
        $references = $import->rows()
            ->whereNotNull('payout_reference')
            ->distinct()
            ->orderBy('payout_reference')
            ->pluck('payout_reference');

        return $references
            ->map(function (string $reference) use ($import, $actor): array {
                $rows = $this->payoutRows((int) $import->payment_source_id, $reference);
                $this->assertRowsAccess($rows, $actor);

                return $this->summaryFromRows($rows, $reference);
            })
            ->values()
            ->all();
    }

    public function authorizeImport(GatewaySettlementImport $import, User $actor): void
    {
        $this->assertReviewAccess($actor);
        $this->authorizeImportScope($import, $actor);
    }

    public function authorizeImportScope(GatewaySettlementImport $import, User $actor): void
    {
        $this->assertImportAccess($import, $actor);
    }

    /** @param Collection<int, GatewaySettlementRow> $rows */
    public function authorizePayoutRows(Collection $rows, User $actor): void
    {
        $this->assertRowsAccess($rows, $actor);
    }

    /** @return Collection<int, BankTransaction> */
    public function bankEvidenceCandidates(
        GatewaySettlementImport $import,
        string $payoutReference,
        User $actor,
    ): Collection {
        $this->authorizeImport($import, $actor);
        if (! $import->rows()->where('payout_reference', $payoutReference)->exists()) {
            throw new AuthorizationException('The payout is outside this settlement import.');
        }
        $rows = $this->payoutRows((int) $import->payment_source_id, $payoutReference);
        $this->assertRowsAccess($rows, $actor);
        $netCents = (int) $this->summaryFromRows($rows, $payoutReference)['net_cents'];
        $bankAccountId = $this->accountingContext->defaultBankAccountId((int) $import->company_id);
        if ($bankAccountId === null || $netCents <= 0) {
            return collect();
        }

        return BankTransaction::query()
            ->with('statementImport:id,file_name')
            ->where('company_id', $import->company_id)
            ->where('bank_account_id', $bankAccountId)
            ->whereNotNull('statement_import_id')
            ->whereNull('source_type')
            ->whereNull('matched_bank_transaction_id')
            ->where('direction', 'inflow')
            ->where('status', '!=', 'void')
            ->where('amount', number_format($netCents / 100, 2, '.', ''))
            ->whereNotIn('id', \App\Models\ArClearingSettlement::query()
                ->whereNotNull('evidence_bank_transaction_id')
                ->select('evidence_bank_transaction_id'))
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->limit(50)
            ->get();
    }

    /** @return Collection<int, PaymentProviderTransaction> */
    public function candidatesForRow(GatewaySettlementRow $row, User $actor): Collection
    {
        $this->assertReviewAccess($actor);
        $import = $row->import;
        $this->assertImportAccess($import, $actor);

        $reference = (string) $row->row_reference;
        $candidates = PaymentProviderTransaction::query()
            ->with(['payment.customer', 'attempt'])
            ->where('payment_source_id', $row->payment_source_id)
            ->where('normalized_status', 'paid')
            ->whereNotNull('verified_paid_at')
            ->where('verified_amount_cents', $row->gross_cents)
            ->where('verified_currency', $import->currency)
            ->whereNull('active_clearing_settlement_id')
            ->whereHas('attempt', fn ($query) => $query
                ->where('company_id', $import->company_id)
                ->where('branch_id', $row->branch_id))
            ->whereHas('payment', fn ($query) => $query
                ->where('source', 'ar')
                ->where('method', 'skipcash')
                ->where('company_id', $import->company_id)
                ->where('branch_id', $row->branch_id)
                ->where('payment_source_id', $row->payment_source_id)
                ->whereNull('voided_at')
                ->whereNull('clearing_settled_at'))
            ->orderByRaw(
                'CASE WHEN provider_payment_id = ? OR merchant_transaction_id = ? OR visa_id = ? THEN 1 ELSE 0 END DESC',
                [$reference, $reference, $reference],
            )
            ->orderByDesc('verified_paid_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $reportDate = $this->databaseInstantDate(
            $row->getRawOriginal('transaction_at'),
            (string) $import->timezone,
        );

        return $candidates
            ->each(function (PaymentProviderTransaction $candidate) use ($row, $reportDate, $import): void {
                $reasons = [];
                $score = 0;
                $referenceMatches = $row->row_reference !== null && collect([
                    $candidate->provider_payment_id,
                    $candidate->merchant_transaction_id,
                    $candidate->visa_id,
                ])->contains(fn ($value): bool => $value !== null
                    && hash_equals((string) $row->row_reference, trim((string) $value)));
                if ($referenceMatches) {
                    $reasons[] = 'reference_value';
                    $score += 100;
                }

                $customer = $candidate->payment?->customer;
                $customerPhone = $this->phoneNumbers->normalize($customer?->phone_e164)
                    ?? $this->phoneNumbers->normalize($customer?->phone);
                if ($row->customer_phone_hash !== null
                    && $customerPhone !== null
                    && hash_equals((string) $row->customer_phone_hash, hash('sha256', $customerPhone))) {
                    $reasons[] = 'phone';
                    $score += 10;
                }

                $candidateDate = $this->databaseInstantDate(
                    $candidate->getRawOriginal('verified_finished_at'),
                    (string) $import->timezone,
                );
                if ($reportDate !== null && $candidateDate !== null && $candidateDate === $reportDate) {
                    $reasons[] = 'date';
                    $score += 5;
                }

                $candidate->setAttribute('settlement_candidate_score', $score);
                $candidate->setAttribute('settlement_candidate_reasons', $reasons);
            })
            ->sort(function (PaymentProviderTransaction $left, PaymentProviderTransaction $right): int {
                $scoreOrder = ((int) $right->settlement_candidate_score) <=> ((int) $left->settlement_candidate_score);
                if ($scoreOrder !== 0) {
                    return $scoreOrder;
                }

                $verifiedOrder = ((int) $right->verified_paid_at?->getTimestamp())
                    <=> ((int) $left->verified_paid_at?->getTimestamp());

                return $verifiedOrder !== 0 ? $verifiedOrder : ((int) $right->id <=> (int) $left->id);
            })
            ->take(20)
            ->values();
    }

    public function match(
        GatewaySettlementImport $import,
        GatewaySettlementRow $row,
        ?int $providerTransactionId,
        int $importRevision,
        int $rowRevision,
        User $actor,
        ?string $evidenceReference = null,
        ?string $reason = null,
    ): GatewaySettlementRow {
        $this->assertEnabled();
        $this->assertReviewAccess($actor);
        $this->assertImportAccess($import, $actor);

        return DB::transaction(function () use (
            $import,
            $row,
            $providerTransactionId,
            $importRevision,
            $rowRevision,
            $actor,
            $evidenceReference,
            $reason,
        ): GatewaySettlementRow {
            PaymentSource::query()->lockForUpdate()->findOrFail($import->payment_source_id);
            $import = GatewaySettlementImport::query()->lockForUpdate()->findOrFail($import->id);
            $row = GatewaySettlementRow::query()
                ->where('import_id', $import->id)
                ->lockForUpdate()
                ->findOrFail($row->id);

            if ((int) $import->revision !== $importRevision || (int) $row->revision !== $rowRevision) {
                throw new GatewaySettlementConflictException('REVIEW_STALE', 'The displayed settlement data has changed. Refresh and try again.');
            }
            if ($row->order_type !== 'sale' || $row->error_code !== null) {
                throw ValidationException::withMessages([
                    'row' => __('Only a valid Sale row can be matched to a customer receipt.'),
                ]);
            }
            if ($row->duplicate_of_row_id !== null) {
                throw ValidationException::withMessages([
                    'row' => __('Match the original economic row; this row is retained duplicate evidence.'),
                ]);
            }

            if ($providerTransactionId === null) {
                if ($row->matched_provider_transaction_id !== null && trim((string) $reason) === '') {
                    throw ValidationException::withMessages([
                        'reason' => __('Give a reason when removing a settlement match.'),
                    ]);
                }
                $row->forceFill([
                    'match_state' => 'unmatched',
                    'matched_provider_transaction_id' => null,
                    'evidence_reference' => null,
                    'reviewed_by' => (int) $actor->id,
                    'reviewed_at' => now(),
                    'match_reason' => trim((string) $reason) ?: null,
                    'revision' => (int) $row->revision + 1,
                ])->save();
                $this->invalidatePayoutReviews($row);
                $this->auditMatch($row, $actor, 'gateway_settlement_row.unmatched');

                return $row->fresh();
            }

            $transaction = PaymentProviderTransaction::query()
                ->with(['attempt', 'payment'])
                ->lockForUpdate()
                ->findOrFail($providerTransactionId);
            $confirmedEvidenceReference = $this->assertProviderMatch($import, $row, $transaction, $evidenceReference);

            $claimedElsewhere = GatewaySettlementRow::query()
                ->where('matched_provider_transaction_id', $transaction->id)
                ->where('id', '!=', $row->id)
                ->where('match_state', 'matched')
                ->lockForUpdate()
                ->exists();
            if ($claimedElsewhere || $transaction->active_clearing_settlement_id !== null) {
                throw new GatewaySettlementConflictException('PAYMENT_ALREADY_CLAIMED', 'The provider transaction is already linked to another settlement row.');
            }

            $history = ArClearingSettlementItem::query()
                ->with('settlement')
                ->where('provider_transaction_id', $transaction->id)
                ->first();
            if ($history) {
                $code = $history->settlement?->voided_at
                    ? 'SETTLEMENT_CORRECTION_REQUIRED'
                    : 'PAYMENT_ALREADY_CLAIMED';
                throw new GatewaySettlementConflictException($code, 'The provider transaction already has settlement history.');
            }

            $row->forceFill([
                'match_state' => 'matched',
                'matched_provider_transaction_id' => (int) $transaction->id,
                'evidence_reference' => $confirmedEvidenceReference,
                'reviewed_by' => (int) $actor->id,
                'reviewed_at' => now(),
                'match_reason' => trim((string) $reason) ?: null,
                'revision' => (int) $row->revision + 1,
            ])->save();
            $this->invalidatePayoutReviews($row);
            $this->auditMatch($row, $actor, 'gateway_settlement_row.matched');

            return $row->fresh();
        });
    }

    /** @return array<string, mixed> */
    public function review(
        GatewaySettlementImport $entryImport,
        string $payoutReference,
        string $payoutFingerprint,
        ?int $evidenceBankTransactionId,
        int $importRevision,
        User $actor,
        ?string $remittanceEvidenceReference = null,
    ): array {
        $this->assertEnabled();
        $this->assertReviewAccess($actor);
        $this->assertImportAccess($entryImport, $actor);

        return DB::transaction(function () use (
            $entryImport,
            $payoutReference,
            $payoutFingerprint,
            $evidenceBankTransactionId,
            $importRevision,
            $actor,
            $remittanceEvidenceReference,
        ): array {
            PaymentSource::query()->lockForUpdate()->findOrFail($entryImport->payment_source_id);
            $entryImport = GatewaySettlementImport::query()->lockForUpdate()->findOrFail($entryImport->id);
            if ((int) $entryImport->revision !== $importRevision) {
                throw new GatewaySettlementConflictException('REVIEW_STALE', 'The import changed after it was displayed. Refresh and try again.');
            }
            if (! $entryImport->rows()->where('payout_reference', $payoutReference)->exists()) {
                throw ValidationException::withMessages([
                    'payout_reference' => __('The payout does not belong to this import.'),
                ]);
            }

            $rows = $this->payoutRows((int) $entryImport->payment_source_id, $payoutReference, true);
            $this->assertRowsAccess($rows, $actor);
            $summary = $this->summaryFromRows($rows, $payoutReference);
            if (! hash_equals($summary['payout_fingerprint'], $payoutFingerprint)) {
                throw new GatewaySettlementConflictException('REVIEW_STALE', 'The payout membership or matches changed after it was displayed.');
            }
            if ($summary['blocking_reasons'] !== []) {
                throw new GatewaySettlementConflictException('PAYOUT_REVIEW_REQUIRED', 'Every payout row must be valid and matched before review.');
            }
            if ($summary['gross_cents'] <= 0 || $summary['net_cents'] <= 0) {
                throw ValidationException::withMessages([
                    'payout_reference' => __('The supported posting path requires positive gross and net payout amounts.'),
                ]);
            }

            $bankAccountId = $this->accountingContext->defaultBankAccountId((int) $entryImport->company_id);
            $bankAccount = $bankAccountId
                ? BankAccount::query()->lockForUpdate()->find($bankAccountId)
                : null;
            if (! $bankAccount
                || ! $bankAccount->is_active
                || ! $bankAccount->is_default
                || ! $bankAccount->ledger_account_id
                || (int) $bankAccount->company_id !== (int) $entryImport->company_id
                || strtoupper((string) $bankAccount->currency_code) !== (string) $entryImport->currency) {
                throw ValidationException::withMessages([
                    'bank_account' => __('The default company bank account is not ready for SkipCash settlements.'),
                ]);
            }

            $remittanceEvidenceReference = trim((string) $remittanceEvidenceReference);
            $remittanceEvidence = $remittanceEvidenceReference !== ''
                ? $this->bankRemittanceEvidence(
                    $summary['contributing_import_ids'],
                    $remittanceEvidenceReference,
                    $payoutReference,
                    (int) $bankAccount->id,
                    (int) $summary['net_cents'],
                )
                : null;
            $bankEvidence = null;
            if ($evidenceBankTransactionId !== null) {
                $bankEvidence = BankTransaction::query()
                    ->whereKey($evidenceBankTransactionId)
                    ->where('company_id', $entryImport->company_id)
                    ->where('bank_account_id', $bankAccount->id)
                    ->whereNotNull('statement_import_id')
                    ->whereNull('source_type')
                    ->lockForUpdate()
                    ->first();
                if (! $bankEvidence
                    || $bankEvidence->direction !== 'inflow'
                    || $bankEvidence->status === 'void'
                    || $bankEvidence->matched_bank_transaction_id !== null
                    || $this->decimalToCents((string) $bankEvidence->amount) !== (int) $summary['net_cents']) {
                    throw ValidationException::withMessages([
                        'evidence_bank_transaction_id' => __('Choose an unmatched bank statement deposit for the exact payout net amount.'),
                    ]);
                }
                if (! $this->referencesComparable($bankEvidence->reference, $payoutReference) && $remittanceEvidence === null) {
                    throw ValidationException::withMessages([
                        'remittance_evidence_reference' => __('This bank deposit reference does not identify the payout. Attach retained remittance evidence.'),
                    ]);
                }
                if ($remittanceEvidence !== null
                    && $bankEvidence->transaction_date?->toDateString() !== $remittanceEvidence['bank_date']) {
                    throw ValidationException::withMessages([
                        'remittance_evidence_reference' => __('The bank statement date and retained remittance date must agree.'),
                    ]);
                }
            } elseif ($remittanceEvidence === null) {
                throw ValidationException::withMessages([
                    'bank_evidence' => __('Choose an exact bank statement deposit or retained bank remittance evidence.'),
                ]);
            }

            $this->mappings->assertRequiredMappings((int) $entryImport->company_id, [
                'skipcash_commission_expense',
                'skipcash_settlement_fee_expense',
            ]);
            $commissionAccountId = $this->mappings->resolveAccountId('skipcash_commission_expense', (int) $entryImport->company_id);
            $feeAccountId = $this->mappings->resolveAccountId('skipcash_settlement_fee_expense', (int) $entryImport->company_id);
            $source = $entryImport->paymentSource()->with('clearingAccount')->firstOrFail();
            $originalClearingBreakdown = $this->originalClearingBreakdown(
                $rows->whereIn('id', $summary['posting_row_ids'])->values(),
                $entryImport,
                (int) $summary['gross_cents'],
            );

            $snapshot = [
                'entry_import_id' => (int) $entryImport->id,
                'contributing_import_ids' => $summary['contributing_import_ids'],
                'member_row_ids' => $summary['member_row_ids'],
                'posting_row_ids' => $summary['posting_row_ids'],
                'payout_reference' => $payoutReference,
                'payout_fingerprint' => $summary['payout_fingerprint'],
                'gross_cents' => $summary['gross_cents'],
                'commission_cents' => $summary['commission_cents'],
                'settlement_fee_cents' => $summary['settlement_fee_cents'],
                'net_cents' => $summary['net_cents'],
                'bank_account_id' => (int) $bankAccount->id,
                'bank_ledger_account_id' => (int) $bankAccount->ledger_account_id,
                'evidence_bank_transaction_id' => $bankEvidence?->id !== null ? (int) $bankEvidence->id : null,
                'bank_remittance_evidence' => $remittanceEvidence,
                'settlement_date' => $bankEvidence?->transaction_date?->toDateString() ?? $remittanceEvidence['bank_date'],
                'payment_source_id' => (int) $source->id,
                'original_clearing_breakdown' => $originalClearingBreakdown,
                'commission_expense_account_id' => (int) $commissionAccountId,
                'settlement_fee_expense_account_id' => (int) $feeAccountId,
                'identifier_mapping_evidence' => $this->mappingEvidenceReference($entryImport),
                'reviewed_by' => (int) $actor->id,
                'reviewed_at' => now()->toISOString(),
            ];
            $snapshot['reviewed_fingerprint'] = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));

            $snapshots = $entryImport->review_snapshots ?? [];
            $snapshots[$payoutReference] = $snapshot;
            $entryImport->forceFill([
                'review_snapshots' => $snapshots,
                'review_state' => 'reviewed',
                'reviewed_by' => (int) $actor->id,
                'reviewed_at' => now(),
                'revision' => (int) $entryImport->revision + 1,
            ])->save();

            $this->audit->log('gateway_settlement_payout.reviewed', (int) $actor->id, $entryImport, [
                'payout_reference' => $payoutReference,
                'payout_fingerprint' => $summary['payout_fingerprint'],
                'reviewed_fingerprint' => $snapshot['reviewed_fingerprint'],
                'gross_cents' => $summary['gross_cents'],
                'net_cents' => $summary['net_cents'],
                'evidence_bank_transaction_id' => $bankEvidence?->id !== null ? (int) $bankEvidence->id : null,
                'bank_remittance_evidence_id' => $remittanceEvidence['id'] ?? null,
            ], (int) $entryImport->company_id);

            return [
                'import_revision' => (int) $entryImport->revision,
                ...$snapshot,
            ];
        });
    }

    /** @return array<string, mixed> */
    public function payoutSummary(int $paymentSourceId, string $payoutReference): array
    {
        return $this->summaryFromRows(
            $this->payoutRows($paymentSourceId, $payoutReference),
            $payoutReference,
        );
    }

    /**
     * @param  Collection<int, GatewaySettlementRow>  $rows
     * @return array<string, mixed>
     */
    public function summarizeLockedRows(Collection $rows, string $payoutReference): array
    {
        return $this->summaryFromRows($rows, $payoutReference);
    }

    /** @return Collection<int, GatewaySettlementRow> */
    private function payoutRows(int $paymentSourceId, string $payoutReference, bool $lock = false): Collection
    {
        $query = GatewaySettlementRow::query()
            ->where('payment_source_id', $paymentSourceId)
            ->where('payout_reference', $payoutReference)
            ->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    /**
     * @param  Collection<int, GatewaySettlementRow>  $rows
     * @return array<string, mixed>
     */
    private function summaryFromRows(Collection $rows, string $payoutReference): array
    {
        $blocking = [];
        $identityGroups = $rows->groupBy(fn (GatewaySettlementRow $row): string => $row->economic_identity !== null
            ? 'identity:'.$row->economic_identity
            : 'row:'.$row->id);
        $economicRows = collect();

        $gross = 0;
        $commission = 0;
        $fees = 0;
        $net = 0;
        foreach ($identityGroups as $group) {
            $group = $group->sortBy('id')->values();
            if ($group->count() > 1) {
                $signatures = $group->map(fn (GatewaySettlementRow $row): string => implode('|', [
                    (string) $row->financial_content_hash,
                    (string) $row->branch_id,
                    strtolower((string) $row->merchant),
                    (string) $row->status,
                    (string) $row->order_type,
                ]))->unique();
                if ($signatures->count() > 1 || $group->contains(fn (GatewaySettlementRow $row): bool => $row->error_code === 'ECONOMIC_IDENTITY_CONFLICT')) {
                    $blocking[] = 'ECONOMIC_IDENTITY_CONFLICT';
                }
            }

            foreach ($group as $member) {
                if ($member->error_code !== null) {
                    $blocking[] = $member->error_code;
                }
            }

            $matchedMembers = $group->filter(fn (GatewaySettlementRow $row): bool => $row->match_state === 'matched'
                && $row->matched_provider_transaction_id !== null);
            if ($matchedMembers->count() > 1) {
                $blocking[] = 'PAYMENT_ALREADY_CLAIMED';
            }
            $row = $matchedMembers->first() ?? $group->first();
            $economicRows->push($row);

            if ($row->order_type === 'sale') {
                if ($row->match_state !== 'matched' || $row->matched_provider_transaction_id === null) {
                    $blocking[] = 'SALE_UNMATCHED';
                }
                $gross += (int) $row->gross_cents;
                $commission += (int) $row->total_commission_cents;
            } elseif ($row->order_type === 'settlement_fee') {
                $fees += (int) $row->settlement_fee_cents;
            } else {
                $blocking[] = 'ORDER_TYPE_UNSUPPORTED';
            }
            $net += (int) $row->net_cents;
        }
        if ($gross - $commission - $fees !== $net) {
            $blocking[] = 'PAYOUT_TOTAL_MISMATCH';
        }
        if ($rows->isEmpty()) {
            $blocking[] = 'PAYOUT_EMPTY';
        }

        $members = $rows->map(fn (GatewaySettlementRow $row): array => [
            'id' => (int) $row->id,
            'revision' => (int) $row->revision,
            'economic_identity' => $row->economic_identity,
            'financial_content_hash' => $row->financial_content_hash,
            'match_state' => $row->match_state,
            'matched_provider_transaction_id' => $row->matched_provider_transaction_id,
            'evidence_reference' => $row->evidence_reference,
            'error_code' => $row->error_code,
        ])->values()->all();

        return [
            'payout_reference' => $payoutReference,
            'payout_fingerprint' => hash('sha256', json_encode($members, JSON_THROW_ON_ERROR)),
            'contributing_import_ids' => $rows->pluck('import_id')->map(fn ($id) => (int) $id)->unique()->values()->all(),
            'member_row_ids' => $rows->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'posting_row_ids' => $economicRows->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'row_count' => $rows->count(),
            'distinct_row_count' => $economicRows->count(),
            'duplicate_count' => $rows->count() - $economicRows->count(),
            'sale_count' => $economicRows->where('order_type', 'sale')->count(),
            'fee_count' => $economicRows->where('order_type', 'settlement_fee')->count(),
            'gross_cents' => $gross,
            'commission_cents' => $commission,
            'settlement_fee_cents' => $fees,
            'net_cents' => $net,
            'blocking_reasons' => array_values(array_unique($blocking)),
        ];
    }

    private function assertProviderMatch(
        GatewaySettlementImport $import,
        GatewaySettlementRow $row,
        PaymentProviderTransaction $transaction,
        ?string $providedEvidenceReference,
    ): string {
        $profile = config('skipcash.report_profiles.'.strtolower((string) $import->paymentSource->code));
        $mapping = is_array($profile) ? ($profile['identifier_mapping'] ?? null) : null;
        $reportField = $this->normalizedField(is_array($mapping) ? ($mapping['report_field'] ?? null) : null);
        $providerField = $this->normalizedField(is_array($mapping) ? ($mapping['provider_field'] ?? null) : null);
        $mappingEvidence = trim((string) (is_array($mapping) ? ($mapping['evidence_reference'] ?? '') : ''));
        $allowedProviderFields = [
            'providerpaymentid' => 'provider_payment_id',
            'merchanttransactionid' => 'merchant_transaction_id',
            'visaid' => 'visa_id',
        ];
        $configuredMappingReady = $reportField === 'referencenumber'
            && isset($allowedProviderFields[$providerField])
            && $mappingEvidence !== '';
        $providedEvidenceReference = trim((string) $providedEvidenceReference);
        if ($configuredMappingReady
            && ($providedEvidenceReference === '' || hash_equals($mappingEvidence, $providedEvidenceReference))) {
            $providerAttribute = $allowedProviderFields[$providerField];
            $confirmedEvidenceReference = $mappingEvidence;
        } else {
            $evidence = $providedEvidenceReference !== ''
                ? ($import->evidence_manifest[$providedEvidenceReference] ?? null)
                : null;
            $evidenceField = $this->normalizedField(is_array($evidence) ? ($evidence['provider_field'] ?? null) : null);
            $providerAttribute = $allowedProviderFields[$evidenceField] ?? null;
            $evidenceDisk = is_array($evidence) ? (string) ($evidence['private_disk'] ?? '') : '';
            $evidencePath = is_array($evidence) ? (string) ($evidence['object_key'] ?? '') : '';
            if (! is_array($evidence)
                || ($evidence['purpose'] ?? null) !== 'provider_reference'
                || (int) ($evidence['row_id'] ?? 0) !== (int) $row->id
                || ! hash_equals((string) ($evidence['payout_reference'] ?? ''), (string) $row->payout_reference)
                || ! hash_equals((string) ($evidence['report_value'] ?? ''), (string) $row->row_reference)
                || $providerAttribute === null
                || ! hash_equals((string) ($evidence['provider_value'] ?? ''), trim((string) $transaction->{$providerAttribute}))
                || $evidenceDisk === ''
                || $evidencePath === ''
                || ! Storage::disk($evidenceDisk)->exists($evidencePath)) {
                throw ValidationException::withMessages([
                    'evidence_reference' => __('Upload retained provider evidence that proves this report row reference matches the selected transaction.'),
                ]);
            }
            $confirmedEvidenceReference = $providedEvidenceReference;
        }
        $providerValue = trim((string) $transaction->{$providerAttribute});
        if ($row->row_reference === null || ! hash_equals($providerValue, (string) $row->row_reference)) {
            throw ValidationException::withMessages([
                'provider_transaction_id' => __('The verified provider reference does not match this report row.'),
            ]);
        }

        $attempt = $transaction->attempt;
        $payment = $transaction->payment;
        if (! $attempt || ! $payment
            || (int) $transaction->payment_source_id !== (int) $import->payment_source_id
            || $transaction->normalized_status !== 'paid'
            || $transaction->verified_paid_at === null
            || (int) ($transaction->verified_amount_cents ?? -1) !== (int) $row->gross_cents
            || strtoupper((string) $transaction->verified_currency) !== (string) $import->currency
            || (int) $attempt->company_id !== (int) $import->company_id
            || (int) $attempt->branch_id !== (int) $row->branch_id
            || $payment->source !== 'ar'
            || $payment->method !== 'skipcash'
            || $payment->voided_at !== null
            || $payment->clearing_settled_at !== null
            || (int) $payment->payment_source_id !== (int) $import->payment_source_id
            || (int) $payment->company_id !== (int) $import->company_id
            || (int) $payment->branch_id !== (int) $row->branch_id
            || strtoupper((string) $payment->currency) !== (string) $import->currency
            || (int) $payment->amount_cents !== (int) $row->gross_cents) {
            throw ValidationException::withMessages([
                'provider_transaction_id' => __('The provider transaction, receipt, company, branch, currency, or amount does not match this row.'),
            ]);
        }

        $entry = SubledgerEntry::query()
            ->with('lines')
            ->where('source_type', 'ar_payment')
            ->where('source_id', $payment->id)
            ->where('event', 'payment')
            ->where('status', 'posted')
            ->first();
        $clearingAccountId = (int) ($attempt->source_account_snapshot['clearing_account_id'] ?? 0);
        if ($clearingAccountId <= 0) {
            throw ValidationException::withMessages([
                'provider_transaction_id' => __('The original SkipCash clearing account snapshot is missing.'),
            ]);
        }
        $hasOriginalClearing = $entry?->lines->contains(function ($line) use ($clearingAccountId, $payment): bool {
            return (int) $line->account_id === $clearingAccountId
                && $this->decimalToCents((string) $line->debit) === (int) $payment->amount_cents
                && $this->decimalToCents((string) $line->credit) === 0;
        }) ?? false;
        if (! $hasOriginalClearing) {
            throw ValidationException::withMessages([
                'provider_transaction_id' => __('The original posted SkipCash clearing debit is missing or inconsistent.'),
            ]);
        }

        return $confirmedEvidenceReference;
    }

    /**
     * @param  Collection<int, GatewaySettlementRow>  $rows
     * @return array<int, array{id:int,code:string,name:string,amount_cents:int}>
     */
    private function originalClearingBreakdown(
        Collection $rows,
        GatewaySettlementImport $import,
        int $expectedGrossCents,
    ): array {
        $amounts = [];
        foreach ($rows->where('order_type', 'sale') as $row) {
            $transaction = PaymentProviderTransaction::query()
                ->with('attempt')
                ->findOrFail((int) $row->matched_provider_transaction_id);
            $sourceSnapshot = $transaction->attempt?->source_account_snapshot ?? [];
            $accountId = (int) ($sourceSnapshot['clearing_account_id'] ?? 0);
            if ($accountId <= 0) {
                throw ValidationException::withMessages([
                    'provider_transaction_id' => __('A matched receipt is missing its original clearing account snapshot.'),
                ]);
            }
            $amounts[$accountId] = ($amounts[$accountId] ?? 0) + (int) $row->gross_cents;
        }
        ksort($amounts);
        if (array_sum($amounts) !== $expectedGrossCents) {
            throw ValidationException::withMessages([
                'payout_reference' => __('The original receipt clearing amounts do not equal payout gross.'),
            ]);
        }

        $accounts = LedgerAccount::query()
            ->whereIn('id', array_keys($amounts))
            ->get()
            ->keyBy('id');

        return collect($amounts)->map(function (int $amountCents, int $accountId) use ($accounts, $import): array {
            $account = $accounts->get($accountId);
            if (! $account
                || (int) $account->company_id !== (int) $import->company_id
                || ! $account->is_active
                || ! $account->allow_direct_posting) {
                throw ValidationException::withMessages([
                    'account_mappings' => __('An original SkipCash clearing account is unavailable for posting.'),
                ]);
            }

            return [
                'id' => (int) $account->id,
                'code' => (string) $account->code,
                'name' => (string) $account->name,
                'amount_cents' => $amountCents,
            ];
        })->values()->all();
    }

    /**
     * @param  array<int, int>  $contributingImportIds
     * @return array{id:string,import_id:int,bank_account_id:int,amount_cents:int,bank_date:string,sha256:string}
     */
    private function bankRemittanceEvidence(
        array $contributingImportIds,
        string $evidenceReference,
        string $payoutReference,
        int $bankAccountId,
        int $amountCents,
    ): array {
        $matchingEntry = null;
        $matchingImportId = null;
        foreach (GatewaySettlementImport::query()->whereIn('id', $contributingImportIds)->orderBy('id')->get() as $import) {
            $entry = ($import->evidence_manifest ?? [])[$evidenceReference] ?? null;
            if (is_array($entry)) {
                $matchingEntry = $entry;
                $matchingImportId = (int) $import->id;
                break;
            }
        }

        $disk = is_array($matchingEntry) ? (string) ($matchingEntry['private_disk'] ?? '') : '';
        $objectKey = is_array($matchingEntry) ? (string) ($matchingEntry['object_key'] ?? '') : '';
        if (! is_array($matchingEntry)
            || ($matchingEntry['purpose'] ?? null) !== 'bank_remittance'
            || ! hash_equals((string) ($matchingEntry['payout_reference'] ?? ''), $payoutReference)
            || (int) ($matchingEntry['bank_account_id'] ?? 0) !== $bankAccountId
            || (int) ($matchingEntry['amount_cents'] ?? -1) !== $amountCents
            || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($matchingEntry['bank_date'] ?? ''))
            || $disk === ''
            || $objectKey === ''
            || ! Storage::disk($disk)->exists($objectKey)) {
            throw ValidationException::withMessages([
                'remittance_evidence_reference' => __('Choose retained remittance evidence for this payout, bank, exact amount, and bank date.'),
            ]);
        }

        return [
            'id' => $evidenceReference,
            'import_id' => (int) $matchingImportId,
            'bank_account_id' => $bankAccountId,
            'amount_cents' => $amountCents,
            'bank_date' => (string) $matchingEntry['bank_date'],
            'sha256' => (string) ($matchingEntry['sha256'] ?? ''),
        ];
    }

    private function referencesComparable(?string $left, ?string $right): bool
    {
        $left = $this->normalizedReference($left);
        $right = $this->normalizedReference($right);

        return $left !== ''
            && $right !== ''
            && ($left === $right || str_contains($left, $right) || str_contains($right, $left));
    }

    private function normalizedReference(?string $value): string
    {
        return strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '', trim((string) $value)));
    }

    public function invalidatePayoutReviews(GatewaySettlementRow $row): void
    {
        if ($row->payout_reference === null) {
            return;
        }

        $imports = GatewaySettlementImport::query()
            ->whereHas('rows', fn ($query) => $query
                ->where('payment_source_id', $row->payment_source_id)
                ->where('payout_reference', $row->payout_reference))
            ->lockForUpdate()
            ->get();
        foreach ($imports as $import) {
            $snapshots = $import->review_snapshots ?? [];
            unset($snapshots[$row->payout_reference]);
            $import->forceFill([
                'review_snapshots' => $snapshots !== [] ? $snapshots : null,
                'review_state' => $import->rows()->whereNotNull('error_code')->exists() ? 'blocked' : 'draft',
                'reviewed_by' => null,
                'reviewed_at' => null,
                'revision' => (int) $import->revision + 1,
            ])->save();
        }
    }

    private function auditMatch(GatewaySettlementRow $row, User $actor, string $action): void
    {
        $this->audit->log($action, (int) $actor->id, $row, [
            'import_id' => (int) $row->import_id,
            'payout_reference' => $row->payout_reference,
            'row_reference' => $row->row_reference,
            'provider_transaction_id' => $row->matched_provider_transaction_id,
            'match_state' => $row->match_state,
        ], (int) $row->import->company_id);
    }

    private function mappingEvidenceReference(GatewaySettlementImport $import): string
    {
        return trim((string) config(
            'skipcash.report_profiles.'.strtolower((string) $import->paymentSource->code).'.identifier_mapping.evidence_reference',
        ));
    }

    private function normalizedField(mixed $value): string
    {
        return strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '', trim((string) $value)));
    }

    private function decimalToCents(string $value): int
    {
        if (! preg_match('/^(-?)(\d+)(?:\.(\d{1,4}))?$/', trim($value), $matches)) {
            return PHP_INT_MIN;
        }
        $fraction = str_pad($matches[3] ?? '', 4, '0');
        if (substr($fraction, 2) !== '00') {
            return PHP_INT_MIN;
        }
        $cents = ((int) $matches[2] * 100) + (int) substr($fraction, 0, 2);

        return ($matches[1] ?? '') === '-' ? -$cents : $cents;
    }

    private function databaseInstantDate(mixed $value, string $timezone): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone($timezone))
                ->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }

    private function assertEnabled(): void
    {
        if (! config('skipcash.settlements.enabled', false)) {
            throw ValidationException::withMessages([
                'settlements' => __('SkipCash settlement mutations are disabled.'),
            ]);
        }
    }

    private function assertReviewAccess(User $actor): void
    {
        if (! $actor->can('gateway_settlements.review')) {
            throw new \Illuminate\Auth\Access\AuthorizationException('You are not allowed to review gateway settlements.');
        }
    }

    private function assertImportAccess(GatewaySettlementImport $import, User $actor): void
    {
        $defaultCompanyId = $this->accountingContext->defaultCompanyId();
        if ($defaultCompanyId === null || (int) $import->company_id !== $defaultCompanyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException('The settlement import is outside the active company.');
        }
        $this->assertRowsAccess($import->rows()->get(), $actor);
    }

    /** @param Collection<int, GatewaySettlementRow> $rows */
    private function assertRowsAccess(Collection $rows, User $actor): void
    {
        if ($actor->isAdmin()) {
            return;
        }
        $allowed = $actor->allowedBranchIds();
        if ($allowed === [] || $rows->contains(fn (GatewaySettlementRow $row) => $row->branch_id === null
            || ! in_array((int) $row->branch_id, $allowed, true))) {
            throw new \Illuminate\Auth\Access\AuthorizationException('The settlement payout includes an unavailable branch.');
        }
    }
}
