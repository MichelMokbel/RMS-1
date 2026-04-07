<?php

namespace App\Services\AR;

use App\Models\AccountingCompany;
use App\Models\ArInvoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\SubledgerEntry;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\Accounting\AccountingContextService;
use App\Services\Ledger\SubledgerService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ArAllocationIntegrityService
{
    public function __construct(
        protected AccountingContextService $context,
        protected AccountingAuditLogService $auditLog,
        protected SubledgerService $subledgerService,
    ) {
    }

    public function resolveInvoiceCompanyId(ArInvoice $invoice): ?int
    {
        return $this->context->resolveCompanyId((int) ($invoice->branch_id ?? 0), (int) ($invoice->company_id ?? 0));
    }

    public function resolvePaymentCompanyId(Payment $payment): ?int
    {
        return $this->context->resolveCompanyId((int) ($payment->branch_id ?? 0), (int) ($payment->company_id ?? 0));
    }

    public function assertSameCompanyForPaymentAndInvoice(Payment $payment, ArInvoice $invoice): array
    {
        $paymentCompanyId = $this->resolvePaymentCompanyId($payment);
        $invoiceCompanyId = $this->resolveInvoiceCompanyId($invoice);

        if (! $paymentCompanyId || ! $invoiceCompanyId) {
            throw ValidationException::withMessages([
                'company_id' => __('Payment or invoice company could not be resolved safely for AR allocation.'),
            ]);
        }

        if ($paymentCompanyId !== $invoiceCompanyId) {
            throw ValidationException::withMessages([
                'company_id' => __('Payment and invoice must belong to the same accounting company.'),
            ]);
        }

        return [
            'payment_company_id' => $paymentCompanyId,
            'invoice_company_id' => $invoiceCompanyId,
        ];
    }

    public function mismatchedAllocations(?int $companyId = null): Collection
    {
        return PaymentAllocation::query()
            ->with(['payment.customer', 'allocatable'])
            ->where('allocatable_type', ArInvoice::class)
            ->whereNull('voided_at')
            ->whereHas('payment', fn ($query) => $query->where('source', 'ar')->whereNull('voided_at'))
            ->get()
            ->map(function (PaymentAllocation $allocation) use ($companyId) {
                /** @var ArInvoice|null $invoice */
                $invoice = $allocation->allocatable instanceof ArInvoice ? $allocation->allocatable : null;
                $payment = $allocation->payment;

                if (! $invoice || ! $payment) {
                    return null;
                }

                $paymentCompanyId = $this->resolvePaymentCompanyId($payment);
                $invoiceCompanyId = $this->resolveInvoiceCompanyId($invoice);
                $isMismatch = ! $paymentCompanyId || ! $invoiceCompanyId || $paymentCompanyId !== $invoiceCompanyId;

                if (! $isMismatch) {
                    return null;
                }

                if ($companyId && ! in_array($companyId, array_filter([$paymentCompanyId, $invoiceCompanyId]), true)) {
                    return null;
                }

                return [
                    'allocation' => $allocation,
                    'payment' => $payment,
                    'invoice' => $invoice,
                    'payment_company_id' => $paymentCompanyId,
                    'payment_company_name' => AccountingCompany::query()->whereKey($paymentCompanyId)->value('name'),
                    'invoice_company_id' => $invoiceCompanyId,
                    'invoice_company_name' => AccountingCompany::query()->whereKey($invoiceCompanyId)->value('name'),
                    'customer_name' => $payment->customer?->name,
                    'amount' => round(((int) $allocation->amount_cents) / 100, 2),
                ];
            })
            ->filter()
            ->values();
    }

    public function repairAllocation(PaymentAllocation $allocation, int $actorId, ?string $reason = null): PaymentAllocation
    {
        return DB::transaction(function () use ($allocation, $actorId, $reason) {
            $allocation = PaymentAllocation::query()
                ->with('payment.customer')
                ->whereKey($allocation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($allocation->voided_at) {
                return $allocation;
            }

            if ($allocation->allocatable_type !== ArInvoice::class) {
                throw ValidationException::withMessages([
                    'allocation' => __('Only AR invoice allocations can be repaired here.'),
                ]);
            }

            $payment = Payment::query()->whereKey($allocation->payment_id)->lockForUpdate()->firstOrFail();
            $invoice = ArInvoice::query()->whereKey($allocation->allocatable_id)->lockForUpdate()->firstOrFail();

            $paymentCompanyId = $this->resolvePaymentCompanyId($payment);
            $invoiceCompanyId = $this->resolveInvoiceCompanyId($invoice);

            if ($paymentCompanyId && $invoiceCompanyId && $paymentCompanyId === $invoiceCompanyId) {
                throw ValidationException::withMessages([
                    'allocation' => __('This allocation is not a cross-company mismatch.'),
                ]);
            }

            $entry = SubledgerEntry::query()
                ->where('source_type', 'ar_payment_allocation')
                ->where('source_id', $allocation->id)
                ->where('event', 'apply')
                ->first();

            if ($entry) {
                $this->subledgerService->recordReversalForEntry(
                    $entry,
                    'repair',
                    'AR cross-company allocation repair '.$allocation->id,
                    now()->toDateString(),
                    $actorId
                );
            }

            $allocation->voided_at = now();
            $allocation->voided_by = $actorId;
            $allocation->void_reason = $reason ?: __('Cross-company AR allocation repaired');
            $allocation->save();

            app(ArInvoiceService::class)->recalc($invoice);
            app(ArAllocationService::class)->recalcStatus($invoice->fresh());

            $this->auditLog->log('ar_allocation.repaired', $actorId, $allocation, [
                'payment_id' => (int) $payment->id,
                'invoice_id' => (int) $invoice->id,
                'payment_company_id' => $paymentCompanyId,
                'invoice_company_id' => $invoiceCompanyId,
                'amount_cents' => (int) $allocation->amount_cents,
                'reason' => $allocation->void_reason,
            ], $paymentCompanyId ?: $invoiceCompanyId);

            return $allocation->fresh(['payment']);
        });
    }
}
