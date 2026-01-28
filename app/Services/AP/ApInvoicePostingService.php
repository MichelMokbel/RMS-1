<?php

namespace App\Services\AP;

use App\Models\ApInvoice;
use App\Models\PurchaseOrder;
use App\Services\Ledger\SubledgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApInvoicePostingService
{
    public function __construct(
        protected ApInvoiceTotalsService $totalsService,
        protected SubledgerService $subledgerService
    ) {
    }

    public function post(ApInvoice $invoice, int $userId): ApInvoice
    {
        return DB::transaction(function () use ($invoice, $userId) {
            $invoice = ApInvoice::where('id', $invoice->id)->lockForUpdate()->firstOrFail();

            if (! $invoice->isDraft()) {
                throw ValidationException::withMessages(['status' => __('Only draft invoices can be posted.')]);
            }

            if (! $invoice->supplier_id) {
                throw ValidationException::withMessages(['supplier_id' => __('Supplier is required.')]);
            }

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

            $invoice->status = 'posted';
            $invoice->posted_at = $invoice->posted_at ?? now();
            $invoice->posted_by = $invoice->posted_by ?? $userId;
            $invoice->save();

            $this->subledgerService->recordApInvoice($invoice, $userId);

            return $invoice->fresh(['items']);
        });
    }
}
