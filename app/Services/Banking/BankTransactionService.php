<?php

namespace App\Services\Banking;

use App\Models\ApPayment;
use App\Models\Payment;
use App\Models\BankTransaction;
use App\Services\Accounting\LedgerAccountMappingService;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\Accounting\AccountingContextService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BankTransactionService
{
    public function __construct(
        protected AccountingContextService $context,
        protected LedgerAccountMappingService $mappingService,
        protected AccountingAuditLogService $auditLog
    ) {
    }

    public function recordApPayment(ApPayment $payment, int $actorId): ?BankTransaction
    {
        if ($this->mappingService->normalizePaymentMethod($payment->payment_method) !== 'bank_transfer') {
            return null;
        }

        if (! Schema::hasTable('bank_transactions') || ! Schema::hasTable('bank_accounts')) {
            return null;
        }

        $bankAccountId = (int) ($payment->bank_account_id ?? 0);
        if ($bankAccountId <= 0) {
            $bankAccountId = (int) ($this->context->defaultBankAccountId((int) ($payment->company_id ?? 0)) ?? 0);
        }

        if ($bankAccountId <= 0) {
            return null;
        }

        $payment->bank_account_id = $bankAccountId;
        if (! $payment->company_id) {
            $payment->company_id = $this->context->resolveCompanyId((int) ($payment->branch_id ?? 0));
        }
        if (! $payment->period_id) {
            $payment->period_id = $this->context->resolvePeriodId(optional($payment->payment_date)->toDateString(), (int) $payment->company_id);
        }
        $payment->save();

        $transaction = $this->firstOrCreateBySource([
            'source_type' => ApPayment::class,
            'source_id' => $payment->id,
            'transaction_type' => 'ap_payment',
        ], function () use ($payment, $bankAccountId) {
            return [
                'company_id' => $payment->company_id,
                'bank_account_id' => $bankAccountId,
                'period_id' => $payment->period_id,
                'reconciliation_run_id' => null,
                'transaction_type' => 'ap_payment',
                'transaction_date' => optional($payment->payment_date)->toDateString() ?: now()->toDateString(),
                'amount' => abs((float) $payment->amount),
                'direction' => 'outflow',
                'status' => $payment->voided_at ? 'void' : 'open',
                'is_cleared' => false,
                'cleared_date' => null,
                'reference' => $payment->reference,
                'memo' => $payment->notes ?: 'AP payment '.$payment->id,
                'source_type' => ApPayment::class,
                'source_id' => $payment->id,
                'statement_import_id' => null,
            ];
        });

        $this->auditLog->log('bank_transaction.recorded', $actorId, $transaction, [
            'payment_id' => (int) $payment->id,
            'bank_account_id' => $bankAccountId,
        ], (int) $payment->company_id);

        return $transaction;
    }

    public function recordArPayment(Payment $payment, int $actorId): ?BankTransaction
    {
        if ($this->mappingService->normalizePaymentMethod($payment->method) !== 'bank_transfer') {
            return null;
        }

        if (! Schema::hasTable('bank_transactions') || ! Schema::hasTable('bank_accounts')) {
            return null;
        }

        $bankAccountId = (int) ($payment->bank_account_id ?? 0);
        if ($bankAccountId <= 0) {
            $bankAccountId = (int) ($this->context->defaultBankAccountId((int) ($payment->company_id ?? 0)) ?? 0);
        }

        if ($bankAccountId <= 0) {
            return null;
        }

        if (! $payment->company_id) {
            $payment->company_id = $this->context->resolveCompanyId((int) ($payment->branch_id ?? 0));
        }
        if (! $payment->period_id) {
            $payment->period_id = $this->context->resolvePeriodId(optional($payment->received_at)->toDateString(), (int) $payment->company_id);
        }
        $payment->bank_account_id = $bankAccountId;
        $payment->save();

        $transaction = $this->firstOrCreateBySource([
            'source_type' => Payment::class,
            'source_id' => $payment->id,
            'transaction_type' => 'ar_payment',
        ], function () use ($payment, $bankAccountId) {
            return [
                'company_id' => $payment->company_id,
                'bank_account_id' => $bankAccountId,
                'period_id' => $payment->period_id,
                'reconciliation_run_id' => null,
                'transaction_type' => 'ar_payment',
                'transaction_date' => optional($payment->received_at)->toDateString() ?: now()->toDateString(),
                'amount' => abs((float) $payment->amount_cents / max((int) config('pos.money_scale', 100), 1)),
                'direction' => 'inflow',
                'status' => $payment->voided_at ? 'void' : 'open',
                'is_cleared' => false,
                'cleared_date' => null,
                'reference' => $payment->reference,
                'memo' => $payment->notes ?: 'AR payment '.$payment->id,
                'source_type' => Payment::class,
                'source_id' => $payment->id,
                'statement_import_id' => null,
            ];
        });

        $this->auditLog->log('bank_transaction.recorded', $actorId, $transaction, [
            'payment_id' => (int) $payment->id,
            'bank_account_id' => $bankAccountId,
            'source' => 'ar',
        ], (int) $payment->company_id);

        return $transaction;
    }

    public function voidApPayment(ApPayment $payment, int $actorId): void
    {
        if (! Schema::hasTable('bank_transactions')) {
            return;
        }

        BankTransaction::query()
            ->where('source_type', ApPayment::class)
            ->where('source_id', $payment->id)
            ->update([
                'status' => 'void',
                'updated_at' => now(),
            ]);

        $this->auditLog->log('bank_transaction.voided', $actorId, $payment, [
            'payment_id' => (int) $payment->id,
        ], (int) ($payment->company_id ?? 0) ?: null);
    }

    public function voidArPayment(Payment $payment, int $actorId): void
    {
        if (! Schema::hasTable('bank_transactions')) {
            return;
        }

        BankTransaction::query()
            ->where('source_type', Payment::class)
            ->where('source_id', $payment->id)
            ->update([
                'status' => 'void',
                'updated_at' => now(),
            ]);

        $this->auditLog->log('bank_transaction.voided', $actorId, $payment, [
            'payment_id' => (int) $payment->id,
            'source' => 'ar',
        ], (int) ($payment->company_id ?? 0) ?: null);
    }

    /**
     * @param  array{source_type:string,source_id:int|string,transaction_type:string}  $identity
     * @param  \Closure():array<string,mixed>  $payload
     */
    protected function firstOrCreateBySource(array $identity, \Closure $payload): BankTransaction
    {
        $existing = BankTransaction::query()
            ->where('source_type', $identity['source_type'])
            ->where('source_id', $identity['source_id'])
            ->where('transaction_type', $identity['transaction_type'])
            ->first();

        if ($existing) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($payload) {
                return BankTransaction::query()->create($payload());
            });
        } catch (QueryException $e) {
            $existing = BankTransaction::query()
                ->where('source_type', $identity['source_type'])
                ->where('source_id', $identity['source_id'])
                ->where('transaction_type', $identity['transaction_type'])
                ->first();

            if ($existing) {
                return $existing;
            }

            throw $e;
        }
    }
}
