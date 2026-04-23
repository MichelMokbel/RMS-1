<?php

namespace App\Services\AP;

use App\Models\ApInvoice;
use App\Models\ApPayment;
use App\Models\ApPaymentAllocation;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\Accounting\AccountingContextService;
use App\Services\Accounting\AccountingPeriodGateService;
use App\Services\Accounting\LedgerAccountMappingService;
use App\Services\Banking\BankTransactionService;
use App\Services\Ledger\SubledgerService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApAllocationService
{
    public function __construct(
        protected ApInvoiceStatusService $statusService,
        protected SupplierAccountingPolicyService $supplierPolicy,
        protected SubledgerService $subledgerService,
        protected AccountingContextService $accountingContext,
        protected LedgerAccountMappingService $mappingService,
        protected AccountingPeriodGateService $periodGate,
        protected AccountingAuditLogService $auditLog,
        protected BankTransactionService $bankTransactionService
    ) {}

    public function createPaymentWithAllocations(array $payload, int $userId): ApPayment
    {
        $clientUuid = trim((string) ($payload['client_uuid'] ?? ''));
        if ($clientUuid !== '') {
            $existing = ApPayment::query()
                ->with(['allocations.invoice'])
                ->where('client_uuid', $clientUuid)
                ->first();

            if ($existing) {
                $this->assertReplayMatches($existing, $payload);

                return $existing;
            }
        }

        try {
            return DB::transaction(function () use ($payload, $userId, $clientUuid) {
                $this->validateAllocations($payload['allocations'] ?? [], $payload['supplier_id']);
                $this->supplierPolicy->assertCanPay(
                    \App\Models\Supplier::query()->find((int) $payload['supplier_id']),
                    'supplier_id'
                );
                $companyId = $this->accountingContext->resolveCompanyId($payload['branch_id'] ?? null, $payload['company_id'] ?? null);
                $periodId = $this->accountingContext->resolvePeriodId($payload['payment_date'] ?? null, $companyId);
                $paymentMethod = $this->mappingService->normalizePaymentMethod((string) ($payload['payment_method'] ?? 'bank_transfer'));
                $bankAccountId = $this->resolveBankAccountId($paymentMethod, $companyId, $payload['bank_account_id'] ?? null);
                $this->periodGate->assertDateOpen((string) $payload['payment_date'], $companyId, $periodId, 'ap', 'payment_date');

                $payment = ApPayment::create([
                    'supplier_id' => $payload['supplier_id'],
                    'client_uuid' => $clientUuid !== '' ? $clientUuid : null,
                    'company_id' => $companyId,
                    'bank_account_id' => $bankAccountId,
                    'branch_id' => $payload['branch_id'] ?? null,
                    'department_id' => $payload['department_id'] ?? null,
                    'job_id' => $payload['job_id'] ?? null,
                    'period_id' => $periodId,
                    'payment_date' => $payload['payment_date'],
                    'amount' => $payload['amount'],
                    'payment_method' => $paymentMethod,
                    'currency_code' => $payload['currency_code'] ?? config('pos.currency', 'QAR'),
                    'reference' => $payload['reference'] ?? null,
                    'notes' => $payload['notes'] ?? null,
                    'created_by' => $userId,
                    'posted_at' => now(),
                    'posted_by' => $userId,
                ]);

                $allocations = $payload['allocations'] ?? [];
                if (empty($allocations) && ! Config::get('ap.allow_unapplied_payments', true)) {
                    throw ValidationException::withMessages(['allocations' => __('Allocations required.')]);
                }

                $this->applyAllocations($payment, $allocations);
                $this->subledgerService->recordApPayment($payment, $userId);
                $this->bankTransactionService->recordApPayment($payment->fresh(), $userId);
                $this->auditLog->log('ap_payment.created', $userId, $payment, [
                    'supplier_id' => (int) $payment->supplier_id,
                    'amount' => (float) $payment->amount,
                ], (int) ($payment->company_id ?? 0) ?: null);

                return $payment->fresh(['allocations.invoice']);
            });
        } catch (QueryException $e) {
            if ($clientUuid !== '') {
                $existing = ApPayment::query()
                    ->with(['allocations.invoice'])
                    ->where('client_uuid', $clientUuid)
                    ->first();

                if ($existing) {
                    $this->assertReplayMatches($existing, $payload);

                    return $existing;
                }
            }

            throw $e;
        }
    }

    public function allocateExistingPayment(ApPayment $payment, array $allocations, int $userId): ApPayment
    {
        return DB::transaction(function () use ($payment, $allocations, $userId) {
            $requestedByInvoice = collect($allocations)
                ->mapWithKeys(fn (array $row) => [(int) ($row['invoice_id'] ?? 0) => round((float) ($row['allocated_amount'] ?? 0), 2)]);

            $existingActive = ApPaymentAllocation::query()
                ->where('payment_id', $payment->id)
                ->whereNull('voided_at')
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (ApPaymentAllocation $allocation) => (int) $allocation->invoice_id);

            $isExactReplay = count($allocations) > 0
                && $existingActive->count() === count($allocations)
                && $existingActive->every(fn (ApPaymentAllocation $allocation, int|string $invoiceId) => round((float) $allocation->allocated_amount, 2) === ($requestedByInvoice[(int) $invoiceId] ?? INF));

            if ($isExactReplay) {
                return $payment->fresh(['allocations.invoice']);
            }

            $this->validateAllocations($allocations, $payment->supplier_id, $payment);
            $newAllocations = $this->applyAllocations($payment, $allocations);
            foreach ($newAllocations as $allocation) {
                $this->subledgerService->recordApPaymentAllocation($allocation, $userId);
            }
            $this->auditLog->log('ap_payment.allocated', $userId, $payment, [
                'allocation_count' => count($newAllocations),
            ], (int) ($payment->company_id ?? 0) ?: null);

            return $payment->fresh(['allocations.invoice']);
        });
    }

    private function validateAllocations(array $allocations, int $supplierId, ?ApPayment $payment = null): void
    {
        $totalAlloc = 0;
        foreach ($allocations as $row) {
            $invoice = ApInvoice::where('id', $row['invoice_id'] ?? 0)->lockForUpdate()->first();
            if (! $invoice) {
                throw ValidationException::withMessages(['allocations' => __('Invoice not found.')]);
            }
            if ($invoice->supplier_id !== $supplierId) {
                throw ValidationException::withMessages(['allocations' => __('Allocations must match supplier.')]);
            }
            if (! in_array($invoice->status, ['posted', 'partially_paid'], true)) {
                throw ValidationException::withMessages(['allocations' => __('Invoice must be posted or partially paid.')]);
            }
            $amount = (float) ($row['allocated_amount'] ?? 0);
            if ($amount <= 0) {
                throw ValidationException::withMessages(['allocations' => __('Allocation amount must be positive.')]);
            }
            $outstanding = (float) $invoice->total_amount - (float) $invoice->allocations()->lockForUpdate()->get()->sum('allocated_amount');
            if ($amount > $outstanding) {
                throw ValidationException::withMessages(['allocations' => __('Allocation exceeds outstanding.')]);
            }
            $totalAlloc += $amount;
        }

        if ($payment) {
            if ($totalAlloc > $payment->unallocatedAmount()) {
                throw ValidationException::withMessages(['allocations' => __('Allocations exceed remaining payment amount.')]);
            }
        }
    }

    private function applyAllocations(ApPayment $payment, array $allocations): array
    {
        $created = [];
        $sum = 0;
        foreach ($allocations as $row) {
            $invoice = ApInvoice::where('id', $row['invoice_id'])->lockForUpdate()->firstOrFail();
            $allocAmount = (float) $row['allocated_amount'];
            $sum += $allocAmount;

            $created[] = $this->createApPaymentAllocation($payment->id, $invoice->id, $allocAmount);

            $this->statusService->recalcStatus($invoice);
        }

        if ($sum > (float) $payment->amount) {
            throw ValidationException::withMessages(['allocations' => __('Total allocations exceed payment amount.')]);
        }

        return $created;
    }

    /**
     * Create an ApPaymentAllocation with full race-safety:
     *   1. Pre-create check: skip if an active (non-voided) allocation already exists.
     *   2. DB unique constraint (alloc_active_sentinel) catches concurrent inserts.
     *   3. Catch-block: on MySQL error 1062 (duplicate key), return the existing row.
     */
    private function createApPaymentAllocation(int $paymentId, int $invoiceId, float $allocatedAmount): ApPaymentAllocation
    {
        // (1) Pre-create existence check (inside the surrounding transaction's lock scope).
        $existing = ApPaymentAllocation::query()
            ->where('payment_id', $paymentId)
            ->where('invoice_id', $invoiceId)
            ->whereNull('voided_at')
            ->lockForUpdate()
            ->first();

        if ($existing) {
            return $existing;
        }

        try {
            // (2) Insert — the generated sentinel column + unique index prevents
            //     concurrent duplicates at the DB level.
            return ApPaymentAllocation::create([
                'payment_id' => $paymentId,
                'invoice_id' => $invoiceId,
                'allocated_amount' => $allocatedAmount,
            ]);
        } catch (QueryException $e) {
            // (3) Catch-block: handle duplicate key violation (MySQL 1062).
            if ($e->errorInfo[1] !== 1062) {
                throw $e;
            }

            $found = ApPaymentAllocation::query()
                ->where('payment_id', $paymentId)
                ->where('invoice_id', $invoiceId)
                ->whereNull('voided_at')
                ->first();

            if ($found) {
                return $found;
            }

            throw $e;
        }
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

    /**
     * @param  array<string,mixed>  $payload
     */
    private function assertReplayMatches(ApPayment $payment, array $payload): void
    {
        $requestedAllocations = collect((array) ($payload['allocations'] ?? []))
            ->map(fn (array $row) => [
                'invoice_id' => (int) ($row['invoice_id'] ?? 0),
                'allocated_amount' => round((float) ($row['allocated_amount'] ?? 0), 2),
            ])
            ->filter(fn (array $row) => $row['invoice_id'] > 0 && $row['allocated_amount'] > 0)
            ->sortBy(['invoice_id', 'allocated_amount'])
            ->values()
            ->all();

        $existingAllocations = $payment->allAllocations()
            ->whereNull('voided_at')
            ->get()
            ->map(fn (ApPaymentAllocation $allocation) => [
                'invoice_id' => (int) $allocation->invoice_id,
                'allocated_amount' => round((float) $allocation->allocated_amount, 2),
            ])
            ->sortBy(['invoice_id', 'allocated_amount'])
            ->values()
            ->all();

        $matches = (int) $payment->supplier_id === (int) ($payload['supplier_id'] ?? 0)
            && round((float) $payment->amount, 2) === round((float) ($payload['amount'] ?? 0), 2)
            && strtolower((string) $payment->payment_method) === $this->mappingService->normalizePaymentMethod((string) ($payload['payment_method'] ?? 'bank_transfer'))
            && optional($payment->payment_date)->toDateString() === (string) ($payload['payment_date'] ?? '')
            && (string) ($payment->currency_code ?? config('pos.currency', 'QAR')) === (string) ($payload['currency_code'] ?? ($payment->currency_code ?? config('pos.currency', 'QAR')))
            && json_encode($existingAllocations, JSON_THROW_ON_ERROR) === json_encode($requestedAllocations, JSON_THROW_ON_ERROR);

        if (! $matches) {
            throw ValidationException::withMessages([
                'client_uuid' => __('The supplied payment idempotency key belongs to a different AP payment request.'),
            ]);
        }
    }
}
