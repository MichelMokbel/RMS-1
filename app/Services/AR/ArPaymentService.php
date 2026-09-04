<?php

namespace App\Services\AR;

use App\Models\ArInvoice;
use App\Models\Customer;
use App\Models\LedgerAccount;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentSource;
use App\Models\User;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\Accounting\AccountingContextService;
use App\Services\Accounting\LedgerAccountMappingService;
use App\Services\Banking\BankTransactionService;
use App\Services\Ledger\SubledgerService;
use App\Services\Payments\SavedCreditAllocationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ArPaymentService
{
    public function __construct(
        protected ArInvoiceService $invoices,
        protected ArAllocationService $allocations,
        protected SubledgerService $subledgerService,
        protected AccountingContextService $accountingContext,
        protected LedgerAccountMappingService $mappingService,
        protected BankTransactionService $bankTransactionService,
        protected AccountingAuditLogService $auditLog,
        protected ArAllocationIntegrityService $allocationIntegrity,
    ) {}

    public function createAdvancePayment(
        int $customerId,
        int $branchId,
        int $amountCents,
        string $method,
        ?string $receivedAt,
        ?string $reference,
        ?string $notes,
        int $actorId,
        ?string $clientUuid = null,
        ?int $terminalId = null,
        ?int $posShiftId = null,
        ?string $currency = null
    ): Payment {
        $clientUuid = trim((string) ($clientUuid ?? ''));
        if ($amountCents <= 0) {
            throw ValidationException::withMessages(['amount_cents' => __('Payment amount must be positive.')]);
        }

        $customer = Customer::find($customerId);
        if (! $customer) {
            throw ValidationException::withMessages(['customer_id' => __('Customer not found.')]);
        }

        if ($clientUuid !== '') {
            $existing = Payment::query()->where('client_uuid', $clientUuid)->first();
            if ($existing) {
                $this->assertReplayMatchesAdvance($existing, [
                    'customer_id' => $customer->id,
                    'branch_id' => $branchId,
                    'amount_cents' => $amountCents,
                    'method' => $method,
                    'currency' => $currency ?: (string) config('pos.currency'),
                ]);

                return $existing->fresh();
            }
        }

        try {
            return DB::transaction(function () use ($customer, $branchId, $amountCents, $method, $receivedAt, $reference, $notes, $actorId, $clientUuid, $terminalId, $posShiftId, $currency) {
                $companyId = $this->accountingContext->resolveCompanyId($branchId);
                $normalizedMethod = $this->mappingService->normalizePaymentMethod($method);
                $payment = Payment::create([
                    'branch_id' => $branchId,
                    'customer_id' => $customer->id,
                    'company_id' => $companyId,
                    'bank_account_id' => $this->resolveBankAccountId($normalizedMethod, $companyId, null),
                    'period_id' => $this->accountingContext->resolvePeriodId($receivedAt, $companyId),
                    'client_uuid' => $clientUuid !== '' ? $clientUuid : null,
                    'terminal_id' => $terminalId,
                    'pos_shift_id' => $posShiftId,
                    'source' => 'ar',
                    'method' => $normalizedMethod,
                    'amount_cents' => $amountCents,
                    'currency' => $currency ?: (string) config('pos.currency'),
                    'received_at' => $receivedAt ?? now(),
                    'reference' => $reference ?: null,
                    'notes' => $notes ?: null,
                    'created_by' => $actorId,
                ]);

                $this->subledgerService->recordArPaymentReceived($payment, 0, $amountCents, $actorId);
                $this->bankTransactionService->recordArPayment($payment->fresh(), $actorId);
                $this->auditLog->log('ar_payment.created', $actorId, $payment, [
                    'applied_cents' => 0,
                    'unapplied_cents' => (int) $amountCents,
                    'reference' => $reference,
                ]);

                return $payment->fresh();
            });
        } catch (QueryException $e) {
            if ($clientUuid !== '') {
                $existing = Payment::query()->where('client_uuid', $clientUuid)->first();
                if ($existing) {
                    $this->assertReplayMatchesAdvance($existing, [
                        'customer_id' => $customer->id,
                        'branch_id' => $branchId,
                        'amount_cents' => $amountCents,
                        'method' => $method,
                        'currency' => $currency ?: (string) config('pos.currency'),
                    ]);

                    return $existing->fresh();
                }
            }

            throw $e;
        }
    }

    public function createPaymentWithAllocations(array $payload, int $actorId): Payment
    {
        $amount = (int) ($payload['amount_cents'] ?? 0);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount_cents' => __('Payment amount must be positive.')]);
        }

        $customerId = (int) ($payload['customer_id'] ?? 0);
        if ($customerId <= 0) {
            throw ValidationException::withMessages(['customer_id' => __('Customer is required.')]);
        }

        $customer = Customer::find($customerId);
        if (! $customer) {
            throw ValidationException::withMessages(['customer_id' => __('Customer not found.')]);
        }

        $branchId = (int) ($payload['branch_id'] ?? 0);
        if ($branchId <= 0) {
            $branchId = 1;
        }

        $method = $this->mappingService->normalizePaymentMethod((string) ($payload['method'] ?? 'bank_transfer'));
        $currency = (string) ($payload['currency'] ?? config('pos.currency'));
        $receivedAt = $payload['received_at'] ?? now();
        $reference = $payload['reference'] ?? null;
        $notes = $payload['notes'] ?? null;
        $rows = $payload['allocations'] ?? [];
        $clientUuid = trim((string) ($payload['client_uuid'] ?? ''));
        $terminalId = $payload['terminal_id'] ?? null;
        $posShiftId = $payload['pos_shift_id'] ?? null;
        $bankAccountId = $payload['bank_account_id'] ?? null;
        $paymentSourceId = $payload['payment_source_id'] ?? null;
        $settlementAccountId = $payload['settlement_account_id'] ?? null;
        $recordBankTransaction = array_key_exists('record_bank_transaction', $payload)
            ? (bool) $payload['record_bank_transaction']
            : true;

        usort($rows, function ($a, $b) {
            return (int) ($a['invoice_id'] ?? 0) <=> (int) ($b['invoice_id'] ?? 0);
        });

        if ($clientUuid !== '') {
            $existing = Payment::query()->with(['allocations'])->where('client_uuid', $clientUuid)->first();
            if ($existing) {
                $this->assertReplayMatchesAllocatedPayment($existing, [
                    'customer_id' => $customer->id,
                    'branch_id' => $branchId,
                    'amount_cents' => $amount,
                    'method' => $method,
                    'currency' => $currency,
                    'allocations' => $rows,
                ]);

                return $existing->fresh(['allocations']);
            }
        }

        try {
            return DB::transaction(function () use ($customer, $branchId, $amount, $method, $currency, $receivedAt, $reference, $notes, $rows, $actorId, $clientUuid, $terminalId, $posShiftId, $bankAccountId, $paymentSourceId, $settlementAccountId, $recordBankTransaction) {
                $companyId = $this->accountingContext->resolveCompanyId($branchId);

                $resolvedPaymentSourceId = $this->resolvePaymentSourceId(
                    $paymentSourceId,
                    $settlementAccountId,
                    $companyId,
                    $method,
                );
                $resolvedSettlementAccountId = $this->resolveSettlementAccountId(
                    $settlementAccountId,
                    $companyId,
                    $resolvedPaymentSourceId,
                );

                $lockedInvoices = [];
                foreach ($rows as $row) {
                    $invoiceId = (int) ($row['invoice_id'] ?? 0);
                    if ($invoiceId <= 0) {
                        throw ValidationException::withMessages(['allocations' => __('Invoice not found.')]);
                    }

                    $requested = (int) ($row['amount_cents'] ?? 0);
                    if ($requested <= 0) {
                        throw ValidationException::withMessages(['allocations' => __('Allocation amount must be positive.')]);
                    }

                    $invoice = ArInvoice::whereKey($invoiceId)->lockForUpdate()->first();
                    if (! $invoice) {
                        throw ValidationException::withMessages(['allocations' => __('Invoice not found.')]);
                    }

                    $invoiceCompanyId = $this->allocationIntegrity->resolveInvoiceCompanyId($invoice);
                    if ($invoiceCompanyId !== (int) $companyId) {
                        throw ValidationException::withMessages([
                            'allocations' => __('Payment and allocated invoices must belong to the same accounting company.'),
                        ]);
                    }

                    if ($invoice->customer_id !== $customer->id) {
                        throw ValidationException::withMessages(['allocations' => __('Invoice customer must match payment customer.')]);
                    }

                    if (! in_array($invoice->status, ['issued', 'partially_paid', 'paid'], true)) {
                        throw ValidationException::withMessages(['allocations' => __('Invoice must be issued to accept payments.')]);
                    }

                    $lockedInvoices[$invoiceId] = $invoice;
                }

                $payment = Payment::create([
                    'branch_id' => $branchId,
                    'customer_id' => $customer->id,
                    'company_id' => $companyId,
                    'bank_account_id' => $this->resolveBankAccountId($method, $companyId, $bankAccountId),
                    'payment_source_id' => $resolvedPaymentSourceId,
                    'period_id' => $this->accountingContext->resolvePeriodId((string) $receivedAt, $companyId),
                    'client_uuid' => $clientUuid !== '' ? $clientUuid : null,
                    'terminal_id' => $terminalId,
                    'pos_shift_id' => $posShiftId,
                    'source' => 'ar',
                    'method' => $method,
                    'amount_cents' => $amount,
                    'currency' => $currency,
                    'received_at' => $receivedAt,
                    'reference' => $reference,
                    'notes' => $notes,
                    'created_by' => $actorId,
                ]);

                $remaining = $amount;
                foreach ($rows as $row) {
                    if ($remaining <= 0) {
                        break;
                    }

                    $invoiceId = (int) ($row['invoice_id'] ?? 0);
                    if ($invoiceId <= 0) {
                        throw ValidationException::withMessages(['allocations' => __('Invoice not found.')]);
                    }

                    $requested = (int) ($row['amount_cents'] ?? 0);
                    if ($requested <= 0) {
                        throw ValidationException::withMessages(['allocations' => __('Allocation amount must be positive.')]);
                    }

                    $invoice = $lockedInvoices[$invoiceId] ?? ArInvoice::whereKey($invoiceId)->lockForUpdate()->first();
                    if (! $invoice) {
                        throw ValidationException::withMessages(['allocations' => __('Invoice not found.')]);
                    }
                    $this->allocationIntegrity->assertSameCompanyForPaymentAndInvoice($payment, $invoice);

                    $this->invoices->recalc($invoice);
                    $invoice = $invoice->fresh();
                    $outstanding = (int) $invoice->balance_cents;
                    if ($outstanding <= 0) {
                        continue;
                    }

                    if ($invoice->currency && $invoice->currency !== $currency) {
                        throw ValidationException::withMessages(['allocations' => __('Payment currency must match invoice currency.')]);
                    }
                    if ((int) $invoice->branch_id !== (int) $payment->branch_id) {
                        throw ValidationException::withMessages(['allocations' => __('Payment branch must match invoice branch.')]);
                    }

                    $apply = min($requested, $outstanding, $remaining);
                    if ($apply <= 0) {
                        continue;
                    }

                    PaymentAllocation::create([
                        'payment_id' => $payment->id,
                        'allocatable_type' => ArInvoice::class,
                        'allocatable_id' => $invoice->id,
                        'amount_cents' => $apply,
                    ]);

                    $this->invoices->recalc($invoice);
                    $this->allocations->recalcStatus($invoice->fresh());

                    $remaining -= $apply;
                }

                $applied = (int) PaymentAllocation::query()
                    ->where('payment_id', $payment->id)
                    ->whereNull('voided_at')
                    ->sum('amount_cents');

                $this->subledgerService->recordArPaymentReceived(
                    $payment->fresh(),
                    $applied,
                    $amount - $applied,
                    $actorId,
                    $resolvedSettlementAccountId,
                );

                if ($recordBankTransaction) {
                    $this->bankTransactionService->recordArPayment($payment->fresh(), $actorId);
                }
                $this->auditLog->log('ar_payment.created', $actorId, $payment, [
                    'applied_cents' => (int) $applied,
                    'unapplied_cents' => (int) ($amount - $applied),
                    'reference' => $reference,
                ]);

                return $payment->fresh(['allocations']);
            });
        } catch (QueryException $e) {
            if ($clientUuid !== '') {
                $existing = Payment::query()->with(['allocations'])->where('client_uuid', $clientUuid)->first();
                if ($existing) {
                    $this->assertReplayMatchesAllocatedPayment($existing, [
                        'customer_id' => $customer->id,
                        'branch_id' => $branchId,
                        'amount_cents' => $amount,
                        'method' => $method,
                        'currency' => $currency,
                        'allocations' => $rows,
                    ]);

                    return $existing->fresh(['allocations']);
                }
            }

            throw $e;
        }
    }

    public function applyExistingPaymentToInvoice(
        int $paymentId,
        int $invoiceId,
        int $amountCents,
        int $actorId,
        ?string $paymentClientUuid = null
    ): PaymentAllocation {
        throw ValidationException::withMessages([
            'allocations' => __('Saved customer credit can only be allocated by an administrator from the RMS payment detail.'),
        ]);
    }

    public function applyExistingPaymentAllocations(
        int $paymentId,
        array $rows,
        int $actorId,
        ?string $paymentClientUuid = null,
        ?string $operationUuid = null,
    ): Payment {
        $actor = User::query()->find($actorId);
        if (! $actor || ! $operationUuid) {
            throw ValidationException::withMessages([
                'allocations' => __('Saved credit allocation requires an authenticated administrator action identifier.'),
            ]);
        }
        if ($paymentClientUuid) {
            $payment = Payment::query()->findOrFail($paymentId);
            if ((string) $payment->client_uuid !== $paymentClientUuid) {
                throw ValidationException::withMessages(['payment' => __('Payment identifier mismatch.')]);
            }
        }

        return app(SavedCreditAllocationService::class)
            ->allocate($paymentId, $rows, $actor, $operationUuid)['payment'];
    }

    private function resolveBankAccountId(string $paymentMethod, ?int $companyId, mixed $bankAccountId): ?int
    {
        if ($paymentMethod !== 'bank_transfer') {
            return null;
        }

        $bankAccount = $this->mappingService->resolveBankAccount((int) ($bankAccountId ?? 0), $companyId);
        if (! $bankAccount) {
            throw ValidationException::withMessages([
                'bank_account_id' => __('A bank account is required for bank transfers.'),
            ]);
        }

        if ((int) $bankAccount->company_id !== (int) $companyId) {
            throw ValidationException::withMessages([
                'bank_account_id' => __('The selected bank account does not belong to the payment company.'),
            ]);
        }

        if (! $bankAccount->ledger_account_id) {
            throw ValidationException::withMessages([
                'bank_account_id' => __('The selected bank account must be linked to a ledger account.'),
            ]);
        }

        return (int) $bankAccount->id;
    }

    private function resolvePaymentSourceId(
        mixed $paymentSourceId,
        mixed $settlementAccountId,
        ?int $companyId,
        string $method,
    ): ?int {
        $id = (int) ($paymentSourceId ?? 0);
        if ($id <= 0) {
            return null;
        }

        $source = PaymentSource::query()->lockForUpdate()->find($id);
        if (! $source
            || (int) $source->company_id !== (int) $companyId
            || (string) $source->method !== $method
            || (int) $source->clearing_account_id !== (int) ($settlementAccountId ?? 0)) {
            throw ValidationException::withMessages([
                'payment_source_id' => __('The payment source does not match the payment context.'),
            ]);
        }

        return (int) $source->id;
    }

    private function resolveSettlementAccountId(
        mixed $settlementAccountId,
        ?int $companyId,
        ?int $paymentSourceId,
    ): ?int {
        if ($paymentSourceId === null) {
            return null;
        }

        $account = LedgerAccount::query()->lockForUpdate()->find((int) $settlementAccountId);
        if (! $account || ! $account->is_active || (int) $account->company_id !== (int) $companyId) {
            throw ValidationException::withMessages([
                'settlement_account_id' => __('The payment settlement account is unavailable.'),
            ]);
        }

        return (int) $account->id;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function assertReplayMatchesAdvance(Payment $payment, array $payload): void
    {
        if (
            (int) $payment->customer_id !== (int) $payload['customer_id']
            || (int) $payment->branch_id !== (int) $payload['branch_id']
            || (int) $payment->amount_cents !== (int) $payload['amount_cents']
            || (string) $payment->method !== $this->mappingService->normalizePaymentMethod((string) $payload['method'])
            || (string) $payment->currency !== (string) $payload['currency']
        ) {
            throw ValidationException::withMessages([
                'client_uuid' => __('The supplied payment idempotency key belongs to a different AR payment request.'),
            ]);
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function assertReplayMatchesAllocatedPayment(Payment $payment, array $payload): void
    {
        $this->assertReplayMatchesAdvance($payment, $payload);

        $requested = collect((array) ($payload['allocations'] ?? []))
            ->map(fn ($row) => [
                'invoice_id' => (int) ($row['invoice_id'] ?? 0),
                'amount_cents' => (int) ($row['amount_cents'] ?? 0),
            ])
            ->filter(fn (array $row) => $row['invoice_id'] > 0 && $row['amount_cents'] > 0)
            ->sortBy(['invoice_id', 'amount_cents'])
            ->values();

        $existing = $payment->allAllocations()
            ->whereNull('voided_at')
            ->get()
            ->map(fn (PaymentAllocation $allocation) => [
                'invoice_id' => (int) $allocation->allocatable_id,
                'amount_cents' => (int) $allocation->amount_cents,
            ])
            ->sortBy(['invoice_id', 'amount_cents'])
            ->values();

        if ($this->serializeAllocationSet($requested) !== $this->serializeAllocationSet($existing)) {
            throw ValidationException::withMessages([
                'client_uuid' => __('The supplied payment idempotency key belongs to a different AR allocation request.'),
            ]);
        }
    }

    private function serializeAllocationSet(Collection $allocations): string
    {
        return json_encode($allocations->values()->all(), JSON_THROW_ON_ERROR);
    }
}
