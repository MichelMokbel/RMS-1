<?php

namespace App\Services\Reports;

use App\Models\PurchaseOrderItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class SupplierPurchasesReportQueryService
{
    public function query(array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $supplierId = (int) ($filters['supplier_id'] ?? 0);
        $itemId = (int) ($filters['item_id'] ?? 0);
        $status = (string) ($filters['status'] ?? '');
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;

        return PurchaseOrderItem::query()
            ->join('purchase_orders as po', 'po.id', '=', 'purchase_order_items.purchase_order_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'po.supplier_id')
            ->leftJoin('inventory_items as ii', 'ii.id', '=', 'purchase_order_items.item_id')
            ->when($supplierId > 0, fn (Builder $query) => $query->where('po.supplier_id', $supplierId))
            ->when($itemId > 0, fn (Builder $query) => $query->where('purchase_order_items.item_id', $itemId))
            ->when($status !== '' && $status !== 'all', fn (Builder $query) => $query->where('po.status', $status))
            ->when($status === '' || $status === 'all', fn (Builder $query) => $query->where('po.status', '!=', 'cancelled'))
            ->when($dateFrom, fn (Builder $query) => $query->whereDate('po.order_date', '>=', $dateFrom))
            ->when($dateTo, fn (Builder $query) => $query->whereDate('po.order_date', '<=', $dateTo))
            ->when($search !== '', function (Builder $query) use ($search) {
                $query->where(function (Builder $inner) use ($search) {
                    $inner->where('s.name', 'like', '%'.$search.'%')
                        ->orWhere('ii.name', 'like', '%'.$search.'%')
                        ->orWhere('ii.item_code', 'like', '%'.$search.'%')
                        ->orWhere('po.po_number', 'like', '%'.$search.'%');
                });
            })
            ->groupBy('po.supplier_id', 'purchase_order_items.item_id', 's.name', 'ii.name', 'ii.item_code')
            ->select([
                'po.supplier_id',
                'purchase_order_items.item_id',
                DB::raw('COALESCE(s.name, "—") as supplier_name'),
                DB::raw('COALESCE(ii.name, purchase_order_items.item_id) as item_name'),
                DB::raw('COALESCE(ii.item_code, "") as item_code'),
                DB::raw('SUM(purchase_order_items.quantity) as ordered_quantity'),
                DB::raw('SUM(purchase_order_items.received_quantity) as received_quantity'),
                DB::raw('SUM(purchase_order_items.total_price) as total_amount'),
                DB::raw('AVG(purchase_order_items.unit_price) as avg_unit_price'),
                DB::raw('MIN(po.order_date) as first_order_date'),
                DB::raw('MAX(po.order_date) as last_order_date'),
                DB::raw('COUNT(DISTINCT po.id) as po_count'),
            ])
            ->orderBy('supplier_name')
            ->orderBy('item_name');
    }
}
