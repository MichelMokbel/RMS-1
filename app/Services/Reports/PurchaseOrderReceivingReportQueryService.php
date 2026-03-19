<?php

namespace App\Services\Reports;

use App\Models\PurchaseOrderReceivingLine;
use Illuminate\Database\Eloquent\Builder;

class PurchaseOrderReceivingReportQueryService
{
    public function query(array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $supplierId = (int) ($filters['supplier_id'] ?? 0);
        $purchaseOrderId = (int) ($filters['purchase_order_id'] ?? 0);
        $itemId = (int) ($filters['item_id'] ?? 0);
        $receiverId = (int) ($filters['receiver_id'] ?? 0);
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;

        return PurchaseOrderReceivingLine::query()
            ->with([
                'item.category.parent.parent.parent',
                'receiving.purchaseOrder.supplier',
                'receiving.creator',
                'purchaseOrderItem',
            ])
            ->whereHas('receiving.purchaseOrder', function (Builder $query) use ($supplierId, $purchaseOrderId) {
                $query->when($supplierId > 0, fn (Builder $inner) => $inner->where('supplier_id', $supplierId))
                    ->when($purchaseOrderId > 0, fn (Builder $inner) => $inner->whereKey($purchaseOrderId));
            })
            ->when($itemId > 0, fn (Builder $query) => $query->where('inventory_item_id', $itemId))
            ->when($receiverId > 0, fn (Builder $query) => $query->whereHas('receiving', fn (Builder $inner) => $inner->where('created_by', $receiverId)))
            ->when($dateFrom, fn (Builder $query) => $query->whereHas('receiving', fn (Builder $inner) => $inner->whereDate('received_at', '>=', $dateFrom)))
            ->when($dateTo, fn (Builder $query) => $query->whereHas('receiving', fn (Builder $inner) => $inner->whereDate('received_at', '<=', $dateTo)))
            ->when($search !== '', function (Builder $query) use ($search) {
                $query->where(function (Builder $inner) use ($search) {
                    $inner->whereHas('receiving.purchaseOrder', fn (Builder $poQuery) => $poQuery->where('po_number', 'like', '%'.$search.'%'))
                        ->orWhereHas('receiving.purchaseOrder.supplier', fn (Builder $supplierQuery) => $supplierQuery->where('name', 'like', '%'.$search.'%'))
                        ->orWhereHas('item', function (Builder $itemQuery) use ($search) {
                            $itemQuery->where('name', 'like', '%'.$search.'%')
                                ->orWhere('item_code', 'like', '%'.$search.'%');
                        })
                        ->orWhereHas('receiving.creator', function (Builder $userQuery) use ($search) {
                            $userQuery->where('name', 'like', '%'.$search.'%')
                                ->orWhere('username', 'like', '%'.$search.'%')
                                ->orWhere('email', 'like', '%'.$search.'%');
                        })
                        ->orWhereHas('receiving', fn (Builder $receivingQuery) => $receivingQuery->where('notes', 'like', '%'.$search.'%'));
                });
            })
            ->join('purchase_order_receivings as por', 'por.id', '=', 'purchase_order_receiving_lines.purchase_order_receiving_id')
            ->select('purchase_order_receiving_lines.*')
            ->orderByDesc('por.received_at')
            ->orderByDesc('purchase_order_receiving_lines.id');
    }
}
