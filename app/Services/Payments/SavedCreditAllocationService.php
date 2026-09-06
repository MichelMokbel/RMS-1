<?php

namespace App\Services\Payments;

use App\Models\AccountingAuditLog;
use App\Models\ArInvoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\Accounting\AccountingContextService;
use App\Services\Accounting\AccountingPeriodGateService;
use App\Services\AR\ArAllocationIntegrityService;
use App\Services\AR\ArAllocationService;
use App\Services\AR\ArInvoiceService;
use App\Services\Ledger\SubledgerService;
use App\Services\Subscriptions\MembershipBookingFundingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SavedCreditAllocationService
{
    public function __construct(
        private readonly AccountingContextService $context,
        private readonly AccountingPeriodGateService $periodGate,
        private readonly AccountingAuditLogService $auditLog,
        private readonly ArAllocationIntegrityService $integrity,
        private readonly ArInvoiceService $invoices,
        private readonly ArAllocationService $allocations,
        private readonly SubledgerService $subledger,
        private readonly PaymentCreditProjectionService $projection,
        private readonly MembershipBookingFundingService $membershipBookingFunding,
    ) {}

    /**
     * @param  array<int, array{invoice_id:int, amount_cents:int}>  $rows
     * @return array{payment:Payment, operation_uuid:string, state:string, allocation_ids:array<int, int>, audit_id:int|null}
     */
    public function allocate(int $paymentId, array $rows, User $actor, string $operationUuid): array
    {
        $this->assertActor($actor);
        if (! Str::isUuid($operationUuid) || strtolower($operationUuid) !== $operationUuid) {
            throw ValidationException::withMessages(['operation_uuid' => __('A valid operation identifier is required.')]);
        }
        if (! Schema::hasTable('accounting_audit_logs')) {
            throw ValidationException::withMessages(['allocations' => __('Saved credit allocation is unavailable because audit storage is unavailable.')]);
        }

        $normalizedRows = $this->normalizeRows($rows);
        $fingerprint = hash('sha256', json_encode([
            'payment_id' => $paymentId,
            'rows' => $normalizedRows,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($paymentId, $normalizedRows, $actor, $operationUuid, $fingerprint): array {
            $this->membershipBookingFunding->lockQueueForPaymentMutation($paymentId);
            $payment = Payment::query()->whereKey($paymentId)->lockForUpdate()->firstOrFail();
            $companyId = $this->integrity->resolvePaymentCompanyId($payment);
            if (! $companyId || $companyId !== $this->context->defaultCompanyId()) {
                throw new AuthorizationException(__('This payment is outside the default company.'));
            }

            $accepted = AccountingAuditLog::query()
                ->where('subject_type', Payment::class)
                ->where('subject_id', $payment->id)
                ->where('action', 'payment.saved_credit_allocation.accepted')
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.operation_uuid')) = ?", [$operationUuid])
                ->latest('id')
                ->first();
            if ($accepted) {
                if (($accepted->payload['input_fingerprint'] ?? null) !== $fingerprint) {
                    throw ValidationException::withMessages(['operation_uuid' => __('This operation identifier was used for different allocation input.')]);
                }

                $completed = AccountingAuditLog::query()
                    ->where('subject_type', Payment::class)
                    ->where('subject_id', $payment->id)
                    ->where('action', 'payment.saved_credit_allocation.completed')
                    ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.operation_uuid')) = ?", [$operationUuid])
                    ->latest('id')
                    ->first();
                if (! $completed) {
                    throw ValidationException::withMessages(['allocations' => __('This saved credit action needs administrator review before retrying.')]);
                }

                return [
                    'payment' => $payment->fresh(['allocations']),
                    'operation_uuid' => $operationUuid,
                    'state' => 'completed',
                    'allocation_ids' => array_values(array_map('intval', (array) ($completed->payload['allocation_ids'] ?? []))),
                    'audit_id' => (int) $completed->id,
                ];
            }

            $this->assertPayment($payment);
            $credit = $this->projection->project($payment, true);
            if ($credit['state'] === 'unavailable') {
                throw ValidationException::withMessages(['allocations' => __('This payment balance is inconsistent and cannot be allocated.')]);
            }
            if ($credit['available_cents'] <= 0) {
                throw ValidationException::withMessages(['allocations' => __('This payment has no discretionary saved credit available.')]);
            }

            $requestedTotal = array_sum(array_column($normalizedRows, 'amount_cents'));
            if ($requestedTotal > $credit['available_cents']) {
                throw ValidationException::withMessages(['allocations' => __('Allocations exceed available saved credit.')]);
            }

            $allocationDate = now('Asia/Qatar')->toDateString();
            $this->periodGate->assertDateOpen($allocationDate, $companyId, null, 'ledger', 'allocations');
            $lockedInvoices = [];
            foreach ($normalizedRows as $row) {
                $invoice = ArInvoice::query()->whereKey($row['invoice_id'])->lockForUpdate()->firstOrFail();
                $this->integrity->assertSameCompanyForPaymentAndInvoice($payment, $invoice);
                $this->assertInvoice($payment, $invoice, $row['amount_cents']);
                if (PaymentAllocation::query()
                    ->where('payment_id', $payment->id)
                    ->where('allocatable_type', ArInvoice::class)
                    ->where('allocatable_id', $invoice->id)
                    ->whereNull('voided_at')
                    ->exists()) {
                    throw ValidationException::withMessages(['allocations' => __('This payment is already allocated to one of the selected invoices.')]);
                }
                $lockedInvoices[$invoice->id] = $invoice;
            }

            $this->auditLog->log('payment.saved_credit_allocation.accepted', (int) $actor->id, $payment, [
                'operation_uuid' => $operationUuid,
                'input_fingerprint' => $fingerprint,
                'allocation_date' => $allocationDate,
                'requested_total_cents' => $requestedTotal,
            ], $companyId);

            $allocationIds = [];
            foreach ($normalizedRows as $row) {
                $invoice = $lockedInvoices[$row['invoice_id']];
                $allocation = PaymentAllocation::query()->create([
                    'payment_id' => $payment->id,
                    'allocatable_type' => ArInvoice::class,
                    'allocatable_id' => $invoice->id,
                    'amount_cents' => $row['amount_cents'],
                ]);
                $allocationIds[] = (int) $allocation->id;
                $this->invoices->recalc($invoice);
                $this->allocations->recalcStatus($invoice->fresh());
                $this->subledger->recordArAdvanceApplied($allocation->fresh(['payment']), (int) $actor->id);
                $this->auditLog->log('ar_payment.allocated', (int) $actor->id, $allocation, [
                    'payment_id' => (int) $payment->id,
                    'invoice_id' => (int) $invoice->id,
                    'amount_cents' => (int) $row['amount_cents'],
                    'operation_uuid' => $operationUuid,
                    'allocation_date' => $allocationDate,
                ], $companyId);
            }

            $this->auditLog->log('payment.saved_credit_allocation.completed', (int) $actor->id, $payment, [
                'operation_uuid' => $operationUuid,
                'input_fingerprint' => $fingerprint,
                'allocation_date' => $allocationDate,
                'allocation_ids' => $allocationIds,
                'allocated_total_cents' => $requestedTotal,
            ], $companyId);
            $completedAuditId = AccountingAuditLog::query()
                ->where('subject_type', Payment::class)
                ->where('subject_id', $payment->id)
                ->where('action', 'payment.saved_credit_allocation.completed')
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.operation_uuid')) = ?", [$operationUuid])
                ->latest('id')
                ->value('id');

            return [
                'payment' => $payment->fresh(['allocations']),
                'operation_uuid' => $operationUuid,
                'state' => 'completed',
                'allocation_ids' => $allocationIds,
                'audit_id' => $completedAuditId ? (int) $completedAuditId : null,
            ];
        }, 3);
    }

    private function assertActor(User $actor): void
    {
        if (! $actor->isActive() || $actor->isCustomerPortalUser() || ! $actor->isAdmin()
            || ! $actor->can('payments.credit.allocate') || ! $actor->can('finance.write')) {
            throw new AuthorizationException(__('Only an authorized administrator can allocate saved customer credit.'));
        }
    }

    private function assertPayment(Payment $payment): void
    {
        if ($payment->source !== 'ar' || ! $payment->customer_id || $payment->voided_at) {
            throw ValidationException::withMessages(['payment' => __('Only an active customer AR payment can supply saved credit.')]);
        }
    }

    private function assertInvoice(Payment $payment, ArInvoice $invoice, int $amountCents): void
    {
        if ((int) $payment->customer_id !== (int) $invoice->customer_id) {
            throw ValidationException::withMessages(['allocations' => __('Payment customer must match invoice customer.')]);
        }
        if ((int) $payment->branch_id !== (int) $invoice->branch_id) {
            throw ValidationException::withMessages(['allocations' => __('Payment branch must match invoice branch.')]);
        }
        if ($payment->currency && $invoice->currency && $payment->currency !== $invoice->currency) {
            throw ValidationException::withMessages(['allocations' => __('Payment currency must match invoice currency.')]);
        }
        if (! in_array($invoice->status, ['issued', 'partially_paid'], true) || $invoice->voided_at) {
            throw ValidationException::withMessages(['allocations' => __('Invoice must be issued to accept saved credit.')]);
        }

        $this->invoices->recalc($invoice);
        if ($amountCents > (int) $invoice->fresh()->balance_cents) {
            throw ValidationException::withMessages(['allocations' => __('Allocation exceeds invoice balance.')]);
        }
    }

    /**
     * @param  array<int, array{invoice_id:int, amount_cents:int}>  $rows
     * @return array<int, array{invoice_id:int, amount_cents:int}>
     */
    private function normalizeRows(array $rows): array
    {
        if ($rows === []) {
            throw ValidationException::withMessages(['allocations' => __('Select at least one invoice.')]);
        }

        $normalized = [];
        $invoiceIds = [];
        foreach ($rows as $row) {
            $invoiceId = (int) ($row['invoice_id'] ?? 0);
            $amountCents = (int) ($row['amount_cents'] ?? 0);
            if ($invoiceId <= 0 || $amountCents <= 0) {
                throw ValidationException::withMessages(['allocations' => __('Every saved credit allocation needs a valid invoice and positive amount.')]);
            }
            if (isset($invoiceIds[$invoiceId])) {
                throw ValidationException::withMessages(['allocations' => __('The same invoice cannot be selected twice.')]);
            }
            $invoiceIds[$invoiceId] = true;
            $normalized[] = ['invoice_id' => $invoiceId, 'amount_cents' => $amountCents];
        }

        usort($normalized, fn (array $left, array $right): int => $left['invoice_id'] <=> $right['invoice_id']);

        return $normalized;
    }
}
