<?php

namespace App\Services\Spend;

use App\Models\ApInvoice;
use App\Models\ExpenseProfile;
use App\Services\AP\ApInvoicePostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpenseWorkflowService
{
    public function __construct(
        protected ExpenseApprovalPolicyService $policyService,
        protected ExpenseEventService $eventService,
        protected ApInvoicePostingService $postingService,
        protected ExpenseSettlementService $settlementService
    ) {
    }

    public function initializeProfile(ApInvoice $invoice, string $channel, ?int $walletId = null): ExpenseProfile
    {
        return ExpenseProfile::updateOrCreate(
            ['invoice_id' => $invoice->id],
            [
                'channel' => $channel,
                'wallet_id' => $walletId,
                'approval_status' => 'draft',
            ]
        );
    }

    public function submit(ApInvoice $invoice, int $actorId): ApInvoice
    {
        DB::transaction(function () use ($invoice, $actorId) {
            $lockedInvoice = ApInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $profile = $this->findOrCreateDraftProfile($lockedInvoice);

            if ($profile->approval_status !== 'draft') {
                throw ValidationException::withMessages(['approval_status' => __('Only draft expenses can be submitted.')]);
            }

            $flags = $this->policyService->exceptionFlags($lockedInvoice, $profile);
            $profile->approval_status = 'submitted';
            $profile->submitted_by = $actorId;
            $profile->submitted_at = now();
            $profile->exception_flags = $flags;
            $profile->requires_finance_approval = $this->policyService->requiresFinanceApproval($flags);
            $profile->save();

            $this->eventService->log($lockedInvoice, 'submitted', $actorId, [
                'exception_flags' => $flags,
                'requires_finance_approval' => (bool) $profile->requires_finance_approval,
            ]);
        });

        return $invoice->fresh(['expenseProfile']);
    }

    public function approve(ApInvoice $invoice, int $actorId, string $stage): ApInvoice
    {
        DB::transaction(function () use ($invoice, $actorId, $stage) {
            $lockedInvoice = ApInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $profile = $this->findOrCreateDraftProfile($lockedInvoice);

            if ((int) ($profile->submitted_by ?? 0) === $actorId) {
                throw ValidationException::withMessages(['approver' => __('Submitter cannot approve own expense.')]);
            }

            if ($stage === 'manager') {
                if ($profile->approval_status !== 'submitted') {
                    throw ValidationException::withMessages(['approval_status' => __('Only submitted expenses can be manager-approved.')]);
                }

                $profile->manager_approved_by = $actorId;
                $profile->manager_approved_at = now();

                if ($profile->requires_finance_approval) {
                    $profile->approval_status = 'manager_approved';
                } else {
                    $profile->approval_status = 'approved';
                    $profile->finance_approved_by = null;
                    $profile->finance_approved_at = null;
                }
                $profile->save();

                $this->eventService->log($lockedInvoice, 'manager_approved', $actorId, [
                    'approval_status' => $profile->approval_status,
                ]);

                return;
            }

            if ($stage === 'finance') {
                if ($profile->approval_status !== 'manager_approved') {
                    throw ValidationException::withMessages(['approval_status' => __('Only manager-approved expenses can be finance-approved.')]);
                }

                $profile->finance_approved_by = $actorId;
                $profile->finance_approved_at = now();
                $profile->approval_status = 'approved';
                $profile->save();

                $this->eventService->log($lockedInvoice, 'finance_approved', $actorId, [
                    'approval_status' => $profile->approval_status,
                ]);

                return;
            }

            throw ValidationException::withMessages(['stage' => __('Approval stage must be manager or finance.')]);
        });

        return $invoice->fresh(['expenseProfile']);
    }

    public function reject(ApInvoice $invoice, int $actorId, string $reason): ApInvoice
    {
        DB::transaction(function () use ($invoice, $actorId, $reason) {
            $lockedInvoice = ApInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $profile = $this->findOrCreateDraftProfile($lockedInvoice);

            if ((int) ($profile->submitted_by ?? 0) === $actorId) {
                throw ValidationException::withMessages(['approver' => __('Submitter cannot reject own expense.')]);
            }

            if (! in_array($profile->approval_status, ['submitted', 'manager_approved'], true)) {
                throw ValidationException::withMessages(['approval_status' => __('Only submitted or manager-approved expenses can be rejected.')]);
            }

            $profile->approval_status = 'rejected';
            $profile->rejected_by = $actorId;
            $profile->rejected_at = now();
            $profile->rejection_reason = $reason;
            $profile->save();

            $this->eventService->log($lockedInvoice, 'rejected', $actorId, [
                'reason' => $reason,
            ]);
        });

        return $invoice->fresh(['expenseProfile']);
    }

    public function post(ApInvoice $invoice, int $actorId): ApInvoice
    {
        $result = DB::transaction(function () use ($invoice, $actorId) {
            $lockedInvoice = ApInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $profile = $this->findOrCreateDraftProfile($lockedInvoice);

            if ($profile->approval_status !== 'approved') {
                throw ValidationException::withMessages(['approval_status' => __('Only approved expenses can be posted.')]);
            }

            if (! $lockedInvoice->isDraft()) {
                if (! in_array($lockedInvoice->status, ['posted', 'partially_paid', 'paid'], true)) {
                    throw ValidationException::withMessages(['status' => __('Only draft or posted expense invoices are allowed.')]);
                }

                return $lockedInvoice;
            }

            return $this->postingService->post($lockedInvoice, $actorId);
        });

        $this->eventService->log($result, 'posted', $actorId, ['status' => $result->status]);

        return $result->fresh(['expenseProfile']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function settle(ApInvoice $invoice, int $actorId, array $payload = []): ApInvoice
    {
        $result = DB::transaction(function () use ($invoice, $actorId, $payload) {
            $lockedInvoice = ApInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $profile = $this->findOrCreateDraftProfile($lockedInvoice);

            $settlement = $this->settlementService->settle($lockedInvoice, $profile, $actorId, $payload);

            $this->eventService->log($lockedInvoice, 'settled', $actorId, [
                'payment_id' => $settlement['payment_id'],
                'settlement_mode' => $settlement['settlement_mode'],
            ]);

            return $lockedInvoice;
        });

        return $result->fresh(['expenseProfile']);
    }

    public function workflowState(ApInvoice $invoice): string
    {
        $profile = $invoice->expenseProfile;
        if (! $profile) {
            return 'draft';
        }

        if ($profile->approval_status === 'rejected') {
            return 'rejected';
        }

        if ($profile->approval_status === 'draft') {
            return 'draft';
        }

        if (in_array($profile->approval_status, ['submitted', 'manager_approved'], true)) {
            return $profile->approval_status;
        }

        if ($invoice->status === 'draft') {
            return 'approved_pending_post';
        }

        if (! $profile->settled_at) {
            return 'posted_pending_settlement';
        }

        if ($invoice->status === 'paid') {
            return 'closed';
        }

        return 'settled';
    }

    private function findOrCreateDraftProfile(ApInvoice $invoice): ExpenseProfile
    {
        $profile = ExpenseProfile::query()->find($invoice->id);

        if ($profile) {
            return $profile;
        }

        return ExpenseProfile::create([
            'invoice_id' => $invoice->id,
            'channel' => 'vendor',
            'approval_status' => 'draft',
            'requires_finance_approval' => false,
        ]);
    }
}
