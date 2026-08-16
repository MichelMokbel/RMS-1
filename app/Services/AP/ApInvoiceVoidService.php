<?php

namespace App\Services\AP;

use App\Models\ApInvoice;
use App\Models\ApInvoiceItem;
use App\Models\ExpenseProfile;
use App\Models\PurchaseOrderInvoiceMatch;
use App\Models\SubledgerEntry;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\Accounting\AccountingContextService;
use App\Services\Accounting\AccountingPeriodGateService;
use App\Services\Accounting\JobCostingService;
use App\Services\Ledger\SubledgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ApInvoiceVoidService
{
    public function __construct(
        protected SubledgerService $subledgerService,
        protected AccountingAuditLogService $auditLog,
        protected AccountingContextService $accountingContext,
        protected AccountingPeriodGateService $periodGate,
        protected JobCostingService $jobCostingService,
        protected ApInvoiceAttachmentService $attachmentService,
    ) {}

    public function void(ApInvoice $invoice, int $userId, ?string $reason = null): ApInvoice
    {
        return DB::transaction(function () use ($invoice, $userId, $reason) {
            $invoice = ApInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            $this->voidLockedInvoice($invoice, $userId, $reason);

            return $invoice->fresh(['items', 'supplier', 'expenseProfile']);
        });
    }

    public function voidAndDuplicate(ApInvoice $invoice, int $userId, ?string $reason = null): ApInvoice
    {
        $copiedAttachmentPaths = [];

        try {
            return DB::transaction(function () use ($invoice, $userId, $reason, &$copiedAttachmentPaths) {
                $invoice = ApInvoice::query()
                    ->with(['items', 'expenseProfile', 'attachments'])
                    ->whereKey($invoice->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->assertRevisionAllowed($invoice);
                $this->attachmentService->assertRevisionSourcesAvailable($invoice);

                $rootId = $invoice->rootInvoiceId();
                $root = ApInvoice::query()->whereKey($rootId)->lockForUpdate()->firstOrFail();
                $nextRevision = (int) ApInvoice::query()
                    ->where('revision_root_id', $rootId)
                    ->max('revision_number') + 1;
                $nextRevision = max(1, $nextRevision);
                $revisionNumber = $this->revisionInvoiceNumber((string) $root->invoice_number, $nextRevision);

                if (ApInvoice::query()
                    ->where('supplier_id', $invoice->supplier_id)
                    ->where('invoice_number', $revisionNumber)
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'invoice_number' => __('Invoice revision number already exists.'),
                    ]);
                }

                $this->voidLockedInvoice($invoice, $userId, $reason);

                $duplicate = ApInvoice::query()->create([
                    'company_id' => $invoice->company_id,
                    'branch_id' => $invoice->branch_id,
                    'department_id' => $invoice->department_id,
                    'job_id' => $invoice->job_id,
                    'job_phase_id' => $invoice->job_phase_id,
                    'job_cost_code_id' => $invoice->job_cost_code_id,
                    // Resolve the replacement period from its editable date when it is
                    // eventually posted; never pin a correction to the source period.
                    'period_id' => null,
                    'supplier_id' => $invoice->supplier_id,
                    'purchase_order_id' => $invoice->purchase_order_id,
                    'category_id' => $invoice->category_id,
                    'is_expense' => $invoice->is_expense,
                    'document_type' => $invoice->document_type,
                    'currency_code' => $invoice->currency_code,
                    'source_document_type' => $invoice->source_document_type,
                    'source_document_id' => $invoice->source_document_id,
                    'recurring_template_id' => $invoice->recurring_template_id,
                    'invoice_number' => $revisionNumber,
                    'reference_number' => $invoice->reference_number,
                    'invoice_date' => $invoice->invoice_date?->toDateString(),
                    'due_date' => $invoice->due_date?->toDateString(),
                    'subtotal' => $invoice->subtotal,
                    'tax_amount' => $invoice->tax_amount,
                    'total_amount' => $invoice->total_amount,
                    'status' => 'draft',
                    'notes' => $invoice->notes,
                    'created_by' => $userId,
                    'revision_root_id' => $rootId,
                    'revision_source_id' => $invoice->id,
                    'revision_number' => $nextRevision,
                ]);

                foreach ($invoice->items as $item) {
                    ApInvoiceItem::query()->create([
                        'invoice_id' => $duplicate->id,
                        'purchase_order_item_id' => $item->purchase_order_item_id,
                        'description' => $item->description,
                        'quantity' => $item->quantity,
                        'unit_price' => $item->unit_price,
                        'line_total' => $item->line_total,
                    ]);
                }

                if ($invoice->expenseProfile) {
                    ExpenseProfile::query()->create([
                        'invoice_id' => $duplicate->id,
                        'channel' => $invoice->expenseProfile->channel,
                        'wallet_id' => $invoice->expenseProfile->wallet_id,
                        'approval_status' => 'draft',
                        'requires_finance_approval' => false,
                    ]);
                }

                $copiedAttachments = $this->attachmentService->copyToRevision($invoice, $duplicate, $userId);
                $copiedAttachmentPaths = $copiedAttachments->pluck('file_path')->all();

                $this->auditLog->log('ap_invoice.voided_and_duplicated', $userId, $invoice, [
                    'reason' => $invoice->void_reason,
                    'revision_invoice_id' => (int) $duplicate->id,
                    'revision_root_id' => $rootId,
                    'revision_source_id' => (int) $invoice->id,
                    'revision_number' => $nextRevision,
                    'revision_invoice_number' => $revisionNumber,
                ], (int) ($invoice->company_id ?? 0) ?: null);

                $this->auditLog->log('ap_invoice.revision_created', $userId, $duplicate, [
                    'voided_invoice_id' => (int) $invoice->id,
                    'revision_root_id' => $rootId,
                    'revision_source_id' => (int) $invoice->id,
                    'revision_number' => $nextRevision,
                    'copied_attachment_count' => $copiedAttachments->count(),
                ], (int) ($invoice->company_id ?? 0) ?: null);

                return $duplicate->fresh(['items', 'supplier', 'expenseProfile', 'attachments']);
            });
        } catch (Throwable $exception) {
            $this->attachmentService->cleanupCopiedFiles($copiedAttachmentPaths);

            throw $exception;
        }
    }

    private function voidLockedInvoice(ApInvoice $invoice, int $userId, ?string $reason): void
    {
        $this->assertVoidAllowed($invoice);

        $wasPosted = $invoice->isPosted();
        $correctionDate = now()->toDateString();
        if ($wasPosted) {
            $companyId = $this->accountingContext->resolveCompanyId(
                $invoice->branch_id ? (int) $invoice->branch_id : null,
                $invoice->company_id ? (int) $invoice->company_id : null,
            );
            $this->periodGate->assertDateOpen($correctionDate, $companyId, null, 'ap', 'status');
            $this->reverseOriginalPosting($invoice, $userId, $correctionDate);
        }

        $this->releasePurchaseOrderMatches($invoice, $userId);
        $this->jobCostingService->reverseSourceTransactions(
            ApInvoice::class,
            (int) $invoice->id,
            $userId,
            $correctionDate,
        );

        $invoice->status = 'void';
        $invoice->void_reason = filled($reason) ? trim((string) $reason) : null;
        $invoice->voided_at = $invoice->voided_at ?? now();
        $invoice->voided_by = $invoice->voided_by ?? $userId;
        $invoice->save();

        $this->auditLog->log('ap_invoice.voided', $userId, $invoice, [
            'was_posted' => $wasPosted,
            'reason' => $invoice->void_reason,
        ], (int) ($invoice->company_id ?? 0) ?: null);
    }

    private function assertVoidAllowed(ApInvoice $invoice): void
    {
        if (! in_array($invoice->status, ['draft', 'posted'], true)) {
            throw ValidationException::withMessages(['status' => __('Only draft or posted invoices can be voided.')]);
        }

        if ($invoice->allocations()->exists()) {
            throw ValidationException::withMessages(['status' => __('Cannot void an invoice with allocations.')]);
        }

        if ($invoice->isPosted() && $invoice->document_type === 'landed_cost_adjustment') {
            throw ValidationException::withMessages([
                'document_type' => __('Posted landed cost adjustments cannot be voided until their inventory allocation is reversed.'),
            ]);
        }
    }

    private function assertRevisionAllowed(ApInvoice $invoice): void
    {
        if ($invoice->document_type === 'landed_cost_adjustment') {
            throw ValidationException::withMessages([
                'document_type' => __('Landed cost adjustments cannot be revised until their inventory allocation is reversed.'),
            ]);
        }

        $this->assertVoidAllowed($invoice);
    }

    private function reverseOriginalPosting(ApInvoice $invoice, int $userId, string $correctionDate): void
    {
        $entry = SubledgerEntry::query()
            ->where('source_type', 'ap_invoice')
            ->where('source_id', $invoice->id)
            ->where('event', 'post')
            ->first();

        if (! $entry) {
            if ((float) $invoice->total_amount <= 0) {
                return;
            }

            throw ValidationException::withMessages([
                'ledger' => __('The posted invoice has no source ledger entry to reverse.'),
            ]);
        }

        $this->subledgerService->recordReversalForEntry(
            $entry,
            'void',
            'AP Invoice void '.$invoice->invoice_number,
            $correctionDate,
            $userId,
        );
    }

    private function releasePurchaseOrderMatches(ApInvoice $invoice, int $userId): void
    {
        $matches = PurchaseOrderInvoiceMatch::query()
            ->where('ap_invoice_id', $invoice->id)
            ->lockForUpdate()
            ->get();

        if ($matches->isEmpty()) {
            return;
        }

        $payload = [
            'purchase_order_id' => $invoice->purchase_order_id,
            'match_ids' => $matches->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'matched_quantity' => round((float) $matches->sum('matched_quantity'), 3),
            'matched_amount' => round((float) $matches->sum('matched_amount'), 2),
        ];

        PurchaseOrderInvoiceMatch::query()
            ->whereIn('id', $matches->pluck('id'))
            ->delete();

        $this->auditLog->log('ap_invoice.po_matches_released', $userId, $invoice, $payload, (int) ($invoice->company_id ?? 0) ?: null);
    }

    private function revisionInvoiceNumber(string $baseNumber, int $revision): string
    {
        $candidate = trim($baseNumber).'V'.$revision;
        if (trim($baseNumber) === '' || strlen($candidate) > 100) {
            throw ValidationException::withMessages([
                'invoice_number' => __('Invoice revision number is invalid or too long.'),
            ]);
        }

        return $candidate;
    }
}
