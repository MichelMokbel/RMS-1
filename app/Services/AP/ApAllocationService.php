<?php

namespace App\Services\AP;

use App\Models\ApInvoice;
use App\Models\ApPayment;
use App\Models\ApPaymentAllocation;
use App\Services\Ledger\SubledgerService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApAllocationService
{
    public function __construct(
        protected ApInvoiceStatusService $statusService,
        protected SubledgerService $subledgerService
    )
    {
    }

    public function createPaymentWithAllocations(array $payload, int $userId): ApPayment
    {
        return DB::transaction(function () use ($payload, $userId) {
            $this->validateAllocations($payload['allocations'] ?? [], $payload['supplier_id']);

            $payment = ApPayment::create([
                'supplier_id' => $payload['supplier_id'],
                'payment_date' => $payload['payment_date'],
                'amount' => $payload['amount'],
                'payment_method' => $payload['payment_method'] ?? 'bank_transfer',
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

            return $payment->fresh(['allocations.invoice']);
        });
    }

    public function allocateExistingPayment(ApPayment $payment, array $allocations, int $userId): ApPayment
    {
        return DB::transaction(function () use ($payment, $allocations, $userId) {
            $this->validateAllocations($allocations, $payment->supplier_id, $payment);
            $newAllocations = $this->applyAllocations($payment, $allocations);
            foreach ($newAllocations as $allocation) {
                $this->subledgerService->recordApPaymentAllocation($allocation, $userId);
            }
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
            $outstanding = (float) $invoice->total_amount - (float) $invoice->allocations()->sum('allocated_amount');
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

            $created[] = ApPaymentAllocation::create([
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'allocated_amount' => $allocAmount,
            ]);

            $this->statusService->recalcStatus($invoice);
        }

        if ($sum > (float) $payment->amount) {
            throw ValidationException::withMessages(['allocations' => __('Total allocations exceed payment amount.')]);
        }

        return $created;
    }
}
