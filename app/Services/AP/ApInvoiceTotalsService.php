<?php

namespace App\Services\AP;

use App\Models\ApInvoice;

class ApInvoiceTotalsService
{
    public function recalc(ApInvoice $invoice): ApInvoice
    {
        $subtotal = $invoice->items()->get()->sum(function ($line) {
            return round((float) $line->quantity * (float) $line->unit_price, 2);
        });

        $invoice->subtotal = round($subtotal, 2);
        $invoice->total_amount = round($invoice->subtotal + (float) $invoice->tax_amount, 2);
        $invoice->save();

        return $invoice->fresh(['items', 'allocations']);
    }
}
