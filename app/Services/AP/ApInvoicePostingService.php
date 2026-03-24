<?php

namespace App\Services\AP;

use App\Models\ApInvoice;
use App\Models\PurchaseOrder;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\Accounting\AccountingContextService;
use App\Services\Accounting\JobCostingService;
use App\Services\Accounting\AccountingPeriodGateService;
use App\Services\Inventory\LandedCostAllocationService;
use App\Services\Ledger\SubledgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApInvoicePostingService
{
    public function __construct(
        protected ApInvoiceTotalsService $totalsService,
        protected SupplierAccountingPolicyService $supplierPolicy,
        protected PurchaseOrderInvoiceMatchingService $matchingService,
        protected SubledgerService $subledgerService,
        protected AccountingContextService $accountingContext,
        protected AccountingPeriodGateService $periodGate,
        protected AccountingAuditLogService $auditLog,
        protected JobCostingService $jobCostingService,
        protected LandedCostAllocationService $landedCostAllocationService
    ) {
    }

    public function post(ApInvoice $invoice, int $userId, bool $matchingOverride = false, ?string $matchingOverrideReason = null): ApInvoice
    {
        return DB::transaction(function () use ($invoice, $userId, $matchingOverride, $matchingOverrideReason) {
            $invoice = ApInvoice::where('id', $invoice->id)->lockForUpdate()->firstOrFail();

            if (! $invoice->isDraft()) {
                throw ValidationException::withMessages(['status' => __('Only draft invoices can be posted.')]);
            }

            if (! $invoice->supplier_id) {
                throw ValidationException::withMessages(['supplier_id' => __('Supplier is required.')]);
            }

            $this->supplierPolicy->assertCanPost($invoice->supplier()->first(), 'supplier_id');

            if ($invoice->items()->count() === 0) {
                throw ValidationException::withMessages(['items' => __('Add at least one item.')]);
            }

            if ($invoice->purchase_order_id) {
                $po = PurchaseOrder::find($invoice->purchase_order_id);
                if (! $po || $po->supplier_id !== $invoice->supplier_id) {
                    throw ValidationException::withMessages(['purchase_order_id' => __('PO must exist and belong to the same supplier.')]);
                }
            }

            $this->totalsService->recalc($invoice);
            $invoice->company_id = $invoice->company_id ?: $this->accountingContext->resolveCompanyId((int) ($invoice->branch_id ?? 0));
            $invoice->period_id = $invoice->period_id ?: $this->accountingContext->resolvePeriodId(optional($invoice->invoice_date)->toDateString(), (int) $invoice->company_id);
            $this->periodGate->assertDateOpen(
                optional($invoice->invoice_date)->toDateString() ?? now()->toDateString(),
                (int) $invoice->company_id,
                $invoice->period_id ? (int) $invoice->period_id : null,
                'ap',
                'status'
            );
            $invoice->document_type = $invoice->document_type ?: ($invoice->is_expense ? 'expense' : 'vendor_bill');
            $invoice->currency_code = $invoice->currency_code ?: config('pos.currency', 'QAR');

            $invoice->status = 'posted';
            $invoice->posted_at = $invoice->posted_at ?? now();
            $invoice->posted_by = $invoice->posted_by ?? $userId;
            $invoice->save();

            $matching = $this->matchingService->assertPostable($invoice, $userId, $matchingOverride, $matchingOverrideReason);
            if ($invoice->document_type === 'landed_cost_adjustment') {
                $this->landedCostAllocationService->allocate($invoice, $userId);
            }
            $this->subledgerService->recordApInvoice($invoice, $userId);
            $this->recordJobCost($invoice, $userId);
            $this->auditLog->log('ap_invoice.posted', $userId, $invoice, [
                'document_type' => $invoice->document_type,
                'total_amount' => (float) $invoice->total_amount,
                'matching_status' => $matching['overall_status'] ?? null,
                'matching_override' => $matchingOverride,
            ], (int) ($invoice->company_id ?? 0) ?: null);

            return $invoice->fresh(['items']);
        });
    }

    private function recordJobCost(ApInvoice $invoice, int $userId): void
    {
        if (! $invoice->job_id) {
            return;
        }

        $job = $invoice->job()->first();
        if (! $job) {
            return;
        }

        $type = $invoice->is_expense || in_array($invoice->document_type, ['vendor_bill', 'recurring_bill', 'landed_cost_adjustment'], true)
            ? 'cost'
            : 'adjustment';

        $this->jobCostingService->recordSourceTransaction($job, [
            'transaction_date' => optional($invoice->invoice_date)->toDateString() ?? now()->toDateString(),
            'amount' => (float) $invoice->total_amount,
            'transaction_type' => $type,
            'job_phase_id' => $invoice->job_phase_id,
            'job_cost_code_id' => $invoice->job_cost_code_id,
            'memo' => __('AP invoice :invoice', ['invoice' => $invoice->invoice_number]),
        ], ApInvoice::class, (int) $invoice->id, $userId);
    }
}
