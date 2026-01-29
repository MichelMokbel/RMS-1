<?php

namespace App\Services\Purchasing;

use App\Models\PurchaseOrder;
use Illuminate\Validation\ValidationException;

class PurchaseOrderWorkflowService
{
    public function approve(PurchaseOrder $po): PurchaseOrder
    {
        $po->loadMissing('items');

        if (! $po->isPending()) {
            throw ValidationException::withMessages(['status' => __('Only pending purchase orders can be approved.')]);
        }

        if (! $po->supplier_id || $po->items->count() === 0) {
            throw ValidationException::withMessages(['status' => __('Supplier and at least one line are required.')]);
        }

        $po->update(['status' => PurchaseOrder::STATUS_APPROVED]);

        return $po->fresh(['items.item', 'supplier', 'creator']);
    }

    public function cancel(PurchaseOrder $po): PurchaseOrder
    {
        $po->loadMissing('items');

        if ($po->isReceived()) {
            throw ValidationException::withMessages(['status' => __('Cannot cancel a received PO.')]);
        }

        if ($po->isApproved() && $po->items->sum('received_quantity') > 0) {
            throw ValidationException::withMessages(['status' => __('Cannot cancel after receiving items.')]);
        }

        $po->update(['status' => PurchaseOrder::STATUS_CANCELLED]);

        return $po->fresh(['items.item', 'supplier', 'creator']);
    }
}

