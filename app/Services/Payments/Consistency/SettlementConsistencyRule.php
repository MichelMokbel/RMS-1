<?php

namespace App\Services\Payments\Consistency;

use App\Models\GatewaySettlementImport;
use Illuminate\Support\Facades\DB;

class SettlementConsistencyRule implements PaymentConsistencyRule
{
    public const CODE = 'settlement_v1';

    public function ruleCodes(): array
    {
        return [self::CODE];
    }

    public function scope(string $ruleCode, string $subjectType, int $subjectId): array
    {
        $import = $this->settlementImport($ruleCode, $subjectType, $subjectId);

        return [
            'company_id' => (int) $import->company_id,
            'branch_id' => null,
            'checkout_id' => null,
        ];
    }

    public function evaluate(string $ruleCode, string $subjectType, int $subjectId): array
    {
        $import = $this->settlementImport($ruleCode, $subjectType, $subjectId);
        $source = DB::table('payment_sources')->where('id', $import->payment_source_id)->first([
            'id', 'company_id', 'code', 'method', 'clearing_account_id', 'is_active', 'created_at', 'updated_at',
        ]);
        $rows = DB::table('gateway_settlement_rows')->where('import_id', $import->id)->orderBy('id')->get([
            'id', 'import_id', 'payment_source_id', 'row_sequence', 'order_type', 'status', 'payout_reference',
            'row_reference', 'economic_identity', 'financial_content_hash', 'customer_phone_hash', 'branch_code',
            'branch_id', 'transaction_at', 'explicit_bank_date', 'sales_cents', 'gross_cents',
            'variable_commission_cents', 'fixed_commission_cents', 'total_commission_cents',
            'settlement_fee_cents', 'net_cents', 'match_state', 'matched_provider_transaction_id',
            'duplicate_of_row_id', 'evidence_reference', 'reviewed_by', 'reviewed_at', 'error_code',
            'revision', 'created_at', 'updated_at',
        ]);
        $payoutReferences = $rows->pluck('payout_reference')->filter()->unique()->values();
        $crossImportRows = DB::table('gateway_settlement_rows')
            ->where('payment_source_id', $import->payment_source_id)
            ->whereIn('payout_reference', $payoutReferences)
            ->orderBy('id')->get([
                'id', 'import_id', 'payment_source_id', 'row_sequence', 'order_type', 'status', 'payout_reference',
                'row_reference', 'economic_identity', 'financial_content_hash', 'branch_id', 'transaction_at',
                'explicit_bank_date', 'gross_cents', 'total_commission_cents', 'settlement_fee_cents',
                'net_cents', 'match_state', 'matched_provider_transaction_id', 'duplicate_of_row_id',
                'evidence_reference', 'revision', 'created_at', 'updated_at',
            ]);
        $settlements = DB::table('ar_clearing_settlements')
            ->where('payment_source_id', $import->payment_source_id)
            ->whereIn('payout_reference', $payoutReferences)
            ->orderBy('id')->get([
                'id', 'company_id', 'payment_source_id', 'gateway_import_id', 'bank_account_id',
                'evidence_bank_transaction_id', 'settlement_method', 'settlement_date', 'amount_cents',
                'commission_cents', 'settlement_fee_cents', 'net_cents', 'client_uuid', 'payout_reference',
                'reviewed_fingerprint', 'commission_expense_account_id', 'settlement_fee_expense_account_id',
                'bank_ledger_account_id', 'created_by', 'voided_at', 'voided_by', 'created_at', 'updated_at',
            ]);
        $items = DB::table('ar_clearing_settlement_items')->whereIn('settlement_id', $settlements->pluck('id'))
            ->orderBy('id')->get([
                'id', 'settlement_id', 'payment_id', 'provider_transaction_id', 'gateway_settlement_row_id',
                'amount_cents', 'created_at', 'updated_at',
            ]);
        $adjustments = DB::table('ar_clearing_settlement_adjustments')->whereIn('settlement_id', $settlements->pluck('id'))
            ->orderBy('id')->get([
                'id', 'settlement_id', 'payment_source_id', 'expense_account_id', 'gateway_settlement_row_id',
                'adjustment_type', 'economic_identity', 'amount_cents', 'created_at', 'updated_at',
            ]);
        $providerTransactions = DB::table('payment_provider_transactions')
            ->whereIn('id', $items->pluck('provider_transaction_id')->filter())
            ->orderBy('id')->get([
                'id', 'attempt_id', 'payment_source_id', 'provider_payment_id', 'merchant_transaction_id',
                'verified_amount_cents', 'verified_currency', 'verified_paid_at', 'payment_id',
                'active_clearing_settlement_id', 'created_at', 'updated_at',
            ])->keyBy('id');
        $payments = DB::table('payments')->whereIn('id', $items->pluck('payment_id')->filter())->orderBy('id')->get([
            'id', 'company_id', 'branch_id', 'customer_id', 'payment_source_id', 'source', 'method',
            'amount_cents', 'currency', 'received_at', 'voided_at', 'clearing_settled_at', 'created_at', 'updated_at',
        ])->keyBy('id');
        $bankAccounts = DB::table('bank_accounts')->whereIn('id', $settlements->pluck('bank_account_id')->filter())
            ->orderBy('id')->get([
                'id', 'company_id', 'ledger_account_id', 'branch_id', 'code', 'currency_code',
                'is_default', 'is_active', 'created_at', 'updated_at',
            ])->keyBy('id');
        $bankTransactions = DB::table('bank_transactions')
            ->where(function ($query) use ($settlements): void {
                $query->whereIn('id', $settlements->pluck('evidence_bank_transaction_id')->filter())
                    ->orWhere(function ($source) use ($settlements): void {
                        $source->where('source_type', 'App\\Models\\ArClearingSettlement')
                            ->whereIn('source_id', $settlements->pluck('id'));
                    });
            })->orderBy('id')->get([
                'id', 'company_id', 'bank_account_id', 'matched_bank_transaction_id', 'transaction_type',
                'transaction_date', 'amount', 'direction', 'status', 'is_cleared', 'cleared_date',
                'source_type', 'source_id', 'statement_import_id', 'created_at', 'updated_at',
            ]);
        $subledger = DB::table('subledger_entries')->where('source_type', 'ar_clearing_settlement')
            ->whereIn('source_id', $settlements->pluck('id'))->orderBy('id')->get([
                'id', 'source_type', 'source_id', 'company_id', 'event', 'entry_date', 'status',
                'posted_at', 'voided_at', 'created_at', 'updated_at',
            ]);
        $subledgerLines = DB::table('subledger_lines')->whereIn('entry_id', $subledger->pluck('id'))->orderBy('id')->get([
            'id', 'entry_id', 'account_id', 'debit', 'credit', 'created_at', 'updated_at',
        ]);
        $issues = [];

        if (! $source || (int) $source->company_id !== (int) $import->company_id
            || (string) $source->method !== 'skipcash' || (int) $source->clearing_account_id <= 0) {
            $issues[] = ConsistencyEvidence::issue('SETTLEMENT_PAYMENT_SOURCE_MISMATCH', 'gateway_settlement_import', (int) $import->id);
        }
        if ($import->currency !== 'QAR' || $import->timezone !== 'Asia/Qatar'
            || (int) $import->gross_cents - (int) $import->commission_cents - (int) $import->settlement_fee_cents !== (int) $import->net_cents) {
            $issues[] = ConsistencyEvidence::issue('SETTLEMENT_IMPORT_TOTAL_MISMATCH', 'gateway_settlement_import', (int) $import->id);
        }
        foreach ($rows as $row) {
            if ((int) $row->payment_source_id !== (int) $import->payment_source_id) {
                $issues[] = ConsistencyEvidence::issue('SETTLEMENT_ROW_SOURCE_MISMATCH', 'gateway_settlement_row', (int) $row->id);
            }
            if ($row->order_type === 'sale' && $row->gross_cents !== null
                && (int) $row->gross_cents - (int) ($row->total_commission_cents ?? 0) !== (int) $row->net_cents) {
                $issues[] = ConsistencyEvidence::issue('SETTLEMENT_SALE_ROW_TOTAL_MISMATCH', 'gateway_settlement_row', (int) $row->id);
            }
            if ($row->order_type === 'settlement_fee' && (int) ($row->settlement_fee_cents ?? 0) <= 0) {
                $issues[] = ConsistencyEvidence::issue('SETTLEMENT_FEE_ROW_INVALID', 'gateway_settlement_row', (int) $row->id);
            }
            if ($row->duplicate_of_row_id === null && $row->economic_identity
                && $crossImportRows->where('economic_identity', $row->economic_identity)->whereNull('duplicate_of_row_id')->count() > 1) {
                $issues[] = ConsistencyEvidence::issue('SETTLEMENT_ECONOMIC_IDENTITY_DUPLICATE', 'gateway_settlement_row', (int) $row->id);
            }
        }

        foreach ($settlements as $settlement) {
            $payoutRows = $crossImportRows->where('payout_reference', $settlement->payout_reference)
                ->whereNull('duplicate_of_row_id');
            $saleRows = $payoutRows->where('order_type', 'sale');
            $gross = (int) $saleRows->sum('gross_cents');
            $commission = (int) $saleRows->sum('total_commission_cents');
            $fee = (int) $payoutRows->sum('settlement_fee_cents');
            $net = $gross - $commission - $fee;
            $settlementItems = $items->where('settlement_id', $settlement->id);
            $settlementAdjustments = $adjustments->where('settlement_id', $settlement->id);
            $bank = $bankAccounts->get((int) $settlement->bank_account_id);

            if ($settlement->voided_at !== null) {
                $issues[] = ConsistencyEvidence::issue('SETTLEMENT_VOID_REQUIRES_CORRECTION', 'ar_clearing_settlement', (int) $settlement->id);

                continue;
            }
            if ((int) $settlement->company_id !== (int) $import->company_id
                || (int) $settlement->payment_source_id !== (int) $import->payment_source_id
                || (string) $settlement->settlement_method !== 'skipcash'
                || (int) $settlement->amount_cents !== $gross
                || (int) $settlement->commission_cents !== $commission
                || (int) $settlement->settlement_fee_cents !== $fee
                || (int) $settlement->net_cents !== $net) {
                $issues[] = ConsistencyEvidence::issue('SETTLEMENT_POSTED_TOTAL_MISMATCH', 'ar_clearing_settlement', (int) $settlement->id);
            }
            if ((int) $settlementItems->sum('amount_cents') !== $gross
                || (int) $settlementAdjustments->where('adjustment_type', 'commission')->sum('amount_cents') !== $commission
                || (int) $settlementAdjustments->where('adjustment_type', 'settlement_fee')->sum('amount_cents') !== $fee) {
                $issues[] = ConsistencyEvidence::issue('SETTLEMENT_POSTED_COMPONENT_MISMATCH', 'ar_clearing_settlement', (int) $settlement->id);
            }
            if (! $bank || (int) $bank->company_id !== (int) $settlement->company_id
                || (string) $bank->currency_code !== (string) $import->currency
                || (int) $settlement->bank_ledger_account_id <= 0) {
                $issues[] = ConsistencyEvidence::issue('SETTLEMENT_BANK_SNAPSHOT_MISMATCH', 'ar_clearing_settlement', (int) $settlement->id);
            }
            foreach ($settlementItems as $item) {
                $transaction = $providerTransactions->get((int) $item->provider_transaction_id);
                $payment = $payments->get((int) $item->payment_id);
                if (! $transaction || ! $payment
                    || (int) $transaction->payment_id !== (int) $payment->id
                    || (int) $transaction->active_clearing_settlement_id !== (int) $settlement->id
                    || (int) $payment->payment_source_id !== (int) $settlement->payment_source_id
                    || (int) $payment->amount_cents !== (int) $item->amount_cents
                    || $payment->voided_at !== null || $payment->clearing_settled_at === null) {
                    $issues[] = ConsistencyEvidence::issue('SETTLEMENT_RECEIPT_CLAIM_MISMATCH', 'ar_clearing_settlement_item', (int) $item->id);
                }
            }
            $book = $bankTransactions->first(fn ($row): bool => $row->source_type === 'App\\Models\\ArClearingSettlement'
                && (int) $row->source_id === (int) $settlement->id);
            if (! $book || (int) $book->bank_account_id !== (int) $settlement->bank_account_id
                || $book->direction !== 'inflow' || $this->decimalCents($book->amount) !== $net) {
                $issues[] = ConsistencyEvidence::issue('SETTLEMENT_BANK_ENTRY_MISMATCH', 'ar_clearing_settlement', (int) $settlement->id);
            }
            $entry = $subledger->first(fn ($candidate): bool => (int) $candidate->source_id === (int) $settlement->id
                && (string) $candidate->event === 'settle'
                && (string) $candidate->status === 'posted');
            if (! $entry) {
                $issues[] = ConsistencyEvidence::issue('SETTLEMENT_SUBLEDGER_MISSING', 'ar_clearing_settlement', (int) $settlement->id);
            } else {
                $lines = $subledgerLines->where('entry_id', $entry->id);
                $debitCents = $this->decimalCents($lines->sum('debit'));
                $creditCents = $this->decimalCents($lines->sum('credit'));
                if ($lines->isEmpty() || $debitCents !== $creditCents || $debitCents !== $gross) {
                    $issues[] = ConsistencyEvidence::issue('SETTLEMENT_SUBLEDGER_MISMATCH', 'ar_clearing_settlement', (int) $settlement->id);
                }
            }
        }
        if (in_array((string) $import->posting_state, ['posted', 'partially_posted'], true)
            && $settlements->isEmpty()) {
            $issues[] = ConsistencyEvidence::issue('SETTLEMENT_POSTING_STATE_WITHOUT_SETTLEMENT', 'gateway_settlement_import', (int) $import->id);
        }

        return ConsistencyEvidence::result(
            (int) $import->company_id,
            null,
            null,
            [
                'gross_minus_commission_minus_fee_equals_net' => true,
                'reviewed_economic_rows_are_unique' => true,
                'posted_payout_claims_exact_receipts' => true,
                'posted_payout_retains_bank_snapshot_and_balanced_entries' => true,
                'staged_unposted_import_is_valid' => true,
            ],
            [
                'import_id' => (int) $import->id,
                'review_state' => (string) $import->review_state,
                'posting_state' => (string) $import->posting_state,
                'row_count' => $rows->count(),
                'payout_references' => $payoutReferences->all(),
                'settlement_ids' => $settlements->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            ],
            [
                'import' => $import->only([
                    'id', 'company_id', 'payment_source_id', 'file_hash', 'parser_version', 'currency', 'timezone',
                    'report_period_start', 'report_period_end', 'gross_cents', 'commission_cents',
                    'settlement_fee_cents', 'net_cents', 'totals_complete', 'review_state', 'posting_state',
                    'revision', 'reviewed_by', 'reviewed_at', 'error_code', 'created_at', 'updated_at',
                ]),
                'source' => $source ? (array) $source : ['missing' => true],
                'rows' => $rows->map(fn ($row): array => (array) $row)->all(),
                'cross_import_rows' => $crossImportRows->map(fn ($row): array => (array) $row)->all(),
                'settlements' => $settlements->map(fn ($row): array => (array) $row)->all(),
                'items' => $items->map(fn ($row): array => (array) $row)->all(),
                'adjustments' => $adjustments->map(fn ($row): array => (array) $row)->all(),
                'provider_transactions' => $providerTransactions->values()->map(fn ($row): array => (array) $row)->all(),
                'payments' => $payments->values()->map(fn ($row): array => (array) $row)->all(),
                'bank_accounts' => $bankAccounts->values()->map(fn ($row): array => (array) $row)->all(),
                'bank_transactions' => $bankTransactions->map(fn ($row): array => (array) $row)->all(),
                'subledger' => $subledger->map(fn ($row): array => (array) $row)->all(),
                'subledger_lines' => $subledgerLines->map(fn ($row): array => (array) $row)->all(),
            ],
            $issues,
        );
    }

    private function settlementImport(string $ruleCode, string $subjectType, int $subjectId): GatewaySettlementImport
    {
        if ($ruleCode !== self::CODE || $subjectType !== 'gateway_settlement_import') {
            throw new \InvalidArgumentException('Unsupported settlement consistency subject.');
        }

        return GatewaySettlementImport::query()->findOrFail($subjectId);
    }

    private function decimalCents(mixed $value): int
    {
        return (int) round((float) $value * 100);
    }
}
