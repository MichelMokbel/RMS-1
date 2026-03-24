<?php

namespace App\Services\Purchasing;

use App\Models\PurchaseOrder;
use App\Services\Accounting\AccountingAuditLogService;
use Illuminate\Validation\ValidationException;

class PurchaseOrderWorkflowService
{
    public function __construct(protected AccountingAuditLogService $auditLog)
    {
    }

    public function approve(PurchaseOrder $po, ?int $actorId = null): PurchaseOrder
    {
        $po->loadMissing('items');

        if (! $po->isPending()) {
            throw ValidationException::withMessages(['status' => __('Only pending purchase orders can be approved.')]);
        }

        if (! $po->supplier_id || $po->items->count() === 0) {
            throw ValidationException::withMessages(['status' => __('Supplier and at least one line are required.')]);
        }

        $po->update([
            'status' => PurchaseOrder::STATUS_APPROVED,
            'workflow_state' => PurchaseOrder::STATUS_APPROVED,
            'approved_at' => now(),
            'approved_by' => $actorId,
        ]);
        $this->auditLog->log('purchase_order.approved', $actorId, $po, [], (int) ($po->company_id ?? 0) ?: null);

        return $po->fresh(['items.item', 'supplier', 'creator']);
    }

    public function cancel(PurchaseOrder $po, ?int $actorId = null): PurchaseOrder
    {
        $po->loadMissing('items');

        if ($po->isReceived()) {
            throw ValidationException::withMessages(['status' => __('Cannot cancel a received PO.')]);
        }

        if ($po->isApproved() && $po->items->sum('received_quantity') > 0) {
            throw ValidationException::withMessages(['status' => __('Cannot cancel after receiving items.')]);
        }

        $po->update([
            'status' => PurchaseOrder::STATUS_CANCELLED,
            'workflow_state' => PurchaseOrder::STATUS_CANCELLED,
            'closed_at' => now(),
            'closed_by' => $actorId,
        ]);
        $this->auditLog->log('purchase_order.cancelled', $actorId, $po, [], (int) ($po->company_id ?? 0) ?: null);

        return $po->fresh(['items.item', 'supplier', 'creator']);
    }
}
