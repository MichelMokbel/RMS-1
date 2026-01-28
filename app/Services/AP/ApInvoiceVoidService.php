<?php

namespace App\Services\AP;

use App\Models\ApInvoice;
use App\Services\Ledger\SubledgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApInvoiceVoidService
{
    public function __construct(protected SubledgerService $subledgerService)
    {
    }

    public function void(ApInvoice $invoice, int $userId): ApInvoice
    {
        return DB::transaction(function () use ($invoice, $userId) {
            $invoice = ApInvoice::where('id', $invoice->id)->lockForUpdate()->firstOrFail();

            if (! in_array($invoice->status, ['draft', 'posted'], true)) {
                throw ValidationException::withMessages(['status' => __('Only draft or posted invoices can be voided.')]);
            }

            if ($invoice->allocations()->count() > 0) {
                throw ValidationException::withMessages(['status' => __('Cannot void an invoice with allocations.')]);
            }

            $wasPosted = $invoice->isPosted();
            $note = trim(($invoice->notes ?? '').' Voided by user '.$userId.' on '.now()->toDateTimeString());
            $invoice->status = 'void';
            $invoice->notes = $note;
            $invoice->voided_at = $invoice->voided_at ?? now();
            $invoice->voided_by = $invoice->voided_by ?? $userId;
            $invoice->save();

            if ($wasPosted) {
                $this->subledgerService->recordApInvoiceVoid($invoice, $userId);
            }

            return $invoice;
        });
    }
}
