<?php

namespace App\Services\Reports;

use App\Models\PurchaseOrderItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class PurchaseOrderInventoryListReportQueryService
{
    public function query(array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $supplierId = (int) ($filters['supplier_id'] ?? 0);
        $status = (string) ($filters['status'] ?? '');
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;

        return PurchaseOrderItem::query()
            ->join('purchase_orders as po', 'po.id', '=', 'purchase_order_items.purchase_order_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'po.supplier_id')
            ->leftJoin('inventory_items as ii', 'ii.id', '=', 'purchase_order_items.item_id')
            ->when($supplierId > 0, fn (Builder $query) => $query->where('po.supplier_id', $supplierId))
            ->when($status !== '' && $status !== 'all', fn (Builder $query) => $query->where('po.status', $status))
            ->when($status === '' || $status === 'all', fn (Builder $query) => $query->where('po.status', '!=', 'cancelled'))
            ->when($dateFrom, fn (Builder $query) => $query->whereDate('po.order_date', '>=', $dateFrom))
            ->when($dateTo, fn (Builder $query) => $query->whereDate('po.order_date', '<=', $dateTo))
            ->when($search !== '', function (Builder $query) use ($search) {
                $query->where(function (Builder $inner) use ($search) {
                    $inner->where('ii.name', 'like', '%'.$search.'%')
                        ->orWhere('ii.item_code', 'like', '%'.$search.'%')
                        ->orWhere('ii.description', 'like', '%'.$search.'%')
                        ->orWhere('s.name', 'like', '%'.$search.'%');
                });
            })
            ->groupBy(
                'purchase_order_items.item_id',
                'ii.item_code',
                'ii.name',
                'ii.unit_of_measure'
            )
            ->select([
                'purchase_order_items.item_id',
                DB::raw('COALESCE(ii.item_code, "") as item_code'),
                DB::raw('COALESCE(ii.name, purchase_order_items.item_id) as item_name'),
                DB::raw('COALESCE(ii.unit_of_measure, "") as unit_of_measure'),
                DB::raw('SUM(purchase_order_items.quantity) as ordered_quantity'),
            ])
            ->orderBy('item_name');
    }
}
