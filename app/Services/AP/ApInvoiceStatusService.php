<?php

namespace App\Services\AP;

use App\Models\ApInvoice;

class ApInvoiceStatusService
{
    public function recalcStatus(ApInvoice $invoice): ApInvoice
    {
        if ($invoice->status === 'void' || $invoice->status === 'draft') {
            return $invoice;
        }

        $paid = (float) $invoice->allocations()->sum('allocated_amount');
        $outstanding = round((float) $invoice->total_amount - $paid, 2);

        if ($outstanding <= 0) {
            $invoice->status = 'paid';
        } elseif ($outstanding < (float) $invoice->total_amount) {
            $invoice->status = 'partially_paid';
        } else {
            $invoice->status = 'posted';
        }

        $invoice->save();

        return $invoice->fresh(['allocations']);
    }
}
