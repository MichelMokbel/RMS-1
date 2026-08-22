<?php

namespace App\Services\AP;

use App\Models\ApInvoice;
use App\Models\ApPayment;
use App\Models\ApPaymentAllocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class PaidExpenseCorrectionService
{
    public function __construct(
        protected ApPaymentVoidService $paymentVoidService,
        protected ApInvoiceVoidService $invoiceVoidService,
        protected ApInvoiceAttachmentService $attachmentService,
    ) {}

    public function createEditableVersion(
        ApInvoice $invoice,
        int $userId,
        ?string $reason = null,
    ): ApInvoice {
        $copiedAttachmentPaths = [];

        try {
            return DB::transaction(function () use ($invoice, $userId, $reason, &$copiedAttachmentPaths): ApInvoice {
                $source = $this->reverseExclusiveSettlements($invoice, $userId);
                $revision = $this->invoiceVoidService->voidAndDuplicate($source, $userId, $reason);
                $copiedAttachmentPaths = $revision->attachments->pluck('file_path')->all();

                return $revision;
            });
        } catch (Throwable $exception) {
            // The invoice revision copies S3 objects inside the database transaction.
            // Clean them up if the surrounding settlement-reversal transaction fails.
            $this->attachmentService->cleanupCopiedFiles($copiedAttachmentPaths);

            throw $exception;
        }
    }

    public function voidExpense(
        ApInvoice $invoice,
        int $userId,
        ?string $reason = null,
    ): ApInvoice {
        return DB::transaction(function () use ($invoice, $userId, $reason): ApInvoice {
            $source = $this->reverseExclusiveSettlements($invoice, $userId);

            return $this->invoiceVoidService->void($source, $userId, $reason);
        });
    }

    private function reverseExclusiveSettlements(ApInvoice $invoice, int $userId): ApInvoice
    {
        $source = ApInvoice::query()
            ->with('expenseProfile')
            ->whereKey($invoice->id)
            ->firstOrFail();

        if (! $source->is_expense || ! in_array($source->status, ['paid', 'partially_paid'], true)) {
            throw ValidationException::withMessages([
                'status' => __('Only paid or partially paid expenses can use the closed-expense correction flow.'),
            ]);
        }

        if ($source->document_type === 'landed_cost_adjustment') {
            throw ValidationException::withMessages([
                'document_type' => __('Landed cost adjustments cannot use the expense correction flow.'),
            ]);
        }

        $paymentIds = ApPaymentAllocation::query()
            ->where('invoice_id', $source->id)
            ->whereNull('voided_at')
            ->orderBy('payment_id')
            ->pluck('payment_id')
            ->unique()
            ->values();

        if ($paymentIds->isEmpty()) {
            throw ValidationException::withMessages([
                'payment' => __('This expense has no active payment to reverse.'),
            ]);
        }

        $payments = ApPayment::query()
            ->whereIn('id', $paymentIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $allocations = ApPaymentAllocation::query()
            ->whereIn('payment_id', $paymentIds)
            ->whereNull('voided_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($payments->count() !== $paymentIds->count() || $payments->contains(fn (ApPayment $payment) => $payment->voided_at)) {
            throw ValidationException::withMessages([
                'payment' => __('One of the expense payments is no longer available for correction.'),
            ]);
        }

        if ($allocations->contains(fn (ApPaymentAllocation $allocation) => (int) $allocation->invoice_id !== (int) $source->id)) {
            throw ValidationException::withMessages([
                'payment' => __('A linked payment is shared with another invoice. Void or reallocate that payment before correcting this expense.'),
            ]);
        }

        foreach ($payments as $payment) {
            $activeAllocatedAmount = round((float) $allocations
                ->where('payment_id', $payment->id)
                ->sum('allocated_amount'), 2);
            if ($activeAllocatedAmount !== round((float) $payment->amount, 2)) {
                throw ValidationException::withMessages([
                    'payment' => __('A linked payment contains an unapplied balance. Allocate or resolve that balance before correcting this expense.'),
                ]);
            }
        }

        if ($source->expenseProfile?->settlement_mode === 'petty_cash_wallet'
            && ! $source->expenseProfile->settlement_payment_id
            && $payments->count() > 1) {
            throw ValidationException::withMessages([
                'payment' => __('The legacy petty cash settlement cannot be identified safely. Reverse its payments individually before correcting this expense.'),
            ]);
        }

        foreach ($payments as $payment) {
            $this->paymentVoidService->void($payment, $userId);
        }

        $source = $source->fresh(['allocations', 'expenseProfile']);
        if ($source->status !== 'posted' || $source->allocations->isNotEmpty()) {
            throw ValidationException::withMessages([
                'payment' => __('The expense settlement could not be fully reversed.'),
            ]);
        }

        return $source;
    }
}
